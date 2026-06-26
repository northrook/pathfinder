<?php

declare(strict_types=1);

namespace Northrook\Tests\Pathfinder;

use Northrook\Core\Pathfinder\Path;
use Northrook\Tests\PathfinderTestCase;
use Northrook\Tests\Support\StringableValue;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\Northrook\Core\Pathfinder::class)]
final class ResolutionTest extends PathfinderTestCase
{
    public function testResolvesParameterPathWithSuffix(): void
    {
        $pathfinder = $this->createPathfinder();

        self::assertSame(
            $this->fixtureRoot . '/src/Example.php',
            $pathfinder->getPath('path.root/src/Example.php'),
        );
    }

    public function testResolvesParameterKeyWithoutSuffix(): void
    {
        $pathfinder = $this->createPathfinder();

        self::assertSame(
            $this->fixtureRoot,
            $pathfinder->getPath('path.root'),
        );
    }

    public function testResolvesBackslashParameterKey(): void
    {
        $dir = $this->mkdir('vendor-pkg');
        $file = $this->touch('vendor-pkg/method.php');

        $pathfinder = $this->createPathfinder([
            'Vendor\\Pkg\\Class::method' => $dir,
        ]);

        self::assertSame($file, $pathfinder->getPath('Vendor\\Pkg\\Class::method/method.php'));
    }

    public function testTreatsInvalidKeyLookupAsLiteralPath(): void
    {
        $literal = $this->touch('literal.txt', 'x');
        $pathfinder = $this->createPathfinder();

        self::assertSame($literal, $pathfinder->getPath($literal));
    }

    public function testReturnsNonExistentResolvedPathWithoutCaching(): void
    {
        $missing = $this->workspacePath('missing/file.txt');
        $pathfinder = $this->createPathfinder(['path.missing' => $this->workspacePath('missing')]);

        self::assertSame($missing, $pathfinder->getPath('path.missing/file.txt'));
        self::assertSame(0, $pathfinder->count());
    }

    public function testGlobWildcardShortCircuitsExistenceCheck(): void
    {
        $pathfinder = $this->createPathfinder(['path.glob' => $this->workspacePath('files')]);
        $expected = $this->workspacePath('files') . '/*.txt';

        self::assertSame($expected, $pathfinder->getPath('path.glob/*.txt'));
        self::assertSame(0, $pathfinder->count());
    }

    public function testAcceptsStringableArguments(): void
    {
        $pathfinder = $this->createPathfinder();
        $lookup     = new StringableValue('path.root/src/Example.php');
        $expected   = $this->fixtureRoot . '/src/Example.php';

        self::assertSame($expected, $pathfinder->getPath($lookup));
        self::assertSame($expected, (string) $pathfinder->path($lookup));
        self::assertNotEmpty($pathfinder->getUrl($lookup));
    }

    public function testPathReturnsPathObjectWithSharedFilesystem(): void
    {
        $pathfinder = $this->createPathfinder();
        $path       = $pathfinder->path('path.root/src/Example.php');

        self::assertInstanceOf(Path::class, $path);
        self::assertSame(
            $pathfinder->getPath('path.root/src/Example.php'),
            (string) $path,
        );
    }
}
