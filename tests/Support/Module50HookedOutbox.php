<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Messaging\Outbox\IMessageOutbox;
use Antevemus\ASpecification\Messaging\Outbox\MessageOutboxEntry;
use Closure;
use DateTimeImmutable;

/**
 * The outbox as one relay process of Module 50 (2.16.0, forward 065) sees it: every read and mark of the relay calls the
 * hook first (`fetch`, `delivered`, `failed`), where the scheduler suspends the fiber of the process, kills it or lets it
 * go on; then the inner outbox answers. The hook also learns each mark, so the property knows which sends were marked.
 *
 * @version 2.16.0
 */
final class Module50HookedOutbox implements IMessageOutbox
{
    /**
     * @param Closure(string, string, mixed): void $hook (operation, owner, entry)
     */
    public function __construct(private readonly IMessageOutbox $inner, private readonly string $owner, private readonly Closure $hook)
    {
    }

    public function enqueue(array $entries): int
    {
        return $this->inner->enqueue($entries);
    }

    public function fetchPending(int $limit = 100, ?int $maxAttempts = null): array
    {
        ($this->hook)('fetch', $this->owner, null);

        return $this->inner->fetchPending($limit, $maxAttempts);
    }

    public function markDelivered(MessageOutboxEntry $entry, DateTimeImmutable $at): void
    {
        ($this->hook)('delivered', $this->owner, $entry);
        $this->inner->markDelivered($entry, $at);
    }

    public function markFailed(MessageOutboxEntry $entry, string $reason, DateTimeImmutable $at): void
    {
        ($this->hook)('failed', $this->owner, $entry);
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
