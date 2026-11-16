<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\Outbox;

use DateTimeImmutable;

/**
 * IMessageOutbox - Port of the Transactional Outbox of Messages
 *
 * MessagePublisher writes the messages in the caller's transaction (the same connection as the
 * write that produced the fact); OutboxRelay sends them to the broker after the commit and marks
 * each one delivered (confirmed by the broker) or failed (attempts + 1, last reason). Same design
 * as the domain event outbox of 1.11.0 (forward 063, D4).
 *
 * Idempotency: enqueue() skips an entry whose message id is already stored (the reference DDL also
 * has a UNIQUE constraint on it), so publishing the same message twice stores one row.
 *
 * Order: one queue per ordering key. fetchPending() returns the HEAD of each queue (its oldest
 * entry, by entry id, not yet delivered and not exhausted), so that a message of a key is never
 * sent before an earlier one of the same key. A message without an ordering key is a queue of its
 * own: it is always a head, ordered with the others by entry id only. An entry whose attempts
 * reached $maxAttempts is exhausted: no longer returned, no longer blocking its key.
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\Outbox
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IMessageOutbox
{
    /**
     * Writes entries (within the caller's transaction), skipping those whose message id is
     * already stored.
     *
     * @param list<MessageOutboxEntry> $entries Entries to write
     * @return int Number of entries actually written
     */
    public function enqueue(array $entries): int;

    /**
     * The head of each queue: per ordering key, the not-delivered, not-exhausted entry with the
     * smallest entry id, plus every such entry without a key; ordered by entry id; at most $limit.
     *
     * @param int $limit Maximum number of heads (>= 1)
     * @param int|null $maxAttempts Entries with attempts >= this are exhausted; null = never
     * @return list<MessageOutboxEntry>
     * @throws \InvalidArgumentException When $limit < 1
     */
    public function fetchPending(int $limit = 100, ?int $maxAttempts = null): array;

    /**
     * Marks an entry delivered (attempts + 1).
     *
     * @throws \Antevemus\ASpecification\Messaging\Exceptions\MessagingPersistenceException When the entry does not exist
     */
    public function markDelivered(MessageOutboxEntry $entry, DateTimeImmutable $at): void;

    /**
     * Marks a send not confirmed (attempts + 1, last reason replaced, truncated to 2 000 characters).
     *
     * @param string $reason Reason, without stack trace nor payload
     * @throws \Antevemus\ASpecification\Messaging\Exceptions\MessagingPersistenceException When the entry does not exist
     */
    public function markFailed(MessageOutboxEntry $entry, string $reason, DateTimeImmutable $at): void;

    /**
     * The entry of a message id, or null.
     */
    public function find(string $messageId): ?MessageOutboxEntry;

    /**
     * Every entry of an ordering key, in entry id order (inspection and tests).
     *
     * @return list<MessageOutboxEntry>
     */
    public function findByOrderingKey(string $orderingKey): array;

    /**
     * Number of stored entries (every status).
     */
    public function count(): int;
}
