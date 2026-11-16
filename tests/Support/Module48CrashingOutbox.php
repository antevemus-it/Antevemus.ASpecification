<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Messaging\Outbox\IMessageOutbox;
use Antevemus\ASpecification\Messaging\Outbox\MessageOutboxEntry;
use Closure;
use DateTimeImmutable;

/**
 * An outbox of Module 48 (2.14.0, forward 063) whose relay dies, when told, after the broker confirmed a message and
 * before the outbox marks it delivered: the mark is not written and Module48Crash is thrown.
 *
 * @version 2.14.0
 */
final class Module48CrashingOutbox implements IMessageOutbox
{
    /** @var (Closure(MessageOutboxEntry): bool)|null */
    public ?Closure $crashOnDelivered = null;

    public function __construct(private readonly IMessageOutbox $inner)
    {
    }

    public function enqueue(array $entries): int
    {
        return $this->inner->enqueue($entries);
    }

    public function fetchPending(int $limit = 100, ?int $maxAttempts = null): array
    {
        return $this->inner->fetchPending($limit, $maxAttempts);
    }

    public function markDelivered(MessageOutboxEntry $entry, DateTimeImmutable $at): void
    {
        if ($this->crashOnDelivered !== null && ($this->crashOnDelivered)($entry)) {
            throw new Module48Crash('relay died after the confirmation of ' . $entry->messageId());
        }
        $this->inner->markDelivered($entry, $at);
    }

    public function markFailed(MessageOutboxEntry $entry, string $reason, DateTimeImmutable $at): void
    {
        $this->inner->markFailed($entry, $reason, $at);
    }

    public function find(string $messageId): ?MessageOutboxEntry
    {
        return $this->inner->find($messageId);
    }

    public function findByOrderingKey(string $orderingKey): array
    {
        return $this->inner->findByOrderingKey($orderingKey);
    }

    public function count(): int
    {
        return $this->inner->count();
    }
}
