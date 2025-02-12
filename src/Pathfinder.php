<?php

declare(strict_types=1);

namespace Core;

use Core\Pathfinder\Path;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Core\Interface\{ActionInterface, PathfinderInterface, StorageInterface};
use Symfony\Component\Cache\{Psr16Cache, Adapter\ArrayAdapter};
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use LengthException;
use Stringable, Throwable, InvalidArgumentException;

final readonly class Pathfinder implements PathfinderInterface, ActionInterface
{
    private StorageInterface|Psr16Cache $cache;

    /**
     * @param array<string, string>                        $parameters   [placeholder]
     * @param null|ParameterBagInterface                   $parameterBag
     * @param null|CacheItemPoolInterface|StorageInterface $cache
     * @param null|LoggerInterface                         $logger
     */
    public function __construct(
        private array                                $parameters = [],
        private ?ParameterBagInterface               $parameterBag = null,
        null|StorageInterface|CacheItemPoolInterface $cache = null,
        private ?LoggerInterface                     $logger = null,
    ) {
        if ( $cache instanceof StorageInterface ) {
            $this->cache = $cache;
        }
        else {
            $this->cache = new Psr16Cache( $cache ?? new ArrayAdapter() );
        }
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
            $resolvedPath = $this->cache->get( $key );
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
                $this->cache->set( $key, $resolvedPath );
            }
            else {
                $this->cache->delete( $key );
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
            if ( $cached = $this->cache->get( $cacheKey ) ) {
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

        $parameter = $this::normalize( $parameter );

        if ( ! $this::isPath( $parameter ) ) {
            $this->logger?->warning( 'The value for {key}, is not path-like.', ['key' => $key] );
            return null;
        }

        $exists = \file_exists( $parameter );

        if ( $exists ) {
            try {
                $this->cache->set( $cacheKey, $parameter );
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
            if ( $cached = $this->cache->get( $cacheKey ) ) {
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

            $path = $this::normalize( $parameter, $path );
        }

        if ( $exists = \file_exists( $path ) ) {
            try {
                $this->cache->set( $cacheKey, $path );
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

    /**
     * Checks if a given value has a `URL` structure.
     *
     * ⚠️ Does **NOT** validate the URL in any capacity!
     *
     * @param string|Stringable $value
     * @param ?string           $requiredProtocol
     *
     * @return bool
     */
    public static function isUrl( string|Stringable $value, ?string $requiredProtocol = null ) : bool
    {
        // Stringify
        $string = \trim( (string) $value );

        // Can not be an empty string
        if ( ! $string ) {
            return false;
        }

        // Must not start with a number
        if ( \is_numeric( $string[0] ) ) {
            return false;
        }

        /**
         * Does the string resemble a URL-like structure?
         *
         * Ensures the string starts with a schema-like substring, and has a real-ish domain extension.
         *
         * - Will gladly accept bogus strings like `not-a-schema://d0m@!n.tld/`
         */
        if ( ! \preg_match( '#^([\w\-+]*?[:/]{2}).+\.[a-z0-9]{2,}#m', $string ) ) {
            return false;
        }

        // Check for required protocol if requested
        return ! ( $requiredProtocol && ! \str_starts_with( $string, \rtrim( $requiredProtocol, ':/' ).'://' ) );
    }

    /**
     * Checks if a given value has a `path` structure.
     *
     * ⚠️ Does **NOT** validate the `path` in any capacity!
     *
     * @param string|Stringable $value
     * @param string            $contains [..] optional `str_contains` check
     *
     * @return bool
     */
    public static function isPath( string|Stringable $value, string $contains = '..' ) : bool
    {
        // Stringify
        $string = \trim( (string) $value );

        // Must be at least two characters long to be a path string
        if ( ! $string || \strlen( $string ) < 2 ) {
            return false;
        }

        // One or more slashes indicate this could be a path string
        if ( \str_contains( $string, '/' ) || \str_contains( $string, '\\' ) ) {
            return true;
        }

        // Any periods that aren't in the first 3 characters indicate this could be a `path/file.ext`
        if ( \strrpos( $string, '.' ) > 2 ) {
            return true;
        }

        // Indicates this could be a `.hidden` path
        if ( $string[0] === '.' && \ctype_alpha( $string[1] ) ) {
            return true;
        }

        return \str_contains( $string, $contains );
    }

    /**
     * # Normalise a `string` or `string[]`, assuming it is a `path`.
     *
     * - If an array of strings is passed, they will be joined using the directory separator.
     * - Normalises slashes to system separator.
     * - Removes repeated separators.
     * - Will throw a {@see ValueError} if the resulting string exceeds {@see PHP_MAXPATHLEN}.
     *
     * ```
     * normalizePath( './assets\\\/scripts///example.js' );
     * // => '.\assets\scripts\example.js'
     * ```
     *
     * @param string ...$path
     */
    public static function normalize( string ...$path ) : string
    {
        // Normalize separators
        $normalized = \str_replace( ['\\', '/'], DIRECTORY_SEPARATOR, $path );

        $isRelative = $normalized[0][0] === DIRECTORY_SEPARATOR;

        // Implode->Explode for separator deduplication
        $exploded = \explode( DIRECTORY_SEPARATOR, \implode( DIRECTORY_SEPARATOR, $normalized ) );

        // Ensure each part does not start or end with illegal characters
        $exploded = \array_map( static fn( $item ) => \trim( $item, " \n\r\t\v\0\\/" ), $exploded );

        // Filter the exploded path, and implode using the directory separator
        $path = \implode( DIRECTORY_SEPARATOR, \array_filter( $exploded ) );

        if ( ( $length = \mb_strlen( $path ) ) > ( $limit = PHP_MAXPATHLEN - 2 ) ) {
            $method  = __METHOD__;
            $length  = (string) $length;
            $limit   = (string) $limit;
            $message = "{$method} resulted in a string of {$length}, exceeding the {$limit} character limit.";
            $result  = 'Operation was halted to prevent overflow.';
            throw new LengthException( $message.PHP_EOL.$result );
        }

        // Preserve intended relative paths
        if ( $isRelative ) {
            $path = DIRECTORY_SEPARATOR.$path;
        }

        return $path;
    }
}
