<?php

declare(strict_types=1);

namespace Northrook\Tests\Pathfinder\Path;

use Northrook\Tests\PathfinderTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(\Northrook\Core\Pathfinder\Path::class)]
final class IntegrationTest extends PathfinderTestCase
{
    public function testPathfinderPathSharesFilesystem(): void
    {
        $dir = $this->mkdir('shared');
        $pathfinder = $this->createPathfinder(['path.workspace' => $dir]);
        $path = $pathfinder->path('path.workspace');

        self::assertNotNull($path);

        $target = $dir . '/via-path.txt';
        self::assertTrue($path->append('/via-path.txt')->save('shared'));
        self::assertTrue($this->filesystem->pathsExist($target));
        self::assertSame('shared', $this->filesystem->readFile($target));
    }
}
