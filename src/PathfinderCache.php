<?php

namespace Core;

use Northrook\ArrayStore;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * @template TKey of array-key
 * @template TValue as mixed|array<TKey,TValue>
 *
 * @extends ArrayStore<TKey,TValue>
 */
final class PathfinderCache extends ArrayStore
{

    public static function precompile( array | ParameterBagInterface ...$parameters ) : array
    {
        $precompiled = [];

        foreach ( $parameters as $index => $parameter ) {
            if ( $parameter instanceof ParameterBagInterface ) {
                $parameters[ $index ] = $parameter->all();
            }
        }

        dump( $parameters );

        return $precompiled;
    }
}
