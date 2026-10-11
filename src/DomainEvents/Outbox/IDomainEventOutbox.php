<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents\Outbox;

use DateTimeImmutable;

/**
 * IDomainEventOutbox - Port of the Transactional Outbox of Domain Events
 *
 * OutboxDomainEventPublisher writes the events of a write in the same transaction as the write
 * when the repository and the outbox share one; the DomainEventDispatcher delivers them after the
 * commit and marks each one delivered or failed (attempts + 1, last error message without stack
 * trace). Same design as the 1.8.0 IEffectOutbox (forward 029, D5).
 *
 * Idempotency: enqueue() skips an entry whose event idempotency key is already stored (the
 * reference DDL also has a UNIQUE constraint on it), so re-applying the same write never stores a
 * second row.
 *
 * Delivery order: one queue per entity (class + id). fetchPending() returns the HEAD of each queue
 * (its oldest entry, by event id, not yet delivered), so that an event of an entity is never
 * delivered before an earlier one of the same entity (an exit never overtakes its enter). An entry
 * whose attempts reached $maxAttempts is exhausted: no longer returned, no longer blocking.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents\Outbox
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IDomainEventOutbox
{
    /**
     * Writes entries (within the caller's transaction), skipping those whose idempotency key is
     * already stored.
     *
     * @param list<DomainEventOutboxEntry> $entries Entries to write
     * @return int Number of entries actually written
     */
    public function enqueue(array $entries): int;

    /**
     * The head of each entity's queue: per entity, the not-delivered, not-exhausted entry with the
     * smallest event id; heads ordered by event id; at most $limit of them.
     *
     * @param int $limit Maximum number of heads (>= 1)
     * @param int|null $maxAttempts Entries with attempts >= this are exhausted; null = never
     * @return list<DomainEventOutboxEntry>
     */
    public function fetchPending(int $limit = 100, ?int $maxAttempts = null): array;

    /**
     * Marks an entry delivered (attempts + 1).
     */
    public function markDelivered(DomainEventOutboxEntry $entry, DateTimeImmutable $at): void;

    /**
     * Marks an attempt failed (attempts + 1, last error replaced).
     *
     * @param string $error Error message, without stack trace
     */
    public function markFailed(DomainEventOutboxEntry $entry, string $error, DateTimeImmutable $at): void;

    /**
     * Every entry of an entity, in event id order (inspection and tests).
     *
     * @return list<DomainEventOutboxEntry>
     */
    public function findByEntity(string $entityClass, string $entityId): array;

    /**
     * The entry of an idempotency key, or null.
     */
    public function findByIdempotencyKey(string $idempotencyKey): ?DomainEventOutboxEntry;

    /**
     * Number of stored entries (every status).
     */
    public function count(): int;
}
