<?php

declare(strict_types=1);

namespace Northrook\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * @phpstan-type LogEntry array{level: string, message: string, context: array<string, mixed>}
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<LogEntry> */
    private array $entries = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $normalizedLevel = \is_string($level)
            ? $level
            : ( $level instanceof \Stringable ? (string) $level : 'unknown' );

        $this->entries[] = [
            'level'   => $normalizedLevel,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /** @return list<LogEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function hasLevel(string $level, string $messageFragment): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry['level'] === $level && str_contains($entry['message'], $messageFragment)) {
                return true;
            }
        }

        return false;
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
