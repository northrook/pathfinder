<?php

declare(strict_types=1);

namespace Northrook\Core;

use InvalidArgumentException;
use Northrook\Contracts\Container\Autowire\Logger;
use Northrook\Contracts\Exceptions\FilesystemException;
use Northrook\Contracts\Interfaces\FilesystemInterface;
use Northrook\Contracts\Interfaces\PathfinderInterface;
use Northrook\Core;
use Northrook\Core\Pathfinder\Path;
use Stringable;

use function Northrook\Contracts\is_valid_key;

/**
 * Resolves filesystem paths from configured parameter keys.
 *
 * Lookups use `parameter.key/path/suffix` form — the key is everything before the first `/`,
 * backslashes are preserved (`Vendor\Package\Class::name/src`). Values may reference other
 * parameters via a single `%other.key%` placeholder per value (e.g. `%project_dir%/var/cache`).
 *
 * Resolved paths are cached in memory and optionally persisted to a PHP cache file.
 *
 * Prefer {@see path()} when callers need a {@see Path} result with file operations.
 * Use {@see getPath()} and {@see getUrl()} for string results required by {@see PathfinderInterface}.
 */
final class Pathfinder implements PathfinderInterface, \Countable
{
    use Logger;

    private readonly FilesystemInterface $filesystem;

    /** `false` when caching is disabled; otherwise the path to the PHP cache file. */
    private false|string $cacheFile;

    /** @var ?non-empty-string Hash of the last committed cache payload. */
    private null|string $hash = null;

    /** @var array<non-empty-string, string> Entries restored from the cache file at construction. */
    private array $cached;

    /** @var array<non-empty-string, string> Entries resolved during this request. */
    private array $inMemory = [];

    /** @var array<non-empty-string, true> Parameter keys currently being resolved (cycle detection). */
    private array $resolvingParameters = [];

    /**
     * @todo $parameters will eventually use `Northrook\Core\Parameters->paths`
     *
     * @param array<non-empty-string, string> $parameters  Key-to-path map
     * @param bool|non-empty-string           $cacheFile   `false` disables persistence; `true` picks a default path;
     *                                                       otherwise the path to a PHP cache file
     */
    public function __construct(
        private readonly array $parameters = [],
        bool|string $cacheFile = false,
        null|FilesystemInterface $filesystem = null,
    ) {
        $this->filesystem = $filesystem ?? new Filesystem();
        $this->assignLogger(null, assignNull: true);
        if ($cacheFile === true) {
            $cacheFile =
                $parameters['path.pathfinder_cache'] ?? Core::get()->cacheDirectory . DIR_SEP . 'pathfinder.cache';
        }
        if ($cacheFile === false) {
            $this->cached = [];
        } else {
            [
                $this->hash,
                $this->cached,
            ] = $this->filesystem->pathsExist($cacheFile) ? ( require $cacheFile ) : [null, []];
        }
        $this->cacheFile = $cacheFile;
    }

    /** @see getPath() */
    public function __invoke(
        string|Stringable $path,
        null|string|Stringable $relativeTo = null,
        bool $nullable = false,
    ): null|string {
        return $this->getPath($path, $relativeTo, $nullable);
    }

    /** Persists the cache file when one is configured. */
    public function __destruct()
    {
        $this->commitCache();
    }

    /**
     * Resolves a lookup and returns a URL-safe string via {@see normalize_url()}.
     *
     * @return ($nullable is true ? null|string : string )
     * @throws ($nullable is true ? never : InvalidArgumentException )
     */
    public function getUrl(
        string|Stringable $path,
        null|string|Stringable $relativeTo = null,
        bool $nullable = false,
    ): null|string {
        return (
            normalize_url($this->getPath($path, $relativeTo, $nullable))
            ?: ( $nullable ? null : throw new InvalidArgumentException() )
        );
    }

    /**
     * Resolves a lookup string to an absolute path.
     *
     * `$path` is typically `parameter.key` or `parameter.key/relative/suffix`.
     *
     * When `$relativeTo` is provided, it is resolved first and stripped from the result when the paths share a prefix.
     *
     * Non-existent paths may be returned (globs, not-yet-created targets).
     *
     * Results are written to the in-memory cache when the path exists on disk or when `$relativeTo` is set.
     *
     * @return ($nullable is true ? null|string : string )
     * @throws ($nullable is true ? never : InvalidArgumentException )
     */
    public function getPath(
        string|Stringable $path,
        null|string|Stringable $relativeTo = null,
        bool $nullable = false,
    ): null|string {
        $getPath      = (string) $path;
        $relativePath = $relativeTo ? (string) $relativeTo : null;

        $key = $this->cacheKey($getPath . $relativePath);

        /** @var ?string $resolvedPath */
        $resolvedPath = $this->inMemory[$key] ?? $this->cached[$key] ?? $this->resolvePath($getPath, $relativePath);

        if (! \is_string($resolvedPath)) {
            $this->logger->notice('Unable to resolve path from {key}: {path}.', ['key' => $key, 'path' => $path]);
        } elseif ($this->filesystem->pathsExist($resolvedPath) || $relativePath) {
            return $this->inMemory[$key] = $resolvedPath;
        }

        return $resolvedPath ?: ( $nullable ? null : throw new InvalidArgumentException() );
    }

    /**
     * Resolve a lookup and return a {@see Path} for file operations.
     *
     * @return ($nullable is true ? null|Path : Path)
     * @throws ($nullable is true ? never : InvalidArgumentException )
     */
    public function path(
        string|Stringable $path,
        null|string|Stringable $relativeTo = null,
        bool $nullable = false,
    ): null|Path {
        $resolved = $this->getPath($path, $relativeTo, $nullable);

        return $resolved !== null ? new Path($resolved, $this->filesystem) : null;
    }

    /**
     * Returns the normalized path for a configured parameter key.
     *
     * Values may embed a single `%other.key%` placeholder. Returns `null` when the key is missing,
     * empty, or not path-like; logs a warning in those cases.
     *
     * @throws InvalidArgumentException When `$key` is invalid, a nested placeholder is invalid,
     *                                  or parameter references form a cycle.
     */
    public function getParameter(
        string $key,
    ): null|string {
        if (! $this::validKey($key)) {
            throw new InvalidArgumentException('Invalid parameter key \'' . $key . '\'.');
        }

        $cacheKey = $this->cacheKey($key);

        // Return cached parameter if found
        if ($cached = $this->inMemory[$cacheKey] ?? $this->cached[$cacheKey] ?? null) {
            return $cached;
        }

        if (isset($this->resolvingParameters[$key])) {
            throw new InvalidArgumentException('Circular parameter reference involving \'' . $key . '\'.');
        }

        $this->resolvingParameters[$key] = true;

        try {
            $parameter = $this->parameters[$key] ?? null;

            // Handle value errors
            if (! \is_string($parameter) || empty($parameter)) {
                $value = \is_string($parameter) ? 'empty string' : \gettype($parameter);

                $this->logger->warning('No value for {key}, it is {value}', ['value' => $value, 'key' => $key]);
                return null;
            }

            // Check potential nested parameterKeys
            if (\str_contains($parameter, '%')) {
                $parameter = $this->resolveNestedParameters($parameter);
            }

            $parameter = normalize_path($parameter);

            if (! is_path($parameter)) {
                $this->logger->warning('The value for {key} is not path-like.', ['key' => $key]);
                return null;
            }

            if ($this->filesystem->pathsExist($parameter)) {
                $this->inMemory[$cacheKey] = $parameter;
            }

            return $parameter;
        } finally {
            unset($this->resolvingParameters[$key]);
        }
    }

    /** Whether `$key` is present in the configured parameters map (regardless of value). */
    public function hasParameter(
        string $key,
    ): bool {
        return \array_key_exists($key, $this->parameters) && $this->parameters[$key] !== '';
    }

    /**
     * Writes merged in-memory and file-backed cache entries to disk.
     *
     * The cache file is executable PHP returning `[hash, data]`. No-op when caching is disabled,
     * there is nothing to store, or the hash is unchanged (unless `$force`).
     */
    public function commitCache(
        bool $force = false,
        null|string $cacheFile = null,
    ): bool {
        $cacheFile ??= $this->cacheFile;

        if (! $cacheFile) {
            return false;
        }

        $data = [...$this->inMemory, ...$this->cached];

        if (empty($data)) {
            return false;
        }

        $storageDataHash = \hash('xxh64', \json_encode($data) ?: \serialize($data));

        if (! $force && $storageDataHash === ( $this->hash ?? null )) {
            return false;
        }

        $dateTime           = new \DateTimeImmutable();
        $timestamp          = $dateTime->getTimestamp();
        $formattedTimestamp = $dateTime->format('Y-m-d H:i:s e');

        $header = <<<PHP
            <?php

            /*------------------------------------------------------%{$timestamp}%-

               Name      : Pathfinder Cache
               Generated : {$formattedTimestamp}

               Do not edit it manually.

            -#{$storageDataHash}#------------------------------------------------*/

            PHP;

        $payload = $header . 'return ' . \var_export([$storageDataHash, $data], true) . ';' . NEWLINE;
        $payload = \str_replace(TAB, '    ', $payload);

        try {
            $this->filesystem->createParentDirectory($cacheFile);
            $this->filesystem->writeFileAtomically($cacheFile, $payload);
            $this->hash = $storageDataHash;

            return true;
        } catch (FilesystemException) {
            return false;
        }
    }

    /** Number of entries in the in-memory and file-backed caches combined. */
    public function count(): int
    {
        return \count([...$this->inMemory, ...$this->cached]);
    }

    /** Clears entries loaded from the cache file; in-memory entries are kept. */
    public function clearCache(): self
    {
        $this->cached = [];
        return $this;
    }

    /**
     * Drops file-backed cache entries whose paths no longer exist or are not readable.
     *
     * Does not inspect the in-memory cache.
     */
    public function pruneCache(): self
    {
        foreach ($this->cached as $key => $value) {
            if ($this->filesystem->pathsExist($value) && $this->filesystem->isReadable($value)) {
                continue;
            }

            unset($this->cached[$key]);
        }

        return $this;
    }

    /**
     * Substitutes a single `%key%` placeholder when present.
     *
     * Values with no `%`, or exactly one `%` (e.g. `100%`), are returned unchanged.
     * More than one `%key%` pair throws {@see InvalidArgumentException}.
     */
    private function resolveNestedParameters(
        string $parameter,
    ): string {
        [
            $error,
            $referencedKey,
        ] =
            self::parseSinglePlaceholder($parameter);

        if ($error !== null) {
            throw new InvalidArgumentException($error);
        }

        if ($referencedKey === null) {
            return $parameter;
        }

        $resolve = $this->getParameter($referencedKey);

        if ($resolve === null) {
            $this->logger->warning('Unable to resolve parameter {key} in {parameter}.', [
                'key'       => $referencedKey,
                'parameter' => $parameter,
            ]);

            return $parameter;
        }

        return self::substitutePlaceholder($parameter, $resolve);
    }

    /**
     * Resolves `$path` and optionally re-bases it against `$relativeTo`.
     *
     * When `$relativeTo` is set but does not prefix the resolved path, a critical log entry is emitted
     * and the full path is returned unchanged.
     */
    private function resolvePath(
        string $path,
        null|string $relativeTo = null,
    ): null|string {
        // Resolve potential relative paths first
        if ($relativeTo) {
            $relativeTo = $this->resolveParameter($relativeTo);
        }

        // Resolve the requested path
        $path = $this->resolveParameter($path);

        // Bail early if no path is found
        if (! $path) {
            return null;
        }

        // If relative, and the relative path exists
        if ($relativeTo) {
            // Check that they match
            if (\str_starts_with($path, $relativeTo)) {
                // Subtract the relative path
                $path = \substr($path, \strlen($relativeTo));
            }
            // Handle mismatched relative paths
            else {
                $this->logger->critical('Relative path {relativeTo} to {path} is not valid.', \get_defined_vars());
            }
        }

        return $path;
    }

    /**
     * Resolves a lookup fragment — either a literal path or `parameter.key` + suffix.
     *
     * Glob patterns (`/*`) short-circuit existence checks. Existing paths are cached in memory.
     */
    private function resolveParameter(
        string $string,
    ): null|string {
        $cacheKey = $this->cacheKey($string);

        // Return cached parameter if found
        if ($cached = $this->inMemory[$cacheKey] ?? $this->cached[$cacheKey] ?? null) {
            return $cached;
        }

        // Check for $parameterKey
        [$parameterKey, $path] = $this->resolveProvidedString($string);

        if ($parameterKey) {
            $parameter = $this->getParameter($parameterKey);

            // Bail early on empty parameters
            if (! $parameter) {
                $this->logger->error(
                    'Pathfinder: {parameterKey}:{path}, could not resolve parameter.',
                    \get_defined_vars(),
                );
                return null;
            }

            $path = normalize_path([$parameter, $path]);
        }

        // Return early if the path contains at least one glob wildcard
        if (str_includes_any($path, '/*')) {
            return $path;
        }

        if ($exists = $this->filesystem->pathsExist($path)) {
            $this->inMemory[$cacheKey] = $path;
        }

        if ($parameterKey && ! $exists) {
            $this->logger->notice('Pathfinder: {parameterKey}:{path}, the path does not exist.', \get_defined_vars());
        }

        return $path;
    }

    /**
     * Validates parameter and lookup keys via {@see is_valid_key()}.
     *
     * `.` is an optional segment separator (`path.root`). `\`, `/`, and `%` are literal
     * (`Vendor\Package`, `Class::name`, path suffixes). Path *values* are not subject to this rule.
     *
     * @phpstan-assert-if-true  non-empty-string $key
     */
    public static function validKey(
        string $key,
    ): bool {
        return is_valid_key(
            $key,
            separator: '.',
            charset: \CHARSET_ALNUM . '_\\/-:%',
        );
    }

    /**
     * Validates a compile-time parameter map.
     *
     * Checks keys, placeholder syntax, reference targets, circular dependencies, and path-like values
     * after fully resolving `%key%` placeholders.
     *
     * @param array<non-empty-string, string> $parameters
     *
     * @return list<string> Validation errors; empty when the map is valid.
     */
    public static function validateParameters(
        array $parameters,
    ): array {
        $errors = [];

        foreach ($parameters as $key => $value) {
            if (! \is_string($key) || $key === '') {
                $errors[] = 'Parameter keys must be non-empty strings.';
                continue;
            }

            if (! self::validKey($key)) {
                $errors[] = 'Invalid parameter key \'' . $key . '\'.';
            }

            if (! \is_string($value) || $value === '') {
                $errors[] = 'Parameter \'' . $key . '\' has an empty value.';
                continue;
            }

            [$placeholderError, $referencedKey] = self::parseSinglePlaceholder($value);

            if ($placeholderError !== null) {
                $errors[] = 'Parameter \'' . $key . '\': ' . $placeholderError;
                continue;
            }

            if ($referencedKey !== null && ! \array_key_exists($referencedKey, $parameters)) {
                $errors[] = 'Parameter \'' . $key . '\' references unknown key \'' . $referencedKey . '\'.';
            }
        }

        $reportedCycles = [];

        foreach (\array_keys($parameters) as $key) {
            if (! \is_string($key) || ! self::validKey($key)) {
                continue;
            }

            $visiting = [];
            $cycleKey = self::findCircularParameterReference($key, $parameters, $visiting);

            if ($cycleKey !== null && ! isset($reportedCycles[$cycleKey])) {
                $errors[]                  = 'Circular parameter reference involving \'' . $cycleKey . '\'.';
                $reportedCycles[$cycleKey] = true;
            }
        }

        foreach ($parameters as $key => $value) {
            if (! \is_string($key) || ! self::validKey($key) || ! \is_string($value) || $value === '') {
                continue;
            }

            $resolving = [];
            $resolved  = self::resolveCompiledParameterValue($key, $parameters, $resolving);

            if ($resolved === null) {
                continue;
            }

            if (! is_path(normalize_path($resolved))) {
                $errors[] = 'The value for \'' . $key . '\' is not path-like.';
            }
        }

        return $errors;
    }

    /**
     * @return array{0: null|string, 1: null|non-empty-string} Syntax error and referenced parameter key, if any.
     */
    private static function parseSinglePlaceholder(
        string $value,
    ): array {
        if (! \str_contains($value, '%')) {
            return [null, null];
        }

        $delimiterCount = \substr_count($value, '%');

        if ($delimiterCount === 1) {
            return [null, null];
        }

        if ($delimiterCount !== 2) {
            return ['Parameter value must contain at most one %key% placeholder.', null];
        }

        $open  = \strpos($value, '%');
        $close = \strrpos($value, '%');

        if ($open === false || $close === false || $open >= $close) {
            return ['Unbalanced % delimiters in parameter value.', null];
        }

        $referencedKey = \substr($value, $open + 1, $close - $open - 1);

        if (! self::validKey($referencedKey)) {
            return ['Invalid parameter key \'' . $referencedKey . '\' in nested placeholder.', null];
        }

        return [null, $referencedKey];
    }

    private static function substitutePlaceholder(
        string $value,
        string $resolved,
    ): string {
        $open  = \strpos($value, '%');
        $close = \strrpos($value, '%');

        if ($open === false || $close === false || $open >= $close) {
            return $value;
        }

        return \substr($value, 0, $open) . $resolved . \substr($value, $close + 1);
    }

    /**
     * @param array<non-empty-string, string> $parameters
     * @param array<string, true>             $visiting
     *
     * @param-out array<string, true> $visiting
     */
    private static function findCircularParameterReference(
        string $key,
        array $parameters,
        array &$visiting,
    ): null|string {
        if (isset($visiting[$key])) {
            return $key;
        }

        $value = $parameters[$key] ?? null;

        if (! \is_string($value)) {
            return null;
        }

        [
            $error,
            $referencedKey,
        ] =
            self::parseSinglePlaceholder($value);

        if ($error !== null || $referencedKey === null || ! \array_key_exists($referencedKey, $parameters)) {
            return null;
        }

        $visiting[$key] = true;

        try {
            return self::findCircularParameterReference($referencedKey, $parameters, $visiting);
        } finally {
            unset($visiting[$key]);
        }
    }

    /**
     * @param array<non-empty-string, string> $parameters
     * @param array<string, true>             $resolving
     *
     * @param-out array<string, true> $resolving
     */
    private static function resolveCompiledParameterValue(
        string $key,
        array $parameters,
        array &$resolving,
    ): null|string {
        if (isset($resolving[$key])) {
            return null;
        }

        $value = $parameters[$key] ?? null;

        if (! \is_string($value) || $value === '') {
            return null;
        }

        [
            $error,
            $referencedKey,
        ] =
            self::parseSinglePlaceholder($value);

        if ($error !== null) {
            return null;
        }

        if ($referencedKey === null) {
            return $value;
        }

        if (! \array_key_exists($referencedKey, $parameters)) {
            return null;
        }

        $resolving[$key] = true;

        try {
            $resolved = self::resolveCompiledParameterValue($referencedKey, $parameters, $resolving);

            if ($resolved === null) {
                return null;
            }

            return self::substitutePlaceholder($value, $resolved);
        } finally {
            unset($resolving[$key]);
        }
    }

    /**
     * Cache index for a lookup string; falls back to `xxh3` when {@see validKey()} rejects the input.
     *
     * @return non-empty-string
     */
    private function cacheKey(
        null|string|bool|int|Stringable ...$from,
    ): string {
        $string = \implode('', $from);

        if ($this::validKey($string)) {
            return $string;
        }

        return \hash('xxh3', $string);
    }

    /**
     * Splits a lookup into `[parameterKey, pathSuffix]`.
     *
     * The key is the substring before the first `/`; when absent, the whole string is the key.
     * Backslashes are preserved. Returns `[false, $string]` when the key is invalid.
     *
     * @return array{0: false|string, 1: string}
     */
    private function resolveProvidedString(
        string $string,
    ): array {
        $parameterKey = \strstr($string, '/', true);

        if ($parameterKey === false) {
            $parameterKey = $string;
            $pathSuffix   = '';
        } else {
            $pathSuffix = \substr($string, \strlen($parameterKey));
        }

        if ($this::validKey($parameterKey)) {
            return [$parameterKey, $pathSuffix];
        }

        return [false, $string];
    }
}
