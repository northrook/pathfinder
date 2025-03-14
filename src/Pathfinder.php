<?php

declare(strict_types=1);

namespace Core;

use Core\Pathfinder\Path;
use Core\Interface\ActionInterface;
use JetBrains\PhpStorm\Language;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Cache\CachePoolTrait;
use Stringable;
use function Support\{as_string, isPath, normalizePath, normalizeUrl, str_includes};
use const Support\LOG_LEVEL;
use RuntimeException;

final class Pathfinder implements ActionInterface
{
    use CachePoolTrait;

    public bool $quiet = false;

    /**
     * @param array<string, string>       $parameters        [placeholder]
     * @param null|ParameterBagInterface  $parameterBag
     * @param null|CacheItemPoolInterface $cache
     * @param null|LoggerInterface        $logger
     * @param bool                        $deferCacheCommits
     */
    public function __construct(
        private readonly array                  $parameters = [],
        private readonly ?ParameterBagInterface $parameterBag = null,
        ?CacheItemPoolInterface                 $cache = null,
        private readonly ?LoggerInterface       $logger = null,
        bool                                    $deferCacheCommits = true,
    ) {
        $this->assignCacheAdapter(
            adapter : $cache,
            defer   : $deferCacheCommits,
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
        return normalizeUrl( $this->get( $path, $relativeTo ) );
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
        $resolvedPath = $this->getCache( $key );

        $resolvedPath ??= $this->resolvePath( $getPath, $relativePath );

        if ( ! \is_string( $resolvedPath ) ) {
            $this->log(
                'notice',
                'Unable to resolve path from {key}: {path}.',
                ['key' => $key, 'path' => $path],
            );
        }
        elseif ( \file_exists( $resolvedPath ) || $relativePath ) {
            $this->setCache( $key, $resolvedPath );
        }
        else {
            $this->unsetCache( $key );
        }

        $this->quiet = false;

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
        if ( $cached = $this->getCache( $cacheKey ) ) {
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
                'warning',
                'No value for {key}, it is {value}',
                ['value' => $value, 'key' => $key],
            );
            return null;
        }

        // Check potential nested parameterKeys
        if ( \str_contains( $parameter, '%' ) ) {
            $parameter = $this->resolveNestedParameters( $parameter );
        }

        $parameter = normalizePath( $parameter );

        if ( ! isPath( $parameter ) ) {
            $this->log( 'warning', 'The value for {key}, is not path-like.', ['key' => $key] );
            return null;
        }

        $exists = \file_exists( $parameter );

        if ( $exists ) {
            $this->setCache( $cacheKey, $parameter );
        }
        else {
            $this->log(
                'info',
                'Pathfinder: Exists {exists} - {value} from {key}.',
                ['exists' => 'false', 'value' => $parameter, 'key' => $key],
            );
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
                        'warning',
                        'Unable to resolve parameter {key} in {parameter}.',
                        ['key' => $match[1], 'parameter' => $parameter],
                    );
                }
                return $resolve ?? $match[0];
            },
            $parameter,
        );
    }

    protected function resolvePath( string $path, ?string $relativeTo = null ) : ?string
    {
        // Resolve potential relative path first
        if ( $relativeTo ) {
            $relativeTo = $this->resolveParameter( $relativeTo );
        }

        // Resolve the requested path
        $path = $this->resolveParameter( $path );

        // Bail early if no path is found
        if ( ! $path ) {
            return null;
        }

        // If relative, and relative path exists
        if ( $relativeTo ) {
            // Check they match
            if ( \str_starts_with( $path, $relativeTo ) ) {
                // Subtract the relative path
                $path = \substr( $path, \strlen( $relativeTo ) );
            }
            // Handle mismatched relative paths
            else {
                $this->log(
                    'critical',
                    'Relative path {relativeTo} to {path}, is not valid.',
                    ['relativeTo' => $relativeTo, 'path' => $path],
                );
            }
        }

        return $path;
    }

    protected function resolveParameter( string $string ) : ?string
    {
        $cacheKey = $this->cacheKey( $string );

        // Return cached parameter if found
        if ( $cached = $this->getCache( $cacheKey ) ) {
            return $cached;
        }

        // Check for $parameterKey
        [$parameterKey, $path] = $this->resolveProvidedString( $string );

        if ( $parameterKey ) {
            $parameter = $this->getParameter( $parameterKey );

            // Bail early on empty parameters
            if ( ! $parameter ) {
                $this->log(
                    'error',
                    'Pathfinder: {parameterKey}:{path}, could not resolve parameter.',
                    [
                        'parameterKey' => $parameterKey,
                        'path'         => $path,
                    ],
                );
                return null;
            }

            $path = normalizePath( $parameter, $path );
        }

        // Return early if the path contains at least one glob wildcard
        if ( str_includes( $path, '/*' ) ) {
            return $path;
        }

        if ( $exists = \file_exists( $path ) ) {
            $this->setCache( $cacheKey, $path );
        }

        if ( $parameterKey && ! $exists ) {
            $this->log(
                'notice',
                'Pathfinder: {parameterKey}:{path}, path does not exist.',
                \get_defined_vars(),
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
        $string = \str_replace( ['\\', '/'], DIRECTORY_SEPARATOR, $string );

        // We are only concerned with the first segment
        $parameterKey = \strstr( $string, DIRECTORY_SEPARATOR, true );

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

        return [$parameterKey, \strchr( $string, DIRECTORY_SEPARATOR ) ?: ''];
    }

    /**
     * @param \Psr\Log\LogLevel::* $level
     * @param string               $message
     * @param array<string, mixed> $context
     *
     * @return void
     */
    private function log(
        string $level,
        #[Language( 'Smarty' )]
        string $message,
        array  $context = [],
    ) : void {
        $code = LOG_LEVEL[$level];

        if ( $this->logger === null && ( $code >= LOG_LEVEL['error'] ) ) {
            foreach ( $context as $key => $value ) {
                if ( ! \str_contains( $message, "{{$key}}" ) ) {
                    continue;
                }
                $value   = as_string( $value );
                $message = \str_replace( "{{$key}}", "'{$value}'", $message );
            }
            throw new RuntimeException( "Pathfinder encountered a '{$level}' event: {$message}" );
        }

        if ( $this->quiet && $code < LOG_LEVEL['error'] ) {
            return;
        }

        $this->logger?->log( $code, $message, $context );
    }
}
