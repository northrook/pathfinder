<?php

declare(strict_types=1);

namespace Northrook\Tests\Pathfinder;

use Northrook\Core\Pathfinder;
use Northrook\Tests\PathfinderTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Pathfinder::class)]
final class ValidKeyTest extends PathfinderTestCase
{
    #[DataProvider('validKeyProvider')]
    public function testValidKey(string $key, bool $expected): void
    {
        self::assertSame($expected, Pathfinder::validKey($key));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function validKeyProvider(): iterable
    {
        yield 'simple key' => ['path.root', true];
        yield 'key with suffix segment' => ['path.root/src', true];
        yield 'backslash namespace key' => ['Vendor\\Package\\Class::name', true];
        yield 'hyphen in segment' => ['path.my-key', true];
        yield 'underscore in segment' => ['path.my_key', true];
        yield 'colon in segment' => ['path.cache:item', true];
        yield 'percent in segment' => ['path.pa%th', true];
        yield 'digit in segment' => ['path.segment0', true];

        yield 'single segment without separator' => ['pathroot', true];
        yield 'leading dot' => ['.path.root', false];
        yield 'trailing dot' => ['path.root.', false];
        yield 'double dot' => ['path..root', false];
        yield 'exclamation' => ['path.root!', false];
        yield 'apostrophe' => ["path.key'", false];
        yield 'space' => ['path key', false];
        yield 'unicode' => ['path.über', false];
        yield 'parentheses' => ['path.(key)', false];
        yield 'ampersand' => ['path.key&more', false];
        yield 'dollar' => ['path.key$', false];
        yield 'comma' => ['path.key,part', false];
        yield 'leading digit' => ['0path.root', false];
    }
}
