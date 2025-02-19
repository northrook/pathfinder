<?php

declare(strict_types=1);

namespace Core;

use Core\Pathfinder\Path;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Core\Interface\{ActionInterface, PathfinderInterface};
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Stringable, Throwable, InvalidArgumentException;
use function Support\{isPath, normalizePath};

final class Pathfinder implements PathfinderInterface, ActionInterface
{
    /**
     * @var array<string, string>|CacheItemPoolInterface
     */
    private CacheItemPoolInterface|array $cache;

    /**
     * @param array<string, string>       $parameters   [placeholder]
     * @param null|ParameterBagInterface  $parameterBag
     * @param null|CacheItemPoolInterface $cache
     * @param null|LoggerInterface        $logger
     */
    public function __construct(
        private readonly array                  $parameters = [],
        private readonly ?ParameterBagInterface $parameterBag = null,
        ?CacheItemPoolInterface                 $cache = null,
        private readonly ?LoggerInterface       $logger = null,
    ) {
        $this->cache = $cache ?? [];
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
     * @param string|Stringable      $path
     * @param null|string|Stringable $relativeTo
     *
     * @return string
     */
    public function get(
        string|Stringable      $path,
        null|string|Stringable $relativeTo = null,
    ) : string {
        $key = $this->cacheKey( $path.$relativeTo );

        try {
            $resolvedPath = $this->getCache( $key );
        }
        catch ( Throwable $exception ) {
            $this->logger?->error(
                'Unable to resolve parameter {key}.',
                [
                    'key'       => $key,
                    'exception' => $exception,
                ],
            );
        }
        $resolvedPath ??= $this->resolvePath( (string) $path, (string) $relativeTo );

        try {
            if ( ! \is_string( $resolvedPath ) ) {
                $this->logger?->warning( 'Unable to resolve path "'.$path.'".' );
            }
            elseif ( \file_exists( $resolvedPath ) || $relativeTo ) {
                $this->setCache( $key, $resolvedPath );
            }
            else {
                $this->unsetCache( $key );
            }
        }
        catch ( Throwable $exception ) {
            $this->logger?->error(
                'Unable to resolve parameter {key}.',
                [
                    'key'       => $key,
                    'exception' => $exception,
                ],
            );
        }

        return $resolvedPath;
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
        try {
            if ( $cached = $this->getCache( $cacheKey ) ) {
                return $cached;
            }
        }
        catch ( Throwable $exception ) {
            $this->logger?->error(
                'Unable to resolve parameter {key}.',
                [
                    'key'       => $key,
                    'exception' => $exception,
                ],
            );
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

            $this->logger?->warning(
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
            $this->logger?->warning( 'The value for {key}, is not path-like.', ['key' => $key] );
            return null;
        }

        $exists = \file_exists( $parameter );

        if ( $exists ) {
            try {
                $this->setCache( $cacheKey, $parameter );
            }
            catch ( Throwable $exception ) {
                $this->logger?->error(
                    'Unable to resolve parameter {key}.',
                    [
                        'key'       => $key,
                        'exception' => $exception,
                    ],
                );
            }
        }
        else {
            $this->logger?->info(
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
                    $this->logger?->warning(
                        'Unable to resolve parameter {key} in {parameter}.',
                        [
                            'key'       => $match[1],
                            'parameter' => $parameter,
                        ],
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
                if ( $this->logger ) {
                    $this->logger->critical(
                        'Relative path {relativeTo} to {path}, is not valid.',
                        ['relativeTo' => $relativeTo, 'path' => $path],
                    );
                }
                else {
                    $message = "Relative path [{$relativeTo}][{$path}], is not valid.";
                    throw new InvalidArgumentException( $message );
                }
            }
        }

        return $path;
    }

    protected function resolveParameter( string $string ) : ?string
    {
        $cacheKey = $this->cacheKey( $string );

        // Return cached parameter if found
        try {
            if ( $cached = $this->getCache( $cacheKey ) ) {
                return $cached;
            }
        }
        catch ( Throwable $exception ) {
            $this->logger?->error(
                'Unable to resolve parameter {key}.',
                [
                    'key'       => $string,
                    'exception' => $exception,
                ],
            );
        }

        // Check for $parameterKey
        [$parameterKey, $path] = $this->resolveProvidedString( $string );

        if ( $parameterKey ) {
            $parameter = $this->getParameter( $parameterKey );

            // Bail early on empty parameters
            if ( ! $parameter ) {
                return null;
            }

            $path = normalizePath( $parameter, $path );
        }

        if ( $exists = \file_exists( $path ) ) {
            try {
                $this->setCache( $cacheKey, $path );
            }
            catch ( Throwable $exception ) {
                $this->logger?->error(
                    'Unable to resolve parameter {key}.',
                    [
                        'key'       => $cacheKey,
                        'exception' => $exception,
                    ],
                );
            }
        }

        if ( $parameterKey && ! $exists ) {
            $this->logger?->error(
                'Pathfinder: Unable to resolve {parameterKey}, the parameter does not provide a valid path.',
                [
                    'parameterKey' => $parameterKey,
                    'path'         => $path,
                ],
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

    /**
     * Retrieve a cached value.
     *
     * @param string $key
     *
     * @return null|string
     */
    private function getCache( string $key ) : ?string
    {
        if ( \is_array( $this->cache ) ) {
            return $this->cache[$key] ?? null;
        }

        try {
            if ( $this->cache->hasItem( $key ) ) {
                return $this->cache->getItem( $key )->get();
            }
        }
        catch ( Throwable $exception ) {
            $this->logger?->error(
                'getCache: {key}. '.$exception->getMessage(),
                ['key' => $key, 'exception' => $exception],
            );
        }
        return null;
    }

    private function setCache( string $key, string $value ) : void
    {
        if ( \is_array( $this->cache ) ) {
            $this->cache[$key] = $value;
            return;
        }

        try {
            $item = $this->cache->getItem( $key );
            $item->set( $value );
        }
        catch ( Throwable $exception ) {
            $this->logger?->error(
                'setCache: {key}. '.$exception->getMessage(),
                ['key' => $key, 'exception' => $exception],
            );
        }
    }

    private function unsetCache( string $key ) : void
    {
        if ( \is_array( $this->cache ) ) {
            unset( $this->cache[$key] );
            return;
        }

        try {
            $this->cache->deleteItem( $key );
        }
        catch ( Throwable $exception ) {
            $this->logger?->error(
                'unsetCache: {key}. '.$exception->getMessage(),
                ['key' => $key, 'exception' => $exception],
            );
        }
    }

    private function cacheKey( null|string|bool|int|Stringable ...$from ) : string
    {
        return \hash( 'xxh3', \implode( '', $from ) );
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
}
