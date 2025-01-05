<?php

// -------------------------------------------------------------------
// config\pathfinder
// -------------------------------------------------------------------

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Core\{Pathfinder, PathfinderCache};

return static function( ContainerConfigurator $container ) : void {
    $container->services()
        ->set( PathfinderCache::class )
        ->args(
            [
                '%kernel.cache_dir%/pathfinder.cache', // $storagePath,
                PathfinderCache::class,                // $name = null,
                false,                // $readonly = false,
                true,                // $autosave = true,
                service( 'logger' ), // $logger = null,
            ],
        )
        ->tag( 'monolog.logger', ['channel' => 'pathfinder.cache'] )
            //
            // Find and return registered paths
        ->set( Pathfinder::class )
        ->args(
            [
                /** Ideally replaced during a Compiler Pass, using {@see PathfinderCache::precompile()} */
                [],
                service( 'parameter_bag' ),  // $parameterBag
                service( PathfinderCache::class ),                    // $cache
                service( 'logger' ),
                // $logger
            ],
        )
        ->tag( 'monolog.logger', ['channel' => 'pathfinder'] );
};
