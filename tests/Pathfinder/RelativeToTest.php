<?php

declare(strict_types=1);

namespace Northrook\Tests\Pathfinder;

use Northrook\Tests\PathfinderTestCase;
use Northrook\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\Northrook\Core\Pathfinder::class)]
final class RelativeToTest extends PathfinderTestCase
{
    public function testStripsMatchingPrefix(): void
    {
        $pathfinder = $this->createPathfinder();

        self::assertSame(
            '/src/Example.php',
            $pathfinder->getPath('path.root/src/Example.php', 'path.root'),
        );
    }

    public function testRelativeToCanBeParameterLookup(): void
    {
        $pathfinder = $this->createPathfinder([
            'path.base' => $this->fixtureRoot,
        ]);

        self::assertSame(
            '/src/Example.php',
            $pathfinder->getPath('path.root/src/Example.php', 'path.base'),
        );
    }

    public function testMismatchReturnsFullPathAndLogsCritical(): void
    {
        $logger     = new RecordingLogger();
        $other      = $this->mkdir('other-root');
        $pathfinder = $this->createPathfinder(['path.other' => $other]);
        $this->injectLogger($pathfinder, $logger);

        $expected = $this->fixtureRoot . '/src/Example.php';

        self::assertSame(
            $expected,
            $pathfinder->getPath('path.root/src/Example.php', 'path.other'),
        );
        $this->assertLogContains($logger, 'critical', 'Relative path');
    }

    public function testRelativeToCachesEvenWhenPathDoesNotExist(): void
    {
        $missingDir = $this->workspacePath('not-created');
        $pathfinder = $this->createPathfinder(['path.base' => $missingDir]);

        $pathfinder->getPath('path.base/missing.txt', 'path.base');

        self::assertGreaterThan(0, $pathfinder->count());
    }
}
