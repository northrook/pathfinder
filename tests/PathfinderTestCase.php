<?php

declare(strict_types=1);

namespace Northrook\Tests;

use Northrook\Core\Filesystem;
use Northrook\Core\Pathfinder;
use Northrook\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;

abstract class PathfinderTestCase extends TestCase
{
    protected Filesystem $filesystem;

    protected string $workspace;

    protected string $fixtureRoot;

    protected function setUp(): void
    {
        $this->filesystem  = new Filesystem();
        $this->workspace   = \sys_get_temp_dir() . DIR_SEP . 'northrook-pathfinder-' . \bin2hex(\random_bytes(8));
        $this->fixtureRoot = __DIR__ . '/fixtures/root';

        $this->filesystem->createDirectory($this->workspace);
    }

    protected function tearDown(): void
    {
        if ($this->filesystem->pathsExist($this->workspace)) {
            $this->filesystem->remove($this->workspace);
        }
    }

    /**
     * @param array<non-empty-string, string> $parameters
     * @param bool|non-empty-string             $cacheFile
     */
    protected function createPathfinder(
        array $parameters = [],
        bool|string $cacheFile = false,
    ): Pathfinder {
        $parameters['path.root'] ??= $this->fixtureRoot;

        return new Pathfinder($parameters, $cacheFile, $this->filesystem);
    }

    /**
     * @param array<non-empty-string, string> $parameters
     * @param non-empty-string                  $cacheFile
     */
    protected function reloadPathfinder(
        array $parameters,
        string $cacheFile,
    ): Pathfinder {
        return new Pathfinder($parameters, $cacheFile, $this->filesystem);
    }

    protected function workspacePath(string ...$segments): string
    {
        return $segments === []
            ? $this->workspace
            : $this->workspace . DIR_SEP . \implode(DIR_SEP, $segments);
    }

    protected function touch(string $relative, string $content = ''): string
    {
        $path = $this->workspacePath($relative);
        $this->filesystem->createParentDirectory($path);
        $this->filesystem->writeFileAtomically($path, $content);

        return $path;
    }

    protected function mkdir(string $relative): string
    {
        $path = $this->workspacePath($relative);
        $this->filesystem->createDirectory($path);

        return $path;
    }

    /**
     * @return array{0: string, 1: array<non-empty-string, string>}
     */
    protected function readCacheFile(string $cacheFile): array
    {
        /** @var array{0: string, 1: array<non-empty-string, string>} $loaded */
        $loaded = require $cacheFile;

        return $loaded;
    }

    protected function injectLogger(Pathfinder $pathfinder, RecordingLogger $logger): void
    {
        $pathfinder->assignLogger($logger);
    }

    protected function assertLogContains(
        RecordingLogger $logger,
        string $level,
        string $messageFragment,
    ): void {
        self::assertTrue(
            $logger->hasLevel($level, $messageFragment),
            \sprintf(
                'Expected %s log containing "%s". Entries: %s',
                $level,
                $messageFragment,
                \json_encode($logger->entries()) ?: '[]',
            ),
        );
    }

    /**
     * @return non-empty-string
     */
    protected function cacheFilePath(string $filename = 'pathfinder.cache'): string
    {
        $path = $this->workspacePath($filename);
        \assert($path !== '');

        return $path;
    }
}
