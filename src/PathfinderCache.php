<?php

declare(strict_types=1);

namespace Core;

use Cache\LocalStorage;
use Core\Symfony\DependencyInjection\Autodiscover;
use Stringable;
use Support\Normalize;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[Autodiscover(
    tag      : ['monolog.logger' => ['channel' => 'pathfinder']],
    lazy     : false,
    public   : false,
    shared   : true,
    autowire : true,
)]
final readonly class PathfinderCache
{
    private LocalStorage $storage;

    public function __construct(
        #[Autowire( param : 'path.pathfinder_cache' )]
        string $storagePath,
        bool   $validate = true,
        bool   $autosave = false,
    ) {
        $this->storage = new LocalStorage(
            filePath  : $storagePath,
            name      : 'pathfinder.cache',
            generator : Pathfinder::class,
            autosave  : $autosave,
            validate  : $validate,
        );
    }

    /**
     * @param string    $key
     * @param ?callable $callback
     *
     * @return null|string
     */
    public function get( string $key, ?callable $callback = null ) : ?string
    {
        $value = $this->storage->get( $key, $callback );

        return \is_string( $value ) ? $value : null;
    }

    public function set( string $key, string $value ) : void
    {
        $this->storage->set( $key, $value );
    }

    public function delete( string $key ) : void
    {
        $this->storage->delete( $key );
    }

    public function commit() : void
    {
        $this->storage->save();
    }

    public static function key( null|string|bool|int|Stringable ...$from ) : string
    {
        return \hash( 'xxh3', \implode( '', $from ) );
    }

    /**
     * @param array<string,string>|ParameterBagInterface|string ...$parameters
     *
     * @return array<string, string>
     */
    public static function precompile( array|ParameterBagInterface|string ...$parameters ) : array
    {
        $precompiled = [];

        $parse = [];

        foreach ( $parameters as $parameter ) {
            if ( \is_string( $parameter ) ) {
                $parse[] = $parameter;
            }
            elseif ( \is_array( $parameter ) ) {
                $parse = \array_merge( $parse, $parameter );
            }
            elseif ( $parameter instanceof ParameterBagInterface ) {
                $parse = \array_merge( $parse, $parameter->all() );
            }
        }

        foreach ( $parse as $parameterKey => $parameterValue ) {
            if ( ! \is_string( $parameterValue ) ) {
                continue;
            }
            $precompiled[$parameterKey] = Normalize::path( $parameterValue );
        }

        return $precompiled;
    }
}
