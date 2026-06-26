<?php

declare(strict_types=1);

namespace Northrook\Tests\Pathfinder;

use InvalidArgumentException;
use Northrook\Tests\PathfinderTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\Northrook\Core\Pathfinder::class)]
final class InvokeAndNullableTest extends PathfinderTestCase
{
    public function testInvokeMirrorsGetPathSuccess(): void
    {
        $pathfinder = $this->createPathfinder();
        $expected   = $this->fixtureRoot . '/src/Example.php';

        self::assertSame($expected, $pathfinder('path.root/src/Example.php'));
    }

    public function testInvokeReturnsNullWhenNullable(): void
    {
        $pathfinder = $this->createPathfinder();

        self::assertNull($pathfinder('path.missing/file', nullable: true));
    }

    public function testInvokeThrowsWhenNotNullable(): void
    {
        $pathfinder = $this->createPathfinder();

        $this->expectException(InvalidArgumentException::class);

        $pathfinder('path.missing/file');
    }

    public function testPathNullableReturnsNull(): void
    {
        $pathfinder = $this->createPathfinder();

        self::assertNull($pathfinder->path('path.missing/file', nullable: true));
    }

    public function testHasParameterTrueForEmptyValue(): void
    {
        $pathfinder = $this->createPathfinder(['path.empty' => '']);

        self::assertTrue($pathfinder->hasParameter('path.empty'));
        self::assertFalse($pathfinder->hasParameter('path.missing'));
    }
}
