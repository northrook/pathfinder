<?php

declare(strict_types=1);

namespace Northrook\Tests\Pathfinder;

use Northrook\Tests\PathfinderTestCase;
use Northrook\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\Northrook\Core\Pathfinder::class)]
final class LoggingTest extends PathfinderTestCase
{
    public function testNoticeWhenLookupCannotBeResolved(): void
    {
        $logger = new RecordingLogger();
        $pathfinder = $this->createPathfinder();
        $this->injectLogger($pathfinder, $logger);

        $this->expectException(\InvalidArgumentException::class);

        try {
            $pathfinder->getPath('path.unknown/file.php');
        } finally {
            $this->assertLogContains($logger, 'notice', 'Unable to resolve path from');
        }
    }

    public function testNoticeWhenResolvedPathDoesNotExist(): void
    {
        $logger = new RecordingLogger();
        $pathfinder = $this->createPathfinder(['path.file' => 'foo.bar']);
        $this->injectLogger($pathfinder, $logger);

        $pathfinder->getPath('path.file');

        $this->assertLogContains($logger, 'notice', 'the path does not exist');
    }

    public function testWarningWhenParameterValueMissing(): void
    {
        $logger = new RecordingLogger();
        $pathfinder = $this->createPathfinder();
        $this->injectLogger($pathfinder, $logger);

        $pathfinder->getParameter('path.missing');

        $this->assertLogContains($logger, 'warning', 'No value for');
    }

    public function testWarningWhenParameterValueNotPathLike(): void
    {
        $logger = new RecordingLogger();
        $pathfinder = $this->createPathfinder(['path.text' => 'not-a-path']);
        $this->injectLogger($pathfinder, $logger);

        $pathfinder->getParameter('path.text');

        $this->assertLogContains($logger, 'warning', 'not path-like');
    }

    public function testWarningWhenNestedPlaceholderUnresolved(): void
    {
        $logger = new RecordingLogger();
        $pathfinder = $this->createPathfinder(['path.partial' => '%path.missing%/tail']);
        $this->injectLogger($pathfinder, $logger);

        $pathfinder->getParameter('path.partial');

        $this->assertLogContains($logger, 'warning', 'Unable to resolve parameter');
    }

    public function testErrorWhenParameterKeyCannotResolveDuringPathLookup(): void
    {
        $logger = new RecordingLogger();
        $pathfinder = $this->createPathfinder(['path.empty' => '']);
        $this->injectLogger($pathfinder, $logger);

        $this->expectException(\InvalidArgumentException::class);

        try {
            $pathfinder->getPath('path.empty/file.txt');
        } finally {
            $this->assertLogContains($logger, 'error', 'could not resolve parameter');
        }
    }

    public function testCriticalWhenRelativeToPrefixMismatches(): void
    {
        $logger = new RecordingLogger();
        $other = $this->mkdir('other');
        $pathfinder = $this->createPathfinder(['path.other' => $other]);
        $this->injectLogger($pathfinder, $logger);

        $pathfinder->getPath('path.root/src/Example.php', 'path.other');

        $this->assertLogContains($logger, 'critical', 'Relative path');
    }
}
