<?php

declare(strict_types=1);

namespace Northrook\Tests\Pathfinder\Path;

use InvalidArgumentException;
use Northrook\Core\Pathfinder\Path;
use Northrook\Tests\PathfinderTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Path::class)]
final class UrlTest extends PathfinderTestCase
{
    public function testConstructorParsesExistingQuery(): void
    {
        $path = Path::from('https://example.com/page?foo=bar', $this->filesystem);

        self::assertTrue($path->isUrl());
        self::assertStringContainsString('foo=bar', $path->getPathname());
    }

    public function testAppendMergesQueryParameters(): void
    {
        $path = Path::from('https://example.com/page?foo=bar', $this->filesystem)
            ->append('?baz=1');

        self::assertStringContainsString('foo=bar', $path->getPathname());
        self::assertStringContainsString('baz=1', $path->getPathname());
    }

    public function testAppendOverridesExistingQueryKeys(): void
    {
        $path = Path::from('https://example.com/page?foo=bar', $this->filesystem)
            ->append('?foo=override');

        self::assertStringContainsString('foo=override', $path->getPathname());
        self::assertStringNotContainsString('foo=bar', $path->getPathname());
    }

    public function testToStringReturnsPathnameForUrls(): void
    {
        $url = 'https://example.com/assets/app.js';
        $path = Path::from($url, $this->filesystem);

        self::assertSame($path->getPathname(), (string) $path);
    }

    public function testAppendQueryToLocalPathThrows(): void
    {
        $path = Path::from($this->fixtureRoot, $this->filesystem);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Query parameters can only be appended to URLs');

        $path->append('?x=1');
    }

    public function testFilesystemMethodsOnUrlThrow(): void
    {
        $path = Path::from('https://example.com/file.txt', $this->filesystem);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not support remote URLs');

        $path->exists();
    }
}
