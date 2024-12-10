<?php

namespace Core;

use Support\FileInfo;

interface PathfinderInterface
{

    public function __invoke( string $path, ?string $relativeTo = null ) : ?string;

    public function get( string $path, ?string $relativeTo = null ) : ?string;

    public function getFileInfo( string $path, ?string $relativeTo = null ) : ?FileInfo;
}
