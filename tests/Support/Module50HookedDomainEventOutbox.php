<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\DomainEvents\Outbox\DomainEventOutboxEntry;
use Antevemus\ASpecification\DomainEvents\Outbox\IDomainEventOutbox;
use Closure;
use DateTimeImmutable;

/**
 * The domain event outbox as one dispatcher process of Module 50 (2.16.0, forward 065) sees it: every read and mark calls
 * the hook first (`fetch`, `delivered`, `failed`), as Module50HookedOutbox does for the messages.
 *
 * @version 2.16.0
 */
final class Module50HookedDomainEventOutbox implements IDomainEventOutbox
{
    /**
     * @param Closure(string, string, mixed): void $hook (operation, owner, entry)
     */
    public function __construct(private readonly IDomainEventOutbox $inner, private readonly string $owner, private readonly Closure $hook)
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

    public function markDelivered(DomainEventOutboxEntry $entry, DateTimeImmutable $at): void
    {
        ($this->hook)('delivered', $this->owner, $entry);
        $this->inner->markDelivered($entry, $at);
    }

    public function markFailed(DomainEventOutboxEntry $entry, string $error, DateTimeImmutable $at): void
    {
        ($this->hook)('failed', $this->owner, $entry);
        $this->inner->markFailed($entry, $error, $at);
    }

    public function findByEntity(string $entityClass, string $entityId): array
    {
        return $this->inner->findByEntity($entityClass, $entityId);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?DomainEventOutboxEntry
    {
        return $this->inner->findByIdempotencyKey($idempotencyKey);
    }

    public function count(): int
    {
        return $this->inner->count();
    }
}
