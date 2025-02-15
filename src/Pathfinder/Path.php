<?php

declare(strict_types=1);

namespace Core\Pathfinder;

use Symfony\Component\Filesystem\Filesystem;
use Stringable;
use SplFileInfo;
use ValueError;
use InvalidArgumentException;
use RuntimeException;
use BadMethodCallException;
use function Support\{isPath, isUrl, normalizePath};
use const LOCK_EX;

class Path implements Stringable
{
    protected SplFileInfo $fileInfo;

    /** @var array<int|string, array<array-key,mixed>|string> `[query=>param]` */
    protected array $queryParameters = [];

    public function __construct( Stringable|string $path )
    {
        $this->setFileInfo( $path );
    }

    final public function append( string|Stringable $string ) : self
    {
        $path = (string) $string;

        if ( \str_starts_with( $path, '?' ) ) {
            // TODO: [low] Improve compatibility
            \parse_str( $path, $this->queryParameters );
            return $this;
        }

        if ( ! $this->fileInfo->isFile() ) {
            $this->setFileInfo( $this->fileInfo->getPathname().$path );
        }
        else {
            throw new ValueError(
                __METHOD__."\nThe path '{$this->fileInfo->getPathname()}' is a file path, and should not be appended by another file path '{$path}'.",
            );
        }

        return $this;
    }

    /**
     * Atomically dumps content into a file.
     *
     * - {@see IOException} will be caught and logged as an error, returning false
     *
     * @param resource|string $content                 The data to write into the file
     * @param bool            $lock
     * @param bool            $makeRequiredDirectories
     *
     * @return bool|int True if the file was written to, false if it already existed or an error occurred
     */
    final public function save(
        mixed $content,
        bool  $lock = false,
        bool  $makeRequiredDirectories = true,
    ) : bool|int {
        if ( $makeRequiredDirectories ) {
            $this->mkdir();
        }

        return \file_put_contents( $this->fileInfo->getPathname(), $content, $lock ? LOCK_EX : 0 );
    }

    final public function mkdir( int $permissions = 0777, bool $recursive = true ) : bool
    {
        $dir = \dirname( $this->fileInfo->getPathname() );
        if ( ! \is_dir( $dir ) ) {
            return \mkdir( $dir, $permissions, $recursive );
        }
        return true;
    }

    /**
     * Copies {@see self::getRealPath()} to {@see $targetFile}.
     *
     * - Requires {@see Filesystem}.
     * - If the target file is automatically overwritten when this file is newer.
     * - If the target is newer, $overwriteNewerFiles decides whether to overwrite.
     * - {@see IOException}s will be caught and logged as an error, returning false
     *
     * @param string $targetFile
     * @param bool   $overwriteNewerFiles
     *
     * @return bool True if the file was written to, false if it already existed or an error occurred
     */
    final public function copy( string $targetFile, bool $overwriteNewerFiles = false ) : bool
    {
        if ( \class_exists( Filesystem::class ) ) {
            ( new Filesystem() )->copy( $this->fileInfo->getPathname(), $targetFile, $overwriteNewerFiles );
        }

        throw new BadMethodCallException( __METHOD__." requires the 'symfony/filesystem' package." );
    }

    /**
     * Remove the file or directory located at {@see self::$fileInfo}.
     */
    final public function remove() : void
    {
        if ( \class_exists( Filesystem::class ) ) {
            ( new Filesystem() )->remove( $this->fileInfo->getPathname() );
            return;
        }

        throw new BadMethodCallException( __METHOD__." requires the 'symfony/filesystem' package." );
    }

    final public function exists( bool $throwOnError = false ) : bool
    {
        $exists = \file_exists( $this->fileInfo->getPathname() );

        if ( $exists === false && $throwOnError ) {
            throw new RuntimeException( 'Unable to read file: '.$this->fileInfo->getPathname() );
        }

        return $exists;
    }

    final public function isPath() : bool
    {
        return isPath( $this->fileInfo->getPathname() );
    }

    final public function isFile() : bool
    {
        return $this->fileInfo->isFile();
    }

    final public function isDirectory() : bool
    {
        return $this->fileInfo->isDir();
    }

    final public function isDotFile() : bool
    {
        return \str_starts_with( $this->fileInfo->getBasename(), '.' ) && $this->isFile();
    }

    final public function isDotDirectory() : bool
    {
        return \str_contains( $this->fileInfo->getPath(), DIRECTORY_SEPARATOR.'.' );
    }

    final public function isUrl( ?string $protocol = null ) : bool
    {
        return isUrl( $this->fileInfo->getPathname(), $protocol );
    }

    final public function isRelative( bool $traversible = false ) : bool
    {
        return \str_starts_with(
            $this->fileInfo->getPathname(),
            $traversible
                        ? '..'.DIRECTORY_SEPARATOR
                        : DIRECTORY_SEPARATOR,
        );
    }

    final public function isReadable() : bool
    {
        if ( $this->isUrl() ) {
            $session = \curl_init( $this->fileInfo->getPathname() );

            // Set cURL options
            \curl_setopt( $session, CURLOPT_NOBODY, true );         // Use HEAD request
            \curl_setopt( $session, CURLOPT_TIMEOUT, 5 );           // Set timeout
            \curl_setopt( $session, CURLOPT_FOLLOWLOCATION, true ); // Follow redirects
            \curl_setopt( $session, CURLOPT_FAILONERROR, true );    // Fail on HTTP errors (e.g., 404)
            \curl_setopt( $session, CURLOPT_RETURNTRANSFER, true ); // Suppress direct output

            \curl_exec( $session );

            $httpCode  = \curl_getinfo( $session, CURLINFO_HTTP_CODE );
            $hasErrors = (bool) \curl_errno( $session );
            $cUrlError = \curl_error( $session );

            \curl_close( $session );

            if ( $hasErrors ) {
                throw new InvalidArgumentException(
                    __METHOD__." cURL [{$httpCode}] error: ".$cUrlError,
                );
            }

            return $httpCode >= 200 && $httpCode < 400;
        }

        return $this->fileInfo->isReadable();
    }

    final public function SplFileInfo() : SplFileInfo
    {
        return $this->fileInfo;
    }

    final public function getExtension() : string
    {
        return $this->fileInfo->getExtension();
    }

    /**
     * @param bool $falseOnError
     *
     * @return ($falseOnError is true ? false|string : string)
     */
    public function getRealPath( bool $falseOnError = false ) : false|string
    {
        if ( $falseOnError ) {
            return $this->fileInfo->getRealPath();
        }

        return $this->fileInfo->getRealPath() ?: $this->fileInfo->getPathname();
    }

    public function getPathname() : string
    {
        return $this->fileInfo->getPathname();
    }

    public function getPath() : string
    {
        return $this->fileInfo->getPath();
    }

    /**
     * Returns the `filename` without the extension.
     *
     * @return string
     */
    final public function getFilename() : string
    {
        return \strrchr( $this->fileInfo->getFilename(), '.', true ) ?: $this->fileInfo->getFilename();
    }

    final public function getContents( bool $throwOnError = false ) : ?string
    {
        if ( $this->isUrl() ) {
            if ( $throwOnError ) {
                $message = $this::class.'::getContents() only supports local files.';
                $instead = 'Use \Support\CURL::fetch() instead.';
                throw new InvalidArgumentException( "{$message} {$instead}" );
            }
            return null;
        }

        $contents = \file_get_contents( $this->fileInfo->getPathname() );

        if ( $contents === false && $throwOnError ) {
            throw new RuntimeException( 'Unable to read file: '.$this->getPathname() );
        }

        return $contents ?: null;
    }

    /**
     * Perform one or more `glob(..)` patterns on {@see self::getPathname()}.
     *
     * Each matched result is `normalized`.
     *
     * @param string|string[] $pattern
     * @param ?int            $flags   [auto]
     *
     * @return self[]
     */
    final public function glob(
        string|array $pattern,
        ?int         $flags = null,
    ) : array {
        $flags ??= GLOB_NOSORT | GLOB_BRACE;
        $path = \rtrim( $this->fileInfo->getPathname(), '\\/' );
        $glob = [];

        foreach ( (array) $pattern as $match ) {
            $match = \DIRECTORY_SEPARATOR.\ltrim( $match, '\\/' );
            $glob  = [...$glob, ...( \glob( $path.$match, $flags ) ?: [] )];
        }

        return \array_map( self::from( ... ), $glob );
    }

    /**
     * Returns {@see realpath} if cached.
     *
     * @return string
     */
    public function __toString() : string
    {
        return $this->getRealPath();
    }

    final protected function setFileInfo( Stringable|string $path ) : void
    {
        $string = (string) $path;
        if ( ! \str_contains( $string, '://' ) ) {
            $string = normalizePath( $string );
        }
        $this->fileInfo = new SplFileInfo( $string );
    }

    final public static function from( string|Stringable $filename ) : self
    {
        return new self( $filename );
    }
}
