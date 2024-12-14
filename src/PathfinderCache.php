<?php

namespace Core;

use Northrook\ArrayStore;
use Support\Normalize;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * @template TKey of array-key
 * @template TValue as mixed|array<TKey,TValue>
 *
 * @extends ArrayStore<TKey,TValue>
 */
final class PathfinderCache extends ArrayStore
{
    /**
     * @param array<string,string>|ParameterBagInterface|string ...$parameters
     *
     * @return array<string, string>
     */
    public static function precompile( array|ParameterBagInterface|string ...$parameters ) : array
    {
        $precompiled = [];

        $parse = [];

        foreach ( $parameters as $index => $parameter ) {
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
