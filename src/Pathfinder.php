<?php

declare(strict_types=1);

namespace Core;

use Core\Autowire\Logger;
use Core\Pathfinder\Path;
use Core\Interface\{ActionInterface, Loggable};
use Cache\CacheHandler;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Stopwatch\Stopwatch;
use Stringable;
use function Support\{str_includes_any, is_path, normalize_path, normalize_url};

final class Pathfinder implements ActionInterface, Loggable
{
    use Logger;

    private readonly CacheHandler $cache;

    /**
     * @param array<string, string>   $parameters        [placeholder]
     * @param ?ParameterBagInterface  $parameterBag
     * @param ?CacheItemPoolInterface $cache
     * @param ?Stopwatch              $stopwatch
     * @param bool                    $deferCacheCommits
     */
    public function __construct(
        private readonly array                  $parameters = [],
        private readonly ?ParameterBagInterface $parameterBag = null,
        ?CacheItemPoolInterface                 $cache = null,
        ?Stopwatch                              $stopwatch = null,
        bool                                    $deferCacheCommits = true,
    ) {
        $this->cache = new CacheHandler(
            adapter     : $cache,
            deferCommit : $deferCacheCommits,
            profiler    : $stopwatch,
        );
    }

    /**
     * @param string|Stringable      $path
     * @param null|string|Stringable $relativeTo
     *
     * @return string
     */
    public function __invoke(
        string|Stringable      $path,
        null|string|Stringable $relativeTo = null,
    ) : string {
        return $this->get( $path, $relativeTo );
    }

    /**
     * @param string|Stringable      $path
     * @param null|string|Stringable $relativeTo
     *
     * @return Path
     */
    public function getPath(
        string|Stringable      $path,
        null|string|Stringable $relativeTo = null,
    ) : Path {
        $path = $this->get( $path, $relativeTo );

        return new Path( $path );
    }

    /**
     * A `normalizeUrl` filtered string.
     *
     * @param string|Stringable      $path
     * @param null|string|Stringable $relativeTo
     *
     * @return string
     */
    public function getUrl(
        string|Stringable      $path,
        null|string|Stringable $relativeTo = null,
    ) : string {
        return normalize_url( $this->get( $path, $relativeTo ) );
    }

    /**
     * @param string|Stringable      $path
     * @param null|string|Stringable $relativeTo
     *
     * @return string
     */
    public function get(
        string|Stringable      $path,
        null|string|Stringable $relativeTo = null,
    ) : string {
        $getPath      = (string) $path;
        $relativePath = $relativeTo ? (string) $relativeTo : null;

        $key = $this->cacheKey( $getPath.$relativePath );

        /** @var ?string $resolvedPath */
        $resolvedPath = $this->cache->get( $key );

        $resolvedPath ??= $this->resolvePath( $getPath, $relativePath );

        if ( ! \is_string( $resolvedPath ) ) {
            $this->log(
                'Unable to resolve path from {key}: {path}.',
                ['key' => $key, 'path' => $path],
                'notice',
            );
        }
        elseif ( \file_exists( $resolvedPath ) || $relativePath ) {
            $this->cache->set( $key, $resolvedPath );
        }
        // TODO: [lo] - This should be handled by a 'purge outdated' ran during cleanup,
        //              Should not be performed during runtime.
        // else {
        //     $this->uncache->$this->set( $key );
        // }

        return $resolvedPath ?? $getPath;
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
        if ( $cached = $this->cache->get( $cacheKey ) ) {
            return $cached;
        }

        $parameter = $this->parameters[$key] ?? null;

        if ( ! $parameter && $this->parameterBag?->has( $key ) ) {
            $parameter = $this->parameterBag->get( $key );
        }

        // Handle value errors
        if ( ! $parameter || ! \is_string( $parameter ) ) {
            $value = \is_string( $parameter )
                    ? 'empty string'
                    : \gettype( $parameter );

            $this->log(
                'No value for {key}, it is {value}',
                ['value' => $value, 'key' => $key],
                'warning',
            );
            return null;
        }

        // Check potential nested parameterKeys
        if ( \str_contains( $parameter, '%' ) ) {
            $parameter = $this->resolveNestedParameters( $parameter );
        }

        $parameter = normalize_path( $parameter );

        if ( ! is_path( $parameter ) ) {
            $this->log( 'The value for {key} is not path-like.', ['key' => $key], 'warning' );
            return null;
        }

        if ( \file_exists( $parameter ) ) {
            $this->cache->set( $cacheKey, $parameter );
        }

        return $parameter;
    }

    /**
     * Check if a key exists in {@see self::$parameters} or a provided {@see self::$parameterBag}.
     *
     * @param string $key
     *
     * @return bool
     */
    public function hasParameter( string $key ) : bool
    {
        return \array_key_exists( $key, $this->parameters ) || $this->parameterBag?->has( $key );
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
                    $this->log(
                        'Unable to resolve parameter {key} in {parameter}.',
                        ['key' => $match[1], 'parameter' => $parameter],
                        'warning',
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
                $this->log(
                    'Relative path {relativeTo} to {path} is not valid.',
                    ['relativeTo' => $relativeTo, 'path' => $path],
                    'critical',
                );
            }
        }

        return $path;
    }

    protected function resolveParameter( string $string ) : ?string
    {
        $cacheKey = $this->cacheKey( $string );

        // Return cached parameter if found
        if ( $cached = $this->cache->get( $cacheKey ) ) {
            return $cached;
        }

        // Check for $parameterKey
        [$parameterKey, $path] = $this->resolveProvidedString( $string );

        if ( $parameterKey ) {
            $parameter = $this->getParameter( $parameterKey );

            // Bail early on empty parameters
            if ( ! $parameter ) {
                $this->log(
                    'Pathfinder: {parameterKey}:{path}, could not resolve parameter.',
                    [
                        'parameterKey' => $parameterKey,
                        'path'         => $path,
                    ],
                    'error',
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
            $this->cache->set( $cacheKey, $path );
        }

        if ( $parameterKey && ! $exists ) {
            $this->log(
                'Pathfinder: {parameterKey}:{path}, the path does not exist.',
                \get_defined_vars(),
                'notice',
            );
        }

        return $path;
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public static function validKey( string $key ) : bool
    {
        // Keys must have at least one delimiter
        if ( ! \str_contains( $key, '.' ) ) {
            return false;
        }

        /** Only `[a-zA-Z.-_]` allowed */
        if ( ! \ctype_alpha( \str_replace( ['.', '-', '_'], '', $key ) ) ) {
            return false;
        }

        // Keys cannot start or end with the delimiter
        return ! ( \str_starts_with( $key, '.' ) || \str_ends_with( $key, '.' ) );
    }

    private function cacheKey( null|string|bool|int|Stringable ...$from ) : string
    {
        $string = \implode( '', $from );

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
