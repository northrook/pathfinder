<?php

declare(strict_types=1);

namespace Northrook\Tests\Pathfinder;

use Northrook\Core\Pathfinder;
use Northrook\Tests\PathfinderTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Pathfinder::class)]
final class ValidateParametersTest extends PathfinderTestCase
{
    public function testValidMapReturnsNoErrors(): void
    {
        $errors = Pathfinder::validateParameters([
            'path.root'  => $this->fixtureRoot,
            'path.cache' => '%path.root%/var/cache',
        ]);

        self::assertSame([], $errors);
    }

    public function testEmptyMapReturnsNoErrors(): void
    {
        self::assertSame([], Pathfinder::validateParameters([]));
    }

    public function testInvalidKeyIsReported(): void
    {
        $errors = Pathfinder::validateParameters([
            'bad!key' => '/tmp',
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('Invalid parameter key', $errors[0]);
    }

    public function testEmptyValueIsReported(): void
    {
        $errors = Pathfinder::validateParameters([
            'path.empty' => '',
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('empty value', $errors[0]);
    }

    public function testUnknownPlaceholderReferenceIsReported(): void
    {
        $errors = Pathfinder::validateParameters([
            'path.cache' => '%path.missing%/var/cache',
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('references unknown key', $errors[0]);
    }

    public function testMultiplePlaceholdersAreReported(): void
    {
        $errors = Pathfinder::validateParameters([
            'path.alpha'  => $this->fixtureRoot,
            'path.beta'   => $this->fixtureRoot,
            'path.joined' => '%path.alpha%%path.beta%',
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('at most one %key% placeholder', $errors[0]);
    }

    public function testSelfReferenceIsReported(): void
    {
        $errors = Pathfinder::validateParameters([
            'path.loop' => '%path.loop%/src',
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('Circular parameter reference', $errors[0]);
    }

    public function testMutualReferenceIsReported(): void
    {
        $errors = Pathfinder::validateParameters([
            'path.a' => '%path.b%/src',
            'path.b' => '%path.a%/var',
        ]);

        self::assertNotEmpty($errors);
        self::assertTrue(
            \array_any(
                $errors,
                static fn (string $error): bool => \str_contains($error, 'Circular parameter reference'),
            ),
        );
    }

    public function testNonPathLikeValueIsReported(): void
    {
        $errors = Pathfinder::validateParameters([
            'path.text' => 'not-a-path',
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('not path-like', $errors[0]);
    }

    public function testChainedPlaceholdersResolveForPathLikeCheck(): void
    {
        $errors = Pathfinder::validateParameters([
            'path.root'  => $this->fixtureRoot,
            'path.cache' => '%path.root%/var/cache',
            'path.a'     => '%path.b%/src',
            'path.b'     => '%path.root%',
        ]);

        self::assertSame([], $errors);
    }

    public function testUrlValueIsAccepted(): void
    {
        $errors = Pathfinder::validateParameters([
            'path.assets' => 'https://example.com/assets',
        ]);

        self::assertSame([], $errors);
    }

    public function testLiteralPercentValueIsValidated(): void
    {
        $errors = Pathfinder::validateParameters([
            'path.percent' => '100%',
        ]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('not path-like', $errors[0]);
    }
}
