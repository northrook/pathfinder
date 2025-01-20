<?php

declare(strict_types=1);

namespace Core;

use Psr\Log\LoggerInterface;
use Northrook\Clerk;
use Support\FileInfo;
use Support\Interface\ActionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Core\Symfony\DependencyInjection\Autodiscover;
use Stringable, LengthException, InvalidArgumentException;
use function Support\isPath;

#[Autodiscover(
    tag : ['monolog.logger' => ['channel' => 'pathfinder']],
)]
final readonly class Pathfinder implements PathfinderInterface, ActionInterface
{
    /**
     * @param array<string, string>      $parameters   [placeholder]
     * @param null|ParameterBagInterface $parameterBag
     * @param null|PathfinderCache       $cache
     * @param null|LoggerInterface       $logger
     * @param bool                       $debug
     */
    public function __construct(
        private array                  $parameters = [],
        private ?ParameterBagInterface $parameterBag = null,
        private ?PathfinderCache       $cache = null,
        private ?LoggerInterface       $logger = null,
        private bool                   $debug = false,
    ) {}

    /**
     * @param string|Stringable $path
     * @param null|string       $relativeTo
     *
     * @return ?string
     */
    public function __invoke(
        string|Stringable $path,
        ?string           $relativeTo = null,
    ) : ?string {
        return $this->get( $path, $relativeTo );
    }

    /**
     * @param string|Stringable $path
     * @param null|string       $relativeTo
     *
     * @return ?FileInfo
     */
    public function getFileInfo(
        string|Stringable $path,
        ?string           $relativeTo = null,
    ) : ?FileInfo {
        $path = $this->get( $path, $relativeTo );

        return $path ? new FileInfo( $path ) : null;
    }

    private function cacheKey( null|string|bool|int|Stringable ...$from ) : string
    {
        return \hash( 'xxh3', \implode( '', $from ) );
    }

    /**
     * @param string|Stringable $path
     * @param null|string       $relativeTo
     *
     * @return ?string
     */
    public function get(
        string|Stringable $path,
        ?string           $relativeTo = null,
    ) : ?string {
        if ( $this->debug ) {
            Clerk::event( __METHOD__, $this::class );
        }

        $key = $this->cacheKey( $path.$relativeTo );

        $resolvedPath = $this->cache?->get( $key );

        $resolvedPath ??= $this->resolvePath( (string) $path, $relativeTo );

        if ( ! $resolvedPath ) {
            $this->logger?->warning( 'Unable to resolve path "'.$path.'".' );
        }
        elseif ( \file_exists( $resolvedPath ) || $relativeTo ) {
            $this->cache?->set( $key, $resolvedPath );
        }
        else {
            $this->cache?->delete( $key );
        }

        if ( $this->debug ) {
            Clerk::stop( __METHOD__ );
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
            (
                \ctype_alnum( \str_replace( ['.', '-', '_'], '', $key ) )
                    && \str_contains( $key, '.' )
                    && ! \str_starts_with( $key, '.' )
                    && ! \str_ends_with( $key, '.' )
            ),
            'Invalid parameter \''.$key.'\'. Must contain one period, cannot start or end with period.',
        );

        $cacheKey = $this->cacheKey( $key );

        // Return cached parameter if found
        if ( $cached = $this->cache?->get( $cacheKey ) ) {
            return $cached;
        }

        $parameter = $this->parameters[$key] ?? null;

        if ( ! $parameter && $this->parameterBag?->has( $key ) ) {
            $parameter = $this->parameterBag->get( $key );
        }

        // Handle
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

        $parameter = $this::normalize( $parameter );

        if ( ! isPath( $parameter ) ) {
            $this->logger?->warning( 'The value for {key}, is not path-like.', ['key' => $key] );
            return null;
        }

        $exists = \file_exists( $parameter );

        $this->logger?->info(
            'Pathfinder: Exists {exists} - {value} from {key}.',
            ['exists' => $exists, 'value' => $parameter, 'key' => $key],
        );

        if ( $exists ) {
            $this->cache?->set( $cacheKey, $parameter );
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

    final protected function resolvePath( string $path, ?string $relativeTo = null ) : ?string
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

    final protected function resolveParameter( string $string ) : ?string
    {
        $cacheKey = $this->cacheKey( $string );

        // Return cached parameter if found
        if ( $cached = $this->cache?->get( $cacheKey ) ) {
            return $cached;
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
            $this->cache?->set( $cacheKey, $path );
        }

        if ( ! $exists && $this->debug ) {
            $this->logger?->error(
                'Pathfinder: Unable to resolve {parameterKey}, the parameter does not provide a valid path.',
                ['parameterKey' => $parameterKey],
            );
        }

        return $path;
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
        $nroamlized = \str_replace( ['\\', '/'], DIRECTORY_SEPARATOR, $path );

        $isRelative = DIRECTORY_SEPARATOR === $nroamlized[0];

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
            $result  = 'Operation was halted to prevent overflow.';
            throw new LengthException( $message.PHP_EOL.$result );
        }

        // Preserve intended relative paths
        if ( $isRelative ) {
            $path = DIRECTORY_SEPARATOR.$path;
        }

        return $path;
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
        $parameterKey = \strstr( $string, DIRECTORY_SEPARATOR, true ) ?: $string;

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
