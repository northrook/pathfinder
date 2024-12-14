<?php

declare(strict_types=1);

namespace Core;

use Support\FileInfo;

interface PathfinderInterface
{
    /**
     * @param string      $path
     * @param null|string $relativeTo
     * @param bool        $assertive
     *
     * @return ($assertive is true ? string : null|string)
     */
    public function __invoke( string $path, ?string $relativeTo = null, bool $assertive = false ) : ?string;

    /**
     * @param string      $path
     * @param null|string $relativeTo
     * @param bool        $assertive
     *
     * @return ($assertive is true ? string : null|string)
     */
    public function get( string $path, ?string $relativeTo = null, bool $assertive = false ) : ?string;

    /**
     * @param string      $path
     * @param null|string $relativeTo
     * @param bool        $assertive
     *
     * @return ($assertive is true ? FileInfo : null|FileInfo)
     */
    public function getFileInfo( string $path, ?string $relativeTo = null, bool $assertive = false ) : ?FileInfo;
}
