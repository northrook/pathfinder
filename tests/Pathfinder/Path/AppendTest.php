<?php

declare(strict_types=1);

namespace Northrook\Tests\Pathfinder\Path;

use Northrook\Core\Pathfinder\Path;
use Northrook\Tests\PathfinderTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use ValueError;

#[CoversClass(Path::class)]
final class AppendTest extends PathfinderTestCase
{
    public function testAppendAddsDirectorySegment(): void
    {
        $path = Path::from($this->fixtureRoot, $this->filesystem)->append('/nested/file.php');

        self::assertStringEndsWith('/nested/file.php', $path->getPathname());
    }

    public function testAppendToExistingFileThrowsValueError(): void
    {
        $file = $this->touch('file.txt', 'content');
        $path = Path::from($file, $this->filesystem);

        $this->expectException(ValueError::class);

        $path->append('/other.php');
    }

    public function testAppendAddsUrlPathSegment(): void
    {
        $path = Path::from('https://example.com/api', $this->filesystem)->append('/v1/users');

        self::assertSame('https://example.com/api/v1/users', $path->getPathname());
    }
}
