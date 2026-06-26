<?php

declare(strict_types=1);

namespace Northrook\Tests\Pathfinder;

use Northrook\Contracts\Exceptions\FilesystemException;
use Northrook\Contracts\Interfaces\FilesystemInterface;
use Northrook\Core\Filesystem;
use Northrook\Core\Pathfinder;
use Northrook\Tests\PathfinderTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Pathfinder::class)]
final class CacheTest extends PathfinderTestCase
{
    public function testRoundTripPersistsAndReloads(): void
    {
        $cacheFile = $this->cacheFilePath();
        $pathfinder = $this->createPathfinder(cacheFile: $cacheFile);
        $expected = $this->fixtureRoot . '/src/Example.php';

        $pathfinder->getPath('path.root/src/Example.php');
        self::assertTrue($pathfinder->commitCache());

        $reloaded = $this->reloadPathfinder([], $cacheFile);

        self::assertSame($expected, $reloaded->getPath('path.root/src/Example.php'));
    }

    public function testPreloadsPersistedCacheWithEmptyParameters(): void
    {
        $cacheFile = $this->cacheFilePath('preload.cache');
        $quotedDir = $this->workspacePath("dir'with-quote/nested");
        $this->filesystem->createDirectory($quotedDir);
        $this->filesystem->writeFileAtomically($quotedDir . '/file.php', '<?php');

        $parameters = [
            'path.root' => $this->fixtureRoot,
            'path.nested' => '%path.root%/src',
            'path.quoted' => $quotedDir,
        ];

        $writer = $this->createPathfinder($parameters, cacheFile: $cacheFile);
        $writer->getPath('path.root/src/Example.php');
        $writer->getPath('path.nested/Example.php');
        $writer->getPath('path.quoted/file.php');
        $writer->getPath('path.root/src/Example.php', 'path.root');
        self::assertTrue($writer->commitCache(force: true));

        $pathfinder = new Pathfinder([], $cacheFile, $this->filesystem);

        self::assertGreaterThan(0, $pathfinder->count());
        self::assertSame($this->fixtureRoot . '/src/Example.php', $pathfinder->getPath('path.root/src/Example.php'));
        self::assertSame($this->fixtureRoot . '/src/Example.php', $pathfinder->getPath('path.nested/Example.php'));
        self::assertSame($quotedDir . '/file.php', $pathfinder->getPath('path.quoted/file.php'));
        self::assertSame('/src/Example.php', $pathfinder->getPath('path.root/src/Example.php', 'path.root'));
    }

    public function testCacheFileStructure(): void
    {
        $cacheFile = $this->cacheFilePath('structure.cache');
        $pathfinder = $this->createPathfinder(cacheFile: $cacheFile);
        $pathfinder->getPath('path.root/src/Example.php');
        $pathfinder->commitCache();

        $contents = $this->filesystem->readFile($cacheFile);
        self::assertStringStartsWith('<?php', $contents);
        self::assertStringNotContainsString("\t", $contents);

        [$hash, $data] = $this->readCacheFile($cacheFile);
        self::assertNotSame('', $hash);
        self::assertArrayHasKey('path.root/src/Example.php', $data);
    }

    public function testCountReflectsInMemoryAndFileBackedEntries(): void
    {
        $cacheFile = $this->cacheFilePath('count.cache');
        $pathfinder = $this->createPathfinder(cacheFile: $cacheFile);

        self::assertSame(0, $pathfinder->count());

        $pathfinder->getPath('path.root/src/Example.php');
        self::assertSame(2, $pathfinder->count());

        $pathfinder->commitCache();
        $reloaded = $this->reloadPathfinder([], $cacheFile);
        self::assertSame(2, $reloaded->count());
    }

    public function testClearCacheEmptiesFileBackedButKeepsInMemory(): void
    {
        $cacheFile = $this->cacheFilePath('clear.cache');
        $pathfinder = $this->createPathfinder(cacheFile: $cacheFile);
        $pathfinder->getPath('path.root/src/Example.php');
        $pathfinder->commitCache();

        $reloaded = $this->reloadPathfinder(['path.root' => $this->fixtureRoot], $cacheFile);
        self::assertSame(2, $reloaded->count());

        $reloaded->clearCache();
        self::assertSame(0, $reloaded->count());

        self::assertSame(
            $this->fixtureRoot . '/src/Example.php',
            $reloaded->getPath('path.root/src/Example.php'),
        );
        self::assertSame(2, $reloaded->count());
    }

    public function testPruneCacheRemovesStaleFileBackedEntries(): void
    {
        $file = $this->touch('prune.txt', 'content');
        $cacheFile = $this->cacheFilePath('prune.cache');

        $pathfinder = $this->createPathfinder(['path.file' => $file], cacheFile: $cacheFile);
        $pathfinder->getPath('path.file');
        $pathfinder->commitCache();

        $this->filesystem->remove($file);

        $reloaded = $this->reloadPathfinder(['path.file' => $file], $cacheFile);
        self::assertSame(1, $reloaded->count());

        $reloaded->pruneCache();
        self::assertSame(0, $reloaded->count());
    }

    public function testCommitCacheReturnsFalseWhenDisabled(): void
    {
        $pathfinder = $this->createPathfinder();
        $pathfinder->getPath('path.root/src/Example.php');

        self::assertFalse($pathfinder->commitCache());
    }

    public function testCommitCacheReturnsFalseWhenEmpty(): void
    {
        $pathfinder = $this->createPathfinder(cacheFile: $this->cacheFilePath('empty.cache'));

        self::assertFalse($pathfinder->commitCache());
    }

    public function testCommitCacheReturnsFalseWhenHashUnchanged(): void
    {
        $cacheFile = $this->cacheFilePath('unchanged.cache');
        $pathfinder = $this->createPathfinder(cacheFile: $cacheFile);
        $pathfinder->getPath('path.root/src/Example.php');

        self::assertTrue($pathfinder->commitCache());
        self::assertFalse($pathfinder->commitCache());
    }

    public function testCommitCacheForceRewritesEvenWhenHashUnchanged(): void
    {
        $cacheFile = $this->cacheFilePath('force.cache');
        $pathfinder = $this->createPathfinder(cacheFile: $cacheFile);
        $pathfinder->getPath('path.root/src/Example.php');
        $pathfinder->commitCache();

        self::assertTrue($pathfinder->commitCache(force: true));
    }

    public function testCommitCacheWritesToAlternateFile(): void
    {
        $primary = $this->cacheFilePath('primary.cache');
        $alternate = $this->cacheFilePath('alternate.cache');
        $pathfinder = $this->createPathfinder(cacheFile: $primary);
        $pathfinder->getPath('path.root/src/Example.php');

        self::assertTrue($pathfinder->commitCache(cacheFile: $alternate));
        self::assertTrue($this->filesystem->pathsExist($alternate));
        self::assertFalse($this->filesystem->pathsExist($primary));
    }

    public function testDestructorPersistsCache(): void
    {
        $cacheFile = $this->cacheFilePath('destruct.cache');
        $pathfinder = $this->createPathfinder(cacheFile: $cacheFile);
        $pathfinder->getPath('path.root/src/Example.php');
        unset($pathfinder);

        self::assertTrue($this->filesystem->pathsExist($cacheFile));

        $reloaded = $this->reloadPathfinder([], $cacheFile);
        self::assertSame(
            $this->fixtureRoot . '/src/Example.php',
            $reloaded->getPath('path.root/src/Example.php'),
        );
    }

    public function testCommitCacheReturnsFalseOnFilesystemException(): void
    {
        $cacheFile = $this->cacheFilePath('fail.cache');
        $real = new Filesystem();
        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->method('pathsExist')->willReturnCallback(
            static fn (string ...$paths): bool => $real->pathsExist(...$paths),
        );
        $filesystem->method('createParentDirectory')->willReturnCallback(
            static function (string|iterable $paths, int $mode = 0777) use ($real): void {
                $real->createParentDirectory($paths, $mode);
            },
        );
        $filesystem->method('writeFileAtomically')->willThrowException(
            new FilesystemException('write failed'),
        );

        $pathfinder = new Pathfinder(['path.root' => $this->fixtureRoot], $cacheFile, $filesystem);
        $pathfinder->getPath('path.root/src/Example.php');

        self::assertFalse($pathfinder->commitCache());
    }

    public function testConstructorLoadsMissingCacheFileAsEmpty(): void
    {
        $cacheFile = $this->cacheFilePath('missing.cache');
        $pathfinder = new Pathfinder([], $cacheFile, $this->filesystem);

        self::assertSame(0, $pathfinder->count());
    }
}
