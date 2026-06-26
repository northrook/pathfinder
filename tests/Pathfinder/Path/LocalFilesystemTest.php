<?php

declare(strict_types=1);

namespace Northrook\Tests\Pathfinder\Path;

use InvalidArgumentException;
use Northrook\Contracts\Exceptions\FilesystemException;
use Northrook\Contracts\Interfaces\FilesystemInterface;
use Northrook\Core\Pathfinder\Path;
use Northrook\Tests\PathfinderTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

#[CoversClass(Path::class)]
final class LocalFilesystemTest extends PathfinderTestCase
{
    public function testSaveAndGetContentsRoundTrip(): void
    {
        $target = $this->workspacePath('written.txt');
        $path = Path::from($target, $this->filesystem);

        self::assertTrue($path->save('hello'));
        self::assertSame('hello', $path->getContents());
    }

    public function testSaveWithoutCreatingDirectoriesUsesFilesystemBehavior(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->method('isFile')->willReturn(false);
        $filesystem->method('pathsExist')->willReturn(false);
        $filesystem->method('writeFileAtomically')->willThrowException(new FilesystemException('missing parent'));

        $target = $this->workspacePath('deep/nested/file.txt');
        $path = Path::from($target, $filesystem);

        self::assertFalse($path->save('hello', makeRequiredDirectories: false));
    }

    public function testMkdirCreatesParentDirectory(): void
    {
        $target = $this->workspacePath('new-dir/file.txt');
        $path = Path::from($target, $this->filesystem);

        self::assertTrue($path->mkdir());
        self::assertTrue($this->filesystem->isDirectory(\dirname($target)));
    }

    public function testMkdirIsIdempotentWhenParentExists(): void
    {
        $dir = $this->mkdir('existing');
        $path = Path::from($dir . '/file.txt', $this->filesystem);

        self::assertTrue($path->mkdir());
    }

    public function testCopyCreatesTargetFile(): void
    {
        $source = $this->touch('source.txt', 'payload');
        $target = $this->workspacePath('copied.txt');
        $path = Path::from($source, $this->filesystem);

        self::assertTrue($path->copy($target));
        self::assertSame('payload', $this->filesystem->readFile($target));
    }

    public function testCopyWithAlwaysOverwrite(): void
    {
        $source = $this->touch('source.txt', 'new');
        $target = $this->touch('target.txt', 'old');
        $path = Path::from($source, $this->filesystem);

        self::assertTrue($path->copy($target, alwaysOverwrite: true));
        self::assertSame('new', $this->filesystem->readFile($target));
    }

    public function testRemoveDeletesFile(): void
    {
        $file = $this->touch('remove-me.txt', 'x');
        $path = Path::from($file, $this->filesystem);

        $path->remove();

        self::assertFalse($this->filesystem->pathsExist($file));
    }

    public function testExistsReturnsFalseForMissingPath(): void
    {
        $path = Path::from($this->workspacePath('missing.txt'), $this->filesystem);

        self::assertFalse($path->exists());
    }

    public function testExistsThrowsOnError(): void
    {
        $path = Path::from($this->workspacePath('missing.txt'), $this->filesystem);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read file');

        $path->exists(throwOnError: true);
    }

    public function testGetContentsThrowsOnReadFailure(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->method('readFile')->willThrowException(new FilesystemException('read failed'));

        $path = Path::from($this->workspacePath('unreadable.txt'), $filesystem);

        $this->expectException(RuntimeException::class);

        $path->getContents(throwOnError: true);
    }

    public function testSaveReturnsFalseOnFilesystemException(): void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->method('isFile')->willReturn(false);
        $filesystem->method('pathsExist')->willReturn(true);
        $filesystem->method('createDirectory');
        $filesystem->method('writeFileAtomically')->willThrowException(new FilesystemException('write failed'));

        $path = Path::from($this->workspacePath('fail.txt'), $filesystem);

        self::assertFalse($path->save('data'));
    }

    public function testFilesystemOperationsOnUrlThrow(): void
    {
        $path = Path::from('https://example.com/file.txt', $this->filesystem);

        $this->expectException(InvalidArgumentException::class);

        $path->save('data');
    }
}
