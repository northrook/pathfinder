<?php

declare(strict_types=1);

namespace Northrook\Tests\Pathfinder;

use InvalidArgumentException;
use Northrook\Tests\PathfinderTestCase;
use Northrook\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\Northrook\Core\Pathfinder::class)]
final class NestedParameterTest extends PathfinderTestCase
{
    public function testStandardPlaceholderSubstitution(): void
    {
        $pathfinder = $this->createPathfinder([
            'path.cache' => '%path.root%/var/cache',
        ]);

        self::assertSame(
            $this->fixtureRoot . '/var/cache',
            $pathfinder->getParameter('path.cache'),
        );
    }

    public function testPlaceholderWithPathSuffixResolvesViaGetPath(): void
    {
        $pathfinder = $this->createPathfinder([
            'path.nested' => '%path.root%/src',
        ]);

        self::assertSame(
            $this->fixtureRoot . '/src/Example.php',
            $pathfinder->getPath('path.nested/Example.php'),
        );
    }

    public function testMultiplePlaceholdersThrows(): void
    {
        $alpha = $this->mkdir('alpha');
        $beta  = $this->mkdir('beta');

        $pathfinder = $this->createPathfinder([
            'path.alpha'  => $alpha,
            'path.beta'   => $beta,
            'path.joined' => '%path.alpha%%path.beta%',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most one %key% placeholder');

        $pathfinder->getParameter('path.joined');
    }

    public function testNonPathLikePercentValuesReturnNull(): void
    {
        $logger = new RecordingLogger();
        $pathfinder = $this->createPathfinder([
            'path.lone'    => '%',
            'path.percent' => '100%',
        ]);
        $this->injectLogger($pathfinder, $logger);

        self::assertNull($pathfinder->getParameter('path.lone'));
        self::assertNull($pathfinder->getParameter('path.percent'));
        $this->assertLogContains($logger, 'warning', 'not path-like');
    }

    public function testEmptyNestedPlaceholderThrows(): void
    {
        $pathfinder = $this->createPathfinder([
            'path.bad' => '%%',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid parameter key');

        $pathfinder->getParameter('path.bad');
    }

    public function testInvalidNestedKeyThrows(): void
    {
        $pathfinder = $this->createPathfinder([
            'path.bad' => '%bad key%',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid parameter key');

        $pathfinder->getParameter('path.bad');
    }

    public function testUnresolvedPlaceholderIsLeftIntact(): void
    {
        $logger     = new RecordingLogger();
        $pathfinder = $this->createPathfinder(['path.partial' => '%path.missing%/tail']);
        $this->injectLogger($pathfinder, $logger);

        self::assertSame('%path.missing%/tail', $pathfinder->getParameter('path.partial'));
        $this->assertLogContains($logger, 'warning', 'Unable to resolve parameter');
    }
}
