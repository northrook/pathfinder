<?php

declare(strict_types=1);

namespace Northrook\Tests\Pathfinder\Path;

use Northrook\Core\Pathfinder\Path;
use Northrook\Tests\PathfinderTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Path::class)]
final class IntrospectionTest extends PathfinderTestCase
{
    public function testIsFileAndIsDirectory(): void
    {
        $file = Path::from($this->fixtureRoot . '/src/Example.php', $this->filesystem);
        $dir = Path::from($this->fixtureRoot . '/src', $this->filesystem);

        self::assertTrue($file->isFile());
        self::assertFalse($file->isDirectory());
        self::assertTrue($dir->isDirectory());
        self::assertFalse($dir->isFile());
    }

    public function testIsPathAndIsReadable(): void
    {
        $path = Path::from($this->fixtureRoot . '/src/Example.php', $this->filesystem);

        self::assertTrue($path->isPath());
        self::assertTrue($path->isReadable());
    }

    public function testIsDotPathFileAndDirectory(): void
    {
        $dotDir = $this->mkdir('parent/.hidden');
        $dotFile = $this->touch('parent/.hidden/.secret', 'hidden');

        $file = Path::from($dotFile, $this->filesystem);
        $dir  = Path::from($dotDir, $this->filesystem);

        self::assertTrue($file->isDotPath());
        self::assertTrue($file->isDotFile());
        self::assertFalse($file->isDotDirectory());

        self::assertTrue($dir->isDotPath());
        self::assertFalse($dir->isDotFile());
        self::assertTrue($dir->isDotDirectory());
    }

    public function testIsDotPathDoesNotRequirePathToExist(): void
    {
        $path = Path::from($this->workspacePath('not-yet-created/.secret'), $this->filesystem);

        self::assertTrue($path->isDotPath());
        self::assertFalse($path->isDotFile());
        self::assertFalse($path->isDotDirectory());
    }

    public function testIsRelative(): void
    {
        $absolute = Path::from($this->fixtureRoot . '/src/Example.php', $this->filesystem);
        $relative = Path::from('src/Example.php', $this->filesystem);

        self::assertFalse($absolute->isRelative());
        self::assertTrue($relative->isRelative());
    }

    public function testGetRealPathResolvesSymlink(): void
    {
        $link = $this->fixtureRoot . '/src/link.php';

        if (! $this->filesystem->pathsExist($link)) {
            self::markTestSkipped('Symlink fixture is not available.');
        }

        $path = Path::from($link, $this->filesystem);
        $real = $path->getRealPath();

        self::assertStringEndsWith('/Example.php', $real);
        self::assertSame($real, (string) $path);
    }

    public function testGetRealPathFalseOnErrorForMissingPath(): void
    {
        $path = Path::from($this->workspacePath('missing/file.txt'), $this->filesystem);

        self::assertFalse($path->getRealPath(falseOnError: true));
    }

    public function testAccessors(): void
    {
        $path = Path::from($this->fixtureRoot . '/src/Example.php', $this->filesystem);

        self::assertStringEndsWith('/src/Example.php', $path->getPathname());
        self::assertStringEndsWith('/src', $path->getPath());
        self::assertSame('Example', $path->getFilename());
        self::assertSame('php', $path->getExtension());
        self::assertInstanceOf(\SplFileInfo::class, $path->getSplFileInfo());
    }
}
