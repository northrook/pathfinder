<?php

declare(strict_types=1);

namespace Northrook\Tests\Pathfinder;

use Northrook\Core\Pathfinder;
use Northrook\Tests\PathfinderTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(Pathfinder::class)]
final class PathCharactersTest extends PathfinderTestCase
{
    #[DataProvider('invalidParameterKeyProvider')]
    public function testParameterKeysRejectUnusualCharacters(string $key): void
    {
        self::assertFalse(Pathfinder::validKey($key));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidParameterKeyProvider(): iterable
    {
        yield 'apostrophe' => ["path.key'"];
        yield 'space' => ['path key'];
        yield 'unicode' => ['path.über'];
        yield 'parentheses' => ['path.(key)'];
        yield 'ampersand' => ['path.key&more'];
        yield 'dollar' => ['path.key$'];
        yield 'comma' => ['path.key,part'];
    }

    #[DataProvider('unusualPathSegmentProvider')]
    public function testPathValuesResolveWithUnusualCharacters(string $segment): void
    {
        $directory = $this->workspacePath($segment);
        $file      = $directory . DIR_SEP . 'target.txt';
        $this->filesystem->createDirectory($directory);
        $this->filesystem->writeFileAtomically($file, 'ok');

        $pathfinder = $this->createPathfinder(['path.target' => $directory]);

        self::assertSame($file, $pathfinder->getPath('path.target/target.txt'));
        self::assertSame($file, (string) $pathfinder->path('path.target/target.txt'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusualPathSegmentProvider(): iterable
    {
        yield 'apostrophe' => ["dir'with-quote"];
        yield 'spaces' => ['dir with spaces'];
        yield 'parentheses' => ['dir (1)'];
        yield 'ampersand' => ['save&load'];
        yield 'dollar' => ['$HOME-ish'];
        yield 'comma' => ['one,two'];
        yield 'at-sign' => ['user@host'];
        yield 'hash' => ['file#1'];
        yield 'plus' => ['a+b'];
        yield 'equals' => ['a=b'];
        yield 'semicolon' => ['a;b'];
        yield 'unicode' => ['café-résumé'];
    }

    public function testUnusualPathValuesSurviveCacheRoundTrip(): void
    {
        $segments = ["dir'quote", 'dir with spaces', 'dir (parens)'];
        $parameters = [];
        $expected = [];
        $keys = ['path.alpha', 'path.beta', 'path.gamma'];

        foreach ($segments as $index => $segment) {
            $directory = $this->workspacePath($segment);
            $file = $directory . DIR_SEP . 'file.txt';
            $this->filesystem->createDirectory($directory);
            $this->filesystem->writeFileAtomically($file, 'content');

            $parameters[$keys[$index]] = $directory;
            $expected[$keys[$index] . '/file.txt'] = $file;
        }

        $cacheFile = $this->cacheFilePath('unusual-chars.cache');
        $pathfinder = $this->createPathfinder($parameters, cacheFile: $cacheFile);

        foreach ($expected as $query => $resolved) {
            self::assertSame($resolved, $pathfinder->getPath($query));
        }

        self::assertTrue($pathfinder->commitCache(force: true));

        $reloaded = $this->reloadPathfinder($parameters, $cacheFile);

        foreach ($expected as $query => $resolved) {
            self::assertSame($resolved, $reloaded->getPath($query));
        }
    }
}
