<?php

declare( strict_types = 1 );

namespace Core;

use Psr\Log\LoggerInterface;
use Support\FileInfo;
use Support\Interface\ActionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use function Support\isPath;

final readonly class Pathfinder implements PathfinderInterface, ActionInterface
{

    /**
     * @param array                       $parameters
     * @param null|ParameterBagInterface  $parameterBag
     * @param null|PathfinderCache        $cache
     * @param null|LoggerInterface        $logger
     * @param bool                        $hashKeys
     */
    public function __construct(
            private array                  $parameters = [],
            private ?ParameterBagInterface $parameterBag = null,
            private ?PathfinderCache       $cache = null,
            private ?LoggerInterface       $logger = null,
            private bool                   $hashKeys = false,
    ) {}

    /**
     * @param string       $path
     * @param null|string  $relativeTo
     * @param bool         $assertive
     *
     * @return ($assertive is true ? string : null|string)
     */
    public function __invoke( string $path, ?string $relativeTo = null, bool $assertive = false ) : ?string
    {
        return $this->get( $path, $relativeTo, $assertive );
    }

    /**
     * @param string       $path
     * @param null|string  $relativeTo
     * @param bool         $assertive
     *
     * @return ($assertive is true ? string : null|string)
     */
    public function get( string $path, ?string $relativeTo = null, bool $assertive = false ) : ?string
    {
        $path = $this->resolvePath( $path, $relativeTo );

        if ( $assertive ) {
            \assert( \is_string( $path ) );
        }

        return $path;
    }

    /**
     * @param string       $path
     * @param null|string  $relativeTo
     * @param bool         $assertive
     *
     * @return ($assertive is true ? FileInfo : null|FileInfo)
     */
    public function getFileInfo( string $path, ?string $relativeTo = null, bool $assertive = false ) : ?FileInfo
    {
        return new FileInfo( $this->get( $path, $relativeTo, $assertive ) );
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
     * This performs no validation or caching.
     *
     * @param string  $key
     *
     * @return null|string
     */
    public function getParameter( string $key ) : ?string
    {
        \assert(
                (
                        \ctype_alnum( \str_replace( [ '.', '-', '_' ], '', $key ) ) &&
                        \str_contains( $key, '.' )
                        && !\str_starts_with( $key, '.' )
                        && !\str_ends_with( $key, '.' )
                ),
                'Invalid parameter \'' . $key . '\'. Must contain one period, cannot start or end with period.',
        );

        // Return cached  if found
        if ( $this->cache?->has( $key ) ) {
            return $this->cache->get( $key );
        }

        $parameter = $this->parameters[ $key ] ?? null;

        if ( !$parameter && $this->parameterBag?->has( $key ) ) {
            $parameter = $this->parameterBag->get( $key );
        }

        if ( !$parameter ) {
            $value = \is_string( $parameter ) ? "empty string" : gettype( $parameter );

            $this->logger?->warning(
                    'No value for {key}, it is {value}',
                    [ 'value' => $value, 'key' => $key ],
            );
            return null;
        }

        $parameter = $this::normalize( $parameter );

        if ( !isPath( $parameter ) ) {
            $this->logger?->warning( 'The value for {key}, is not path-like.', [ 'key' => $key ] );
            return null;
        }

        $exists = file_exists( $parameter );

        $this->logger?->info(
                'Pathfinder: Exists {exists} - {value} from {key}.',
                [ 'exists' => $exists, 'value' => $parameter, 'key' => $key ],
        );

        if ( $exists ) {
            $this->cache?->set( $key, $parameter );
        }

        return $parameter;
    }

    public function hasParameter( string $key ) : bool
    {
        return $this->parameters[ $key ] ?? $this->parameterBag?->has( $key ) ?? false;
    }

    final protected function resolvePath( string $path, ?string $relativeTo = null ) : ?string
    {
        $path = $this->resolveParameter( $path );

        if ( !$path ) {
            return null;
        }

        if ( $relativeTo ) {
            $relativeTo = $this->resolveParameter( $relativeTo );

            if ( $relativeTo && \str_starts_with( $relativeTo, $relativeTo ) ) {
                $path = \substr( $path, \strlen( $relativeTo ) );
            }
            else {
                if ( !$relativeTo ) {
                    $relativeTo = \is_string( $relativeTo ) ? "empty string" : gettype( $relativeTo );
                }

                $this->logger?->critical(
                        "Relative path {relativeTo} to {path}, is not valid.",
                        [ 'relativeTo' => $relativeTo, 'path' => $path, ],
                );

                if ( !$this->logger ) {
                    $message = "Relative path [{$relativeTo}][{$path}], is not valid.";
                    throw new \InvalidArgumentException( $message );
                }
            }
        }

        return $path;
    }

    final protected function resolveParameter( string $string ) : ?string
    {
        $cacheKey = $this->resolvedPathKey( $string );

        // Return cached parameter if found
        if ( $this->cache?->has( $cacheKey ) ) {
            return $this->cache->get( $cacheKey );
        }

        // Check for $parameterKey
        [ $parameterKey, $path ] = $this->resolveProvidedString( $string );

        // Resolve the $root key.
        $parameter = $this->getParameter( $parameterKey );

        // Bail early on empty parameters
        if ( !$parameter ) {
            return null;
        }

        $resolvedPath = $this::normalize( $parameter, $path );

        if ( file_exists( $resolvedPath ) ) {
            $this->cache?->set( $cacheKey, $resolvedPath );
        }
        else {
            $this->logger?->error(
                    "Pathfinder: Unable to resolve {parameterKey}, the parameter does not provide a valid path.",
                    [ 'parameterKey' => $parameterKey ],
            );
        }

        return $resolvedPath;
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
     * @param ?string  ...$path
     */
    public static function normalize( ?string ...$path ) : string
    {
        // Normalize separators
        $nroamlized = \str_replace( [ '\\', '/' ], DIRECTORY_SEPARATOR, $path );

        $isRelative = DIRECTORY_SEPARATOR === $nroamlized[ 0 ];

        // Implode->Explode for separator deduplication
        $exploded = \explode( DIRECTORY_SEPARATOR, \implode( DIRECTORY_SEPARATOR, $nroamlized ) );

        // Ensure each part does not start or end with illegal characters
        $exploded = \array_map( static fn( $item ) => \trim( $item, " \n\r\t\v\0\\/" ), $exploded );

        // Filter the exploded path, and implode using the directory separator
        $path = \implode( DIRECTORY_SEPARATOR, \array_filter( $exploded ) );

        if ( ( $length = \mb_strlen( $path ) ) > ( $limit = PHP_MAXPATHLEN - 2 ) ) {
            $method  = __METHOD__;
            $length  = (string) $length;
            $limit   = (string) $limit;
            $message = "{$method} resulted in a string of {$length}, exceeding the {$limit} character limit.";
            $result  = "Operation was halted to prevent overflow.";
            throw new \LengthException( $message . PHP_EOL . $result );
        }

        // Preserve intended relative paths
        if ( $isRelative ) {
            $path = DIRECTORY_SEPARATOR . $path;
        }

        return $path;
    }

    private function resolvedPathKey( string $string ) : string
    {
        return $this->hashKeys
                ? hash( 'xxh3', $string )
                : \str_replace( [ '{', '}', '(', ')', '/', '\\', '@', ',' . ':' ], '.', $string );
    }

    /**
     * Checks if the passed `$string` starts with a `$parameterKey`.
     *
     * @param ?string  $string
     *
     * @return array{0: false|string, 1: null|string}
     */
    private function resolveProvidedString( ?string $string ) : array
    {
        // Normalize separators to a forward slash
        $string = \str_replace( '\\', '/', $string );

        // We are only concerned with the first segment
        $parameterKey = \strstr( $string, '/', true ) ?: $string;

        // At least one separator must be present
        if ( !$parameterKey || !\str_contains( $parameterKey, '.' ) ) {
            return [ false, $string ];
        }

        // Keys cannot start or end with a separator
        if ( \str_starts_with( $parameterKey, '.' ) || \str_ends_with( $parameterKey, '.' ) ) {
            return [ false, $string ];
        }

        return [ $parameterKey, \strchr( $string, '/' ) ?: null ];
    }
}
