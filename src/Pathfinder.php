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
    public function __construct(
            private array                  $parameters = [],
            private ?ParameterBagInterface $parameterBag = null,
            private ?PathfinderCache       $cache = null,
            private ?LoggerInterface       $logger = null,
    ) {}

    public function __invoke( string $path, ?string $relativeTo = null ) : ?string
    {
        return $this->resolvePath( $path, $relativeTo );
    }

    public function get( string $path, ?string $relativeTo = null ) : ?string
    {
        return $this->resolvePath( $path, $relativeTo );
    }

    public function getFileInfo( string $path, ?string $relativeTo = null ) : ?FileInfo
    {
        return new FileInfo( $this->get( $path, $relativeTo ) );
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

        $this->logger?->info( 'Resolved {value} from {key}.', [ 'value' => $parameter, 'path' => $key ] );
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
        // Normalize separators to a forward slash
        $path = \str_replace( '\\', '/', $string );

        // dump( $string );
        // Check for $parameterKey
        $parameterKey = $this->containsParameterKey( $string );

        // Return cached parameter if found
        if ( $parameterKey && $this->cache?->has( $parameterKey ) ) {
            return $this->cache->get( $parameterKey );
        }

        // Return normalized path-like $string when no $parameterKey is found
        if ( !$parameterKey && isPath( $path ) ) {
            return $this::normalize( $path );
        }

        // Resolve the $root key.
        $parameter = $this->getParameter( $parameterKey );

        // Bail early on empty parameters
        if ( !$parameter ) {
            return null;
        }

        $resolvedPath = $this::normalize( $parameter, $string );

        $this->cache?->set( $parameterKey, $resolvedPath );

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

    /**
     * @param string  $string
     *
     * @return string
     *
     * @noinspection PhpUnusedPrivateMethodInspection // used with Symfony\Cache
     */
    private function cacheKey( string $string ) : string
    {
        $string = \str_replace( '\\', '/', $string );

        if ( !\str_contains( $string, '/' ) ) {
            return $string;
        }

        [ $root, $tail ] = \explode( '/', $string, 2 );
        if ( $tail ) {
            $tail = hash( 'xxh3', $tail );
            // $tail = \str_replace( [ '{', '}', '(', ')', '/', '\',', '@', ',' . ':' ], '.', $tail );
        }

        return "%{$root}%$tail";
    }

    /**
     * Checks if the passed `$string` starts with a `$parameterKey`.
     *
     * - `$string` will be normalized.
     * - `$string` will be separated from `$parameterKey` if found.
     *
     * @param ?string  $string
     *
     * @return false|string
     */
    private function containsParameterKey( ?string &$string ) : false | string
    {
        // Normalize separators to a forward slash
        $string = \str_replace( '\\', '/', $string );

        // We are only concerned with the first segment
        $parameterKey = \strstr( $string, '/', true ) ?: $string;

        // At least one separator must be present
        if ( !$parameterKey || !\str_contains( $parameterKey, '.' ) ) {
            return false;
        }

        // Keys cannot start or end with a separator
        if ( \str_starts_with( $parameterKey, '.' ) || \str_ends_with( $parameterKey, '.' ) ) {
            return false;
        }

        $string = \strchr( $string, '/' ) ?: null;

        return $parameterKey;
    }
}
