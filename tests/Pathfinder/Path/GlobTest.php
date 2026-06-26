<?php

declare(strict_types=1);

namespace Northrook\Tests\Pathfinder\Path;

use InvalidArgumentException;
use Northrook\Core\Pathfinder\Path;
use Northrook\Tests\PathfinderTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Path::class)]
final class GlobTest extends PathfinderTestCase
{
    public function testGlobFindsSinglePattern(): void
    {
        $path = Path::from($this->fixtureRoot . '/src', $this->filesystem);
        $matches = $path->glob('Example.php');

        self::assertCount(1, $matches);
        self::assertStringEndsWith('/Example.php', $matches[0]->getPathname());
    }

    public function testGlobAcceptsArrayOfPatterns(): void
    {
        $path = Path::from($this->fixtureRoot . '/src', $this->filesystem);
        $matches = $path->glob(['Example.php', 'missing.php']);

        self::assertCount(1, $matches);
    }

    public function testGlobReturnsEmptyForNoMatches(): void
    {
        $path = Path::from($this->fixtureRoot . '/src', $this->filesystem);

        self::assertSame([], $path->glob('nothing.here'));
    }

    public function testGlobOnUrlThrows(): void
    {
        $path = Path::from('https://example.com/dir', $this->filesystem);

        $this->expectException(InvalidArgumentException::class);

        $path->glob('*.php');
    }
}
