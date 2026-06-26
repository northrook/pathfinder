<?php

declare(strict_types=1);

namespace Northrook\Tests\Pathfinder;

use InvalidArgumentException;
use Northrook\Tests\PathfinderTestCase;
use Northrook\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\Northrook\Core\Pathfinder::class)]
final class GetParameterTest extends PathfinderTestCase
{
    public function testReturnsNormalizedExistingPath(): void
    {
        $pathfinder = $this->createPathfinder();

        self::assertSame($this->fixtureRoot, $pathfinder->getParameter('path.root'));
    }

    public function testMissingKeyReturnsNullAndLogsWarning(): void
    {
        $logger     = new RecordingLogger();
        $pathfinder = $this->createPathfinder();
        $this->injectLogger($pathfinder, $logger);

        self::assertNull($pathfinder->getParameter('path.missing'));
        $this->assertLogContains($logger, 'warning', 'No value for');
    }

    public function testEmptyValueReturnsNullAndLogsWarning(): void
    {
        $logger     = new RecordingLogger();
        $pathfinder = $this->createPathfinder(['path.empty' => '']);
        $this->injectLogger($pathfinder, $logger);

        self::assertNull($pathfinder->getParameter('path.empty'));
        $this->assertLogContains($logger, 'warning', 'No value for');
    }

    public function testNonPathLikeValueReturnsNullAndLogsWarning(): void
    {
        $logger     = new RecordingLogger();
        $pathfinder = $this->createPathfinder(['path.text' => 'not-a-path']);
        $this->injectLogger($pathfinder, $logger);

        self::assertNull($pathfinder->getParameter('path.text'));
        $this->assertLogContains($logger, 'warning', 'not path-like');
    }

    public function testInvalidKeyThrows(): void
    {
        $pathfinder = $this->createPathfinder();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid parameter key 'bad!key'.");

        $pathfinder->getParameter('bad!key');
    }

    public function testCachesOnlyWhenPathExistsOnDisk(): void
    {
        $existing = $this->mkdir('exists');
        $missing  = $this->workspacePath('does-not-exist');

        $pathfinder = $this->createPathfinder([
            'path.exists'  => $existing,
            'path.missing' => $missing,
        ]);

        $pathfinder->getParameter('path.exists');
        $pathfinder->getParameter('path.missing');

        self::assertSame(1, $pathfinder->count());
    }

    public function testDeeplyChainedNestedParameters(): void
    {
        $pathfinder = $this->createPathfinder([
            'path.a' => '%path.b%/src',
            'path.b' => '%path.root%',
        ]);

        self::assertSame($this->fixtureRoot . '/src', $pathfinder->getParameter('path.a'));
    }

    public function testSelfReferencingPlaceholderThrows(): void
    {
        $pathfinder = $this->createPathfinder([
            'path.loop' => '%path.loop%/src',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Circular parameter reference');

        $pathfinder->getParameter('path.loop');
    }

    public function testMutualParameterReferenceThrows(): void
    {
        $pathfinder = $this->createPathfinder([
            'path.a' => '%path.b%/src',
            'path.b' => '%path.a%/var',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Circular parameter reference');

        $pathfinder->getParameter('path.a');
    }
}
