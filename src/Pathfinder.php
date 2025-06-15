<?php

declare(strict_types=1);

namespace Core;

use Core\Contracts\Autowire\Logger;
use Core\Contracts\PathfinderInterface;
use InvalidArgumentException;
use Stringable, Countable;
use function Support\{
    get_system_cache_directory,
    path_valid,
    datetime,
    file_save,
    is_path,
    str_includes_any,
    normalize_path,
    normalize_url,
    slug,
};

final class Pathfinder implements PathfinderInterface, Countable
{
    use Logger;

    private false|string $cacheFile;

    /**
     * The current stored hash
     *
     * @var ?non-empty-string
     */
    private ?string $hash = null;

    /** @var array<non-empty-string, string> */
    private array $cached;

    /** @var array<non-empty-string, string> */
    protected array $inMemory = [];

    /**
     * @param array<non-empty-string, string> $parameters [placeholder]
     * @param bool|non-empty-string           $cacheFile
     */
    public function __construct(
        private readonly array $parameters = [],
        bool|string            $cacheFile = false,
    ) {
        if ( $cacheFile === true ) {
            $cacheFile = $parameters['path.pathfinder_cache']
                         ?? get_system_cache_directory().DIR_SEP.'pathfinder.cache';
        }
        if ( $cacheFile === false ) {
            $this->cached = [];
        }
        else {
            [
                $this->hash,
                $this->cached,
            ] = \file_exists( $cacheFile )
                    ? require $cacheFile
                    : [null, []];
        }
        $this->cacheFile = $cacheFile;
    }

    /**
     * @param string|Stringable      $path
     * @param null|string|Stringable $relativeTo
     * @param bool                   $nullable
     *
     * @return ($nullable is true ? null|string : string )
     * @throws ($nullable is true ? never : InvalidArgumentException )
     */
    public function __invoke(
        string|Stringable      $path,
        null|string|Stringable $relativeTo = null,
        bool                   $nullable = false,
    ) : ?string {
        return $this->getPath( $path, $relativeTo, $nullable );
    }

    public function __destruct()
    {
        $this->commitCache();
    }

    /**
     * A `normalizeUrl` filtered string.
     *
     * @param string|Stringable      $path
     * @param null|string|Stringable $relativeTo
     * @param bool                   $nullable
     *
     * @return ($nullable is true ? null|string : string )
     * @throws ($nullable is true ? never : InvalidArgumentException )
     */
    public function getUrl(
        string|Stringable      $path,
        null|string|Stringable $relativeTo = null,
        bool                   $nullable = false,
    ) : ?string {
        // $path = $this->getPath( $path, $relativeTo );

        return normalize_url( $this->getPath( $path, $relativeTo ) )
                ?: ( $nullable ? null : throw new InvalidArgumentException() );
    }

    /**
     * @param string|Stringable      $path
     * @param null|string|Stringable $relativeTo
     * @param bool                   $nullable
     *
     * @return ($nullable is true ? null|string : string )
     * @throws ($nullable is true ? never : InvalidArgumentException )
     */
    public function getPath(
        string|Stringable      $path,
        null|string|Stringable $relativeTo = null,
        bool                   $nullable = false,
    ) : ?string {
        $getPath      = (string) $path;
        $relativePath = $relativeTo ? (string) $relativeTo : null;

        $key = $this->cacheKey( $getPath.$relativePath );

        /** @var ?string $resolvedPath */
        $resolvedPath = $this->inMemory[$key]
                           ?? $this->cached[$key]
                           ?? $this->resolvePath( $getPath, $relativePath );

        if ( ! \is_string( $resolvedPath ) ) {
            $this->logger->notice(
                'Unable to resolve path from {key}: {path}.',
                ['key' => $key, 'path' => $path],
            );
        }
        elseif ( \file_exists( $resolvedPath ) || $relativePath ) {
            return $this->inMemory[$key] = $resolvedPath;
        }

        return $resolvedPath
                ?: (
                    $nullable
                        ? null
                        : throw new InvalidArgumentException()
                );
    }

    /**
     * Return a `parameter` value by `key`.
     *
     * Will look in:
     * 1. {@see Pathfinder::$parameters}
     * 2. {@see Pathfinder::$parameterBag}
     *
     * Returns `null` on failure.
     *
     * @param string $key
     *
     * @return null|string
     */
    public function getParameter( string $key ) : ?string
    {
        \assert(
            $this::validKey( $key ),
            'Invalid parameter \''.$key.'\'. Must contain one period, cannot start or end with period.',
        );

        $cacheKey = $this->cacheKey( $key );

        // Return cached parameter if found
        if ( $cached = ( $this->inMemory[$key] ?? $this->cached[$key] ?? null ) ) {
            return $cached;
        }

        $parameter = $this->parameters[$key] ?? null;

        // Handle value errors
        if ( ! \is_string( $parameter ) || empty( $parameter ) ) {
            $value = \is_string( $parameter )
                    ? 'empty string'
                    : \gettype( $parameter );

            $this->logger->warning(
                'No value for {key}, it is {value}',
                ['value' => $value, 'key' => $key],
            );
            return null;
        }

        // Check potential nested parameterKeys
        if ( \str_contains( $parameter, '%' ) ) {
            $parameter = $this->resolveNestedParameters( $parameter );
        }

        $parameter = normalize_path( $parameter );

        if ( ! is_path( $parameter ) ) {
            $this->logger->warning(
                'The value for {key} is not path-like.',
                ['key' => $key],
            );
            return null;
        }

        if ( \file_exists( $parameter ) ) {
            $this->inMemory[$cacheKey] = $parameter;
        }

        return $parameter;
    }

    /**
     * Check if a key exists in {@see self::$parameters}.
     *
     * @param string $key
     *
     * @return bool
     */
    public function hasParameter( string $key ) : bool
    {
        return \array_key_exists( $key, $this->parameters );
    }

    public function commitCache( bool $force = false, ?string $cacheFile = null ) : bool
    {
        $cacheFile ??= $this->cacheFile;

        if ( ! $cacheFile ) {
            return false;
        }

        $data = [...$this->inMemory, ...$this->cached];

        if ( empty( $data ) ) {
            return false;
        }

        $storageDataHash = \hash( 'xxh64', \json_encode( $data ) ?: \serialize( $data ) );

        if ( $storageDataHash === ( $this->hash ?? null ) ) {
            return false;
        }

        $dateTime           = datetime();
        $timestamp          = $dateTime->getTimestamp();
        $formattedTimestamp = $dateTime->format( 'Y-m-d H:i:s e' );

        $localStorage['head'] = <<<PHP
            <?php
            
            /*------------------------------------------------------%{$timestamp}%-
            
               Name      : Pathfinder Cache
               Generated : {$formattedTimestamp}
            
               Do not edit it manually.
            
            -#{$storageDataHash}#------------------------------------------------*/
            PHP;

        $localStorage['return'] = 'return [';
        $localStorage['hash']   = TAB."'{$storageDataHash}',";
        $localStorage[]         = TAB.'[';

        $longestKey = \max( \array_map( 'strlen', \array_keys( $data ) ) ) + 2;

        foreach ( $data as $key => $value ) {
            $key            = \str_pad( "'{$key}'", $longestKey );
            $localStorage[] = TAB.TAB."{$key} => '{$value}',";
        }
        $localStorage[]        = TAB.'],';
        $localStorage['close'] = '];'.NEWLINE;

        $php = \implode( NEWLINE, $localStorage );
        $php = \str_replace( TAB, '    ', $php );

        return (bool) file_save( $cacheFile, $php );
    }

    public function count() : int
    {
        return \count( $this->cached );
    }

    public function clearCache() : self
    {
        $this->cached = [];
        return $this;
    }

    public function pruneCache() : self
    {
        foreach ( $this->cached as $key => $value ) {
            if ( path_valid( $value ) ) {
                continue;
            }

            unset( $this->cached[$key] );
        }

        return $this;
    }

    protected function resolveNestedParameters( string $parameter ) : string
    {
        if ( \substr_count( $parameter, '%' ) <= 1 ) {
            return $parameter;
        }

        return (string) \preg_replace_callback(
            '/%([a-zA-Z0-9._-]+)%/',
            function( $match ) use ( $parameter ) : string {
                $resolve = $this->getParameter( $match[1] );

                if ( ! $resolve ) {
                    $this->logger->warning(
                        'Unable to resolve parameter {key} in {parameter}.',
                        ['key' => $match[1], 'parameter' => $parameter, 'match' => $match],
                    );
                }
                return $resolve ?? $match[0];
            },
            $parameter,
        );
    }

    protected function resolvePath( string $path, ?string $relativeTo = null ) : ?string
    {
        // Resolve potential relative paths first
        if ( $relativeTo ) {
            $relativeTo = $this->resolveParameter( $relativeTo );
        }

        // Resolve the requested path
        $path = $this->resolveParameter( $path );

        // Bail early if no path is found
        if ( ! $path ) {
            return null;
        }

        // If relative, and the relative path exists
        if ( $relativeTo ) {
            // Check that they match
            if ( \str_starts_with( $path, $relativeTo ) ) {
                // Subtract the relative path
                $path = \substr( $path, \strlen( $relativeTo ) );
            }
            // Handle mismatched relative paths
            else {
                $this->logger->critical(
                    'Relative path {relativeTo} to {path} is not valid.',
                    \get_defined_vars(),
                );
            }
        }

        return $path;
    }

    protected function resolveParameter( string $string ) : ?string
    {
        $cacheKey = $this->cacheKey( $string );

        // Return cached parameter if found
        if ( $cached = ( $this->inMemory[$cacheKey] ?? $this->cached[$cacheKey] ?? null ) ) {
            return $cached;
        }

        // Check for $parameterKey
        [$parameterKey, $path] = $this->resolveProvidedString( $string );

        if ( $parameterKey ) {
            $parameter = $this->getParameter( $parameterKey );

            // Bail early on empty parameters
            if ( ! $parameter ) {
                $this->logger->error(
                    'Pathfinder: {parameterKey}:{path}, could not resolve parameter.',
                    \get_defined_vars(),
                );
                return null;
            }

            $path = normalize_path( [$parameter, $path] );
        }

        // Return early if the path contains at least one glob wildcard
        if ( str_includes_any( $path, '/*' ) ) {
            return $path;
        }

        if ( $exists = \file_exists( $path ) ) {
            $this->inMemory[$cacheKey] = $path;
        }

        if ( $parameterKey && ! $exists ) {
            $this->logger->notice(
                'Pathfinder: {parameterKey}:{path}, the path does not exist.',
                \get_defined_vars(),
            );
        }

        return $path;
    }

    /**
     * @param string $key
     *
     * @phpstan-assert-if-true  non-empty-string $key
     *
     * @return bool
     */
    public static function validKey( string $key ) : bool
    {
        // Keys must have at least one delimiter
        if ( ! \str_contains( $key, '.' ) ) {
            return false;
        }

        /** Only `[a-zA-Z.-_/]` allowed */
        if ( ! \ctype_alpha( \str_replace( ['.', '-', '_', '/'], '', $key ) ) ) {
            return false;
        }

        // Keys cannot start or end with the delimiter
        return ! ( \str_starts_with( $key, '.' ) || \str_ends_with( $key, '.' ) );
    }

    /**
     * @param null|bool|int|string|Stringable ...$from
     *
     * @return non-empty-string
     */
    private function cacheKey( null|string|bool|int|Stringable ...$from ) : string
    {
        $string = \strtr( \implode( '', $from ), '\\', '/' );

        if ( \str_contains( $string, '/' ) ) {
            [$key, $path] = \explode( '/', $string, 2 );
            $string       = "{$key}/".slug( $path );
        }

        if ( $this::validKey( $string ) ) {
            return $string;
        }

        return \hash( 'xxh3', $string );
    }

    /**
     * Checks if the passed `$string` starts with a `$parameterKey`.
     *
     * @param string $string
     *
     * @return array{0: false|string, 1: string}
     */
    private function resolveProvidedString( string $string ) : array
    {
        // Normalize separators to a forward slash
        $string = \strtr( $string, '\\', DIR_SEP );

        // We are only concerned with the first segment
        $parameterKey = \strstr( $string, DIR_SEP, true );

        if ( $parameterKey === false ) {
            $parameterKey = $string;
        }

        // At least one separator must be present
        if ( ! $parameterKey || ! \str_contains( $parameterKey, '.' ) ) {
            return [false, $string];
        }

        // Keys cannot start or end with a separator
        if ( \str_starts_with( $parameterKey, '.' ) || \str_ends_with( $parameterKey, '.' ) ) {
            return [false, $string];
        }

        return [$parameterKey, \strchr( $string, DIR_SEP ) ?: ''];
    }
}
