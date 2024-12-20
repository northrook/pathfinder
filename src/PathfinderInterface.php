<?php

declare(strict_types=1);

namespace Core;

use Stringable;
use Support\FileInfo;

interface PathfinderInterface
{
    /**
     * @param string|Stringable $path
     * @param null|string       $relativeTo
     * @param bool              $assertive
     *
     * @return ($assertive is true ? string : null|string)
     */
    public function __invoke(
        string|Stringable $path,
        ?string           $relativeTo = null,
        bool              $assertive = false,
    ) : ?string;

    /**
     * @param string|Stringable $path
     * @param null|string       $relativeTo
     * @param bool              $assertive
     *
     * @return ($assertive is true ? string : null|string)
     */
    public function get(
        string|Stringable $path,
        ?string           $relativeTo = null,
        bool              $assertive = false,
    ) : ?string;

    /**
     * @param string|Stringable $path
     * @param null|string       $relativeTo
     * @param bool              $assertive
     *
     * @return ($assertive is true ? FileInfo : null|FileInfo)
     */
    public function getFileInfo(
        string|Stringable $path,
        ?string           $relativeTo = null,
        bool              $assertive = false,
    ) : ?FileInfo;
}
