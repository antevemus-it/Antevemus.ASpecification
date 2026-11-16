<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\Inbox;

use Antevemus\ASpecification\Messaging\Message;
use DateTimeImmutable;

/**
 * IProcessedMessageStore - Port of the Inbox of the Consumers (the Idempotent Consumer)
 *
 * MessageConsumer records here, in the transaction of the handler, that a consumer handled a
 * message (forward 063, D8). When the handler writes through the same connection, the effect and
 * the row commit or roll back together: a message delivered twice (at-least-once) is handled once
 * per consumer (RN-03). One row per consumer x idempotency key (the primary key of the reference
 * DDL); the payload is never stored.
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\Inbox
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IProcessedMessageStore
{
    /**
     * True when the consumer already handled the key.
     */
    public function has(string $consumer, string $idempotencyKey): bool;

    /**
     * Records that the consumer handled the message (within the caller's transaction).
     *
     * @return bool True when written; false when the row was already there (nothing written)
     * @throws \InvalidArgumentException When the consumer name is invalid
     * @throws \Antevemus\ASpecification\Messaging\Exceptions\MessagingPersistenceException When the write
     *         fails (a race of two processes of one consumer ends in an integrity violation, isIntegrityViolation())
     */
    public function markProcessed(string $consumer, Message $message, DateTimeImmutable $at): bool;

    /**
     * The row of a key, or null.
     */
    public function find(string $consumer, string $idempotencyKey): ?ProcessedMessage;

    /**
     * Number of rows, of one consumer or of all of them.
     */
    public function count(?string $consumer = null): int;

    /**
     * Deletes the rows of a consumer processed before an instant (retention: a message older than
     * the broker keeps it cannot be delivered again).
     *
     * @return int Rows deleted
     */
    public function purgeProcessedBefore(string $consumer, DateTimeImmutable $before): int;
}
