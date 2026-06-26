<?php

declare(strict_types=1);

namespace Northrook\Core\Pathfinder;

use InvalidArgumentException;
use Northrook\Contracts\Exceptions\FilesystemException;
use Northrook\Contracts\Interfaces\FilesystemInterface;
use Northrook\Core\Filesystem;
use RuntimeException;
use SplFileInfo;
use Stringable;
use ValueError;

use function Northrook\Core\is_path;
use function Northrook\Core\is_url;
use function Northrook\Core\normalize_path;
use function Northrook\Core\normalize_slashes;

/**
 * Mutable path or URL wrapper produced by {@see \Northrook\Core\Pathfinder::path()}.
 *
 * Local paths support filesystem operations via the injected {@see FilesystemInterface}.
 * URLs support segment and query-string appending; use Core HTTP helpers for remote I/O.
 */
final class Path implements Stringable
{
    private readonly FilesystemInterface $filesystem;
    private SplFileInfo $fileInfo;

    /** @var array<int|string,array<array-key,mixed>|string> */
    private array $queryParameters = [];

    /**
     * @param Stringable|string $path        A local path or URL string.
     * @param null|FilesystemInterface $filesystem  Defaults to a new {@see Filesystem} instance.
     */
    public function __construct(
        Stringable|string $path,
        null|FilesystemInterface $filesystem = null,
    ) {
        $this->filesystem = $filesystem ?? new Filesystem();
        $this->setFileInfo($path);
    }

    /**
     * Appends a path segment or URL query string.
     *
     * - `?foo=bar` merges query parameters (URLs only; later keys override).
     * - Other strings are concatenated to the current pathname.
     * - Appending to an existing file path throws {@see ValueError}.
     *
     * @throws InvalidArgumentException When query parameters are appended to a local path.
     * @throws ValueError               When appending a path segment to an existing file.
     */
    final public function append(
        string|Stringable $string,
    ): self {
        $path = (string) $string;

        if (\str_starts_with($path, '?')) {
            $this->appendQueryString(\substr($path, 1));
            return $this;
        }

        if ($this->isUrl()) {
            $this->setFileInfo($this->fileInfo->getPathname() . $path);
            return $this;
        }

        if (! $this->filesystem->isFile($this->fileInfo->getPathname())) {
            $this->setFileInfo($this->fileInfo->getPathname() . $path);
        } else {
            throw new ValueError(
                __METHOD__
                . "\nThe path '{$this->fileInfo->getPathname()}' is a file path, and should not be appended by another file path '{$path}'.",
            );
        }

        return $this;
    }

    /**
     * Atomically writes `$content` to the path.
     *
     * @param resource|string $content
     */
    final public function save(
        mixed $content,
        bool $makeRequiredDirectories = true,
    ): bool {
        $this->assertLocalPath(__METHOD__);

        if ($makeRequiredDirectories) {
            $this->mkdir();
        }

        try {
            $this->filesystem->writeFileAtomically($this->fileInfo->getPathname(), $content);

            return true;
        } catch (FilesystemException) {
            return false;
        }
    }

    /**
     * Creates the parent directory of this path when it does not exist.
     *
     * @throws InvalidArgumentException When this path is a URL.
     */
    final public function mkdir(
        int $permissions = 0777,
        bool $recursive = true,
    ): bool {
        $this->assertLocalPath(__METHOD__);

        $dir = \dirname($this->fileInfo->getPathname());

        if ($this->filesystem->pathsExist($dir)) {
            return true;
        }

        try {
            $this->filesystem->createDirectory($dir, $permissions);

            return true;
        } catch (FilesystemException) {
            return false;
        }
    }

    /**
     * Copies this path to `$target`.
     *
     * @throws InvalidArgumentException When this path is a URL.
     */
    final public function copy(
        string $target,
        bool $alwaysOverwrite = false,
    ): bool {
        $this->assertLocalPath(__METHOD__);

        try {
            $this->filesystem->copyFile($this->fileInfo->getPathname(), $target, $alwaysOverwrite);

            return true;
        } catch (FilesystemException) {
            return false;
        }
    }

    /**
     * Removes the file or directory at this path.
     *
     * @throws InvalidArgumentException When this path is a URL.
     */
    final public function remove(): void
    {
        $this->assertLocalPath(__METHOD__);

        $this->filesystem->remove($this->fileInfo->getPathname());
    }

    /**
     * @throws InvalidArgumentException When this path is a URL.
     * @throws RuntimeException           When `$throwOnError` is true and the path does not exist.
     */
    final public function exists(bool $throwOnError = false): bool
    {
        $this->assertLocalPath(__METHOD__);

        $exists = $this->filesystem->pathsExist($this->fileInfo->getPathname());

        if ($exists === false && $throwOnError) {
            throw new RuntimeException('Unable to read file: ' . $this->fileInfo->getPathname());
        }

        return $exists;
    }

    /** Whether the pathname is path-like per {@see is_path()}. */
    final public function isPath(): bool
    {
        return is_path($this->fileInfo->getPathname());
    }

    /** @throws InvalidArgumentException When this path is a URL. */
    final public function isFile(): bool
    {
        $this->assertLocalPath(__METHOD__);

        return $this->filesystem->isFile($this->fileInfo->getPathname());
    }

    /** @throws InvalidArgumentException When this path is a URL. */
    final public function isDirectory(): bool
    {
        $this->assertLocalPath(__METHOD__);

        return $this->filesystem->isDirectory($this->fileInfo->getPathname());
    }

    /** Whether the basename starts with `.` (hidden path segment; existence not required). */
    final public function isDotPath(): bool
    {
        return \str_starts_with($this->fileInfo->getBasename(), '.');
    }

    /** @throws InvalidArgumentException When this path is a URL. */
    final public function isDotFile(): bool
    {
        return $this->isDotPath() && $this->isFile();
    }

    /**
     * Whether this path is an existing directory inside a hidden segment (e.g. `.git`, `foo/.hidden`).
     *
     * @throws InvalidArgumentException When this path is a URL.
     */
    final public function isDotDirectory(): bool
    {
        $this->assertLocalPath(__METHOD__);

        return $this->isDirectory()
            && \str_contains($this->fileInfo->getPathname(), DIR_SEP . '.');
    }

    /** Whether the pathname is a URL, optionally restricted to `$protocol`. */
    final public function isUrl(null|string $protocol = null): bool
    {
        return is_url($this->fileInfo->getPathname(), $protocol);
    }

    /** Whether the pathname is relative (does not begin with {@see DIR_SEP}). */
    final public function isRelative(): bool
    {
        return ! \str_starts_with($this->getPathname(), DIR_SEP);
    }

    /** @throws InvalidArgumentException When this path is a URL. */
    final public function isReadable(): bool
    {
        $this->assertLocalPath(__METHOD__);

        return $this->filesystem->isReadable($this->fileInfo->getPathname());
    }

    /** The underlying {@see SplFileInfo} instance. */
    final public function getSplFileInfo(): SplFileInfo
    {
        return $this->fileInfo;
    }

    /** File extension per {@see SplFileInfo::getExtension()}. */
    final public function getExtension(): string
    {
        return $this->fileInfo->getExtension();
    }

    /**
     * Resolves symbolic links and returns a normalized absolute path.
     *
     * @return ($falseOnError is true ? false|string : string)
     * @throws InvalidArgumentException When this path is a URL.
     */
    final public function getRealPath(bool $falseOnError = false): false|string
    {
        $this->assertLocalPath(__METHOD__);

        $path = $this->filesystem->resolvePath($this->fileInfo->getPathname());

        if ($falseOnError) {
            return $path ?? false;
        }

        return normalize_slashes($path ?: $this->fileInfo->getPathname());
    }

    /** Full pathname, including URL query string when present. */
    public function getPathname(): string
    {
        return normalize_slashes($this->fileInfo->getPathname());
    }

    /** Directory portion of the pathname per {@see SplFileInfo::getPath()}. */
    public function getPath(): string
    {
        return normalize_slashes($this->fileInfo->getPath());
    }

    /** Basename without the extension. */
    final public function getFilename(): string
    {
        return normalize_slashes(\strrchr($this->fileInfo->getFilename(), '.', true) ?: $this->fileInfo->getFilename());
    }

    /**
     * @throws InvalidArgumentException When this path is a URL.
     * @throws RuntimeException           When `$throwOnError` is true and reading fails.
     */
    final public function getContents(bool $throwOnError = false): null|string
    {
        $this->assertLocalPath(__METHOD__);

        try {
            return $this->filesystem->readFile($this->fileInfo->getPathname());
        } catch (FilesystemException $exception) {
            if ($throwOnError) {
                throw new RuntimeException('Unable to read file: ' . $this->getPathname(), previous: $exception);
            }

            return null;
        }
    }

    /**
     * Runs `glob()` patterns relative to this path's directory.
     *
     * @param string|string[] $pattern
     *
     * @return self[]
     * @throws InvalidArgumentException When this path is a URL.
     */
    final public function glob(
        string|array $pattern,
        null|int $flags = null,
    ): array {
        $this->assertLocalPath(__METHOD__);

        $flags ??= GLOB_NOSORT | GLOB_BRACE;
        $path  = \rtrim($this->fileInfo->getPathname(), '\\/');
        $glob  = [];

        foreach ((array) $pattern as $match) {
            $match = \DIR_SEP . \ltrim($match, '\\/');
            $glob  = [...$glob, ...( \glob($path . $match, $flags) ?: [] )];
        }

        return \array_map(fn(string $match): self => new self($match, $this->filesystem), $glob);
    }

    /** {@see getPathname()} for URLs; {@see getRealPath()} for local paths. */
    public function __toString(): string
    {
        return $this->isUrl() ? $this->getPathname() : $this->getRealPath();
    }

    /** Named constructor equivalent to `new self(…)`. */
    final public static function from(
        string|Stringable $filename,
        null|FilesystemInterface $filesystem = null,
    ): self {
        return new self($filename, $filesystem);
    }

    /** @throws InvalidArgumentException When a filesystem operation is attempted on a URL. */
    private function assertLocalPath(string $method): void
    {
        if ($this->isUrl()) {
            throw new InvalidArgumentException(
                "{$method} does not support remote URLs; use Core HTTP helpers instead.",
            );
        }
    }

    /** Merges `$query` into the URL and rebuilds the query string. */
    private function appendQueryString(string $query): void
    {
        if (! $this->isUrl()) {
            throw new InvalidArgumentException('Query parameters can only be appended to URLs.');
        }

        $pathname = $this->fileInfo->getPathname();
        $base     = $pathname;

        if (\str_contains($pathname, '?')) {
            $base        = (string) \strstr($pathname, '?', true);
            $queryOffset = \strpos($pathname, '?') + 1;
            $existing    = [];
            \parse_str(\substr($pathname, $queryOffset), $existing);
            $this->queryParameters = [...$this->queryParameters, ...$existing];
        }

        if ($query !== '') {
            $parsed = [];
            \parse_str($query, $parsed);
            $this->queryParameters = [...$this->queryParameters, ...$parsed];
        }

        $built = \http_build_query($this->queryParameters, '', '&', \PHP_QUERY_RFC3986);

        $this->fileInfo = new SplFileInfo($built !== '' ? "{$base}?{$built}" : $base);
    }

    /** Normalizes local paths; parses query parameters from URLs. */
    private function setFileInfo(Stringable|string $path): void
    {
        $string                = (string) $path;
        $this->queryParameters = [];

        if (! \str_contains($string, '://')) {
            $this->fileInfo = new SplFileInfo(normalize_path($string));
            return;
        }

        if (\str_contains($string, '?')) {
            [$string, $query] = \explode('?', $string, 2);

            if ($query !== '') {
                \parse_str($query, $this->queryParameters);
            }
        }

        if ($this->queryParameters !== []) {
            $built  = \http_build_query($this->queryParameters, '', '&', \PHP_QUERY_RFC3986);
            $string = "{$string}?{$built}";
        }

        $this->fileInfo = new SplFileInfo($string);
    }
}
