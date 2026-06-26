<?php

declare(strict_types=1);

namespace Northrook\Tests\Pathfinder;

use InvalidArgumentException;
use Northrook\Tests\PathfinderTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use function Northrook\Core\normalize_url;

#[CoversClass(\Northrook\Core\Pathfinder::class)]
final class GetUrlTest extends PathfinderTestCase
{
    public function testResolvesFilePathToNormalizedUrl(): void
    {
        $pathfinder = $this->createPathfinder();
        $resolved   = $pathfinder->getPath('path.root/src/Example.php');

        self::assertSame(normalize_url($resolved), $pathfinder->getUrl('path.root/src/Example.php'));
    }

    public function testGetUrlAppliesNormalizeUrlToResolvedPath(): void
    {
        $url = 'https://example.com/assets/logo.png';
        $pathfinder = $this->createPathfinder(['path.assets' => $url]);

        self::assertSame(
            normalize_url($pathfinder->getPath('path.assets')),
            $pathfinder->getUrl('path.assets'),
        );
    }

    public function testNullableReturnsNullForMissingLookup(): void
    {
        $pathfinder = $this->createPathfinder();

        self::assertNull($pathfinder->getUrl('path.missing/file', nullable: true));
    }

    public function testThrowsWhenResolutionFailsAndNotNullable(): void
    {
        $pathfinder = $this->createPathfinder();

        $this->expectException(InvalidArgumentException::class);

        $pathfinder->getUrl('path.missing/file');
    }
}
