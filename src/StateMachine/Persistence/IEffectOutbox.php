<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use DateTimeImmutable;

/**
 * IEffectOutbox - Port of the Transactional Outbox of Effects
 *
 * The runner enqueues the effects of an accepted event in the same transaction as the snapshot
 * and the history (forward 023, D12); the EffectDispatcher delivers them after the commit, in
 * write order per instance, and marks each one delivered or failed (attempts + 1, last error
 * message without stack trace, RN-10).
 *
 * Delivery order: fetchPending() returns the HEAD of each instance's queue (its oldest entry not
 * yet delivered), so that an effect is never delivered before an earlier effect of the same
 * instance. An entry whose attempts reached $maxAttempts is exhausted: it is no longer returned
 * and no longer holds its successors back.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IEffectOutbox
{
    /**
     * Writes entries (within the caller's transaction).
     *
     * @param list<OutboxEntry> $entries Entries to write
     */
    public function enqueue(array $entries): void;

    /**
     * The head of each instance's queue: per instance, the not-delivered, not-exhausted entry with
     * the smallest (instance version, sort order); heads ordered by enqueue instant, instance id;
     * at most $limit of them.
     *
     * @param int $limit Maximum number of heads (>= 1)
     * @param int|null $maxAttempts Entries with attempts >= this are exhausted; null = never
     * @return list<OutboxEntry>
     */
    public function fetchPending(int $limit = 100, ?int $maxAttempts = null): array;

    /**
     * Marks an entry delivered (attempts + 1).
     *
     * @param OutboxEntry $entry Entry delivered
     * @param DateTimeImmutable $at Instant of delivery
     */
    public function markDelivered(OutboxEntry $entry, DateTimeImmutable $at): void;

    /**
     * Marks an attempt failed (attempts + 1, last error replaced).
     *
     * @param OutboxEntry $entry Entry attempted
     * @param string $error Error message, without stack trace
     * @param DateTimeImmutable $at Instant of the attempt
     */
    public function markFailed(OutboxEntry $entry, string $error, DateTimeImmutable $at): void;

    /**
     * Every entry of an instance in write order (inspection and tests).
     *
     * @param string $instanceId Instance id
     * @return list<OutboxEntry>
     */
    public function findByInstance(string $instanceId): array;
}
