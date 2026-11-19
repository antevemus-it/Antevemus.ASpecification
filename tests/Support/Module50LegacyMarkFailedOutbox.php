<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Messaging\Outbox\IMessageOutbox;
use Antevemus\ASpecification\Messaging\Outbox\InMemoryMessageOutbox;
use Antevemus\ASpecification\Messaging\Outbox\MessageOutboxEntry;
use DateTimeImmutable;

/**
 * The in-memory outbox with the failure mark of 2.15.0 (Module 50, 2.16.0, forward 065, D3): markFailed() overwrites the
 * entry whatever its status, so a delivered entry turns back into a failed one. The counter-proof of the property
 * sequences without a lock: the same generator reopens delivered entries here and never on the outbox of 2.16.0.
 *
 * @version 2.16.0
 */
final class Module50LegacyMarkFailedOutbox implements IMessageOutbox
{
    public function __construct(private readonly InMemoryMessageOutbox $inner)
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
        $this->inner->markDelivered($entry, $at);
    }

    public function markFailed(MessageOutboxEntry $entry, string $reason, DateTimeImmutable $at): void
    {
        $state = $this->inner->exportState();
        $stored = $state['entries'][$entry->entryId] ?? null;
        if ($stored === null) {
            $this->inner->markFailed($entry, $reason, $at); // throws, as it always did

            return;
        }
        $state['entries'][$entry->entryId] = $stored->withFailed($reason, $at);
        $this->inner->importState($state);
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
