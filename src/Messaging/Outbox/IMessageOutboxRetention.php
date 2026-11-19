<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\Outbox;

use DateTimeImmutable;

/**
 * IMessageOutboxRetention - Retention of the Message Outbox
 *
 * An outbox keeps every entry, delivered or not, so it only grows; the inbox has
 * IProcessedMessageStore::purgeProcessedBefore(), and this is the counterpart for the outbox
 * (forward 065, D4). A separate interface, implemented by InMemoryMessageOutbox and PdoMessageOutbox,
 * because a method added to IMessageOutbox would break the implementations outside the library.
 *
 * Only delivered entries are removed. A pending or failed entry, exhausted or not, is never removed: it
 * is a message that has not reached the broker (a dead message on the side of the producer, kept for an
 * investigation). The removal goes in batches of at most $limit entries, the oldest delivery first, so
 * that one call never becomes a huge DELETE; call it again until it returns less than $limit.
 *
 * The window (D5): enqueue() skips a message whose id is stored, and only while it is stored. Once an
 * entry is purged, a producer that writes the same message id again publishes it again. So the window
 * of the outbox must be longer than any retry of a producer that may write the same message again, and
 * the retention of the inbox of each consumer must be at least the window of the outbox plus the longest
 * delay of the relay (a copy sent late must still find its row in the inbox).
 *
 * @version    2.16.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\Outbox
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IMessageOutboxRetention
{
    /**
     * Removes at most $limit delivered entries whose delivery is older than $before (strictly), the
     * oldest delivery first (ties by entry id).
     *
     * @param DateTimeImmutable $before Entries delivered before this instant are removed
     * @param int $limit Largest number of entries removed by this call (>= 1)
     * @return int Number of entries removed
     * @throws \InvalidArgumentException When $limit < 1
     * @throws \Antevemus\ASpecification\Messaging\Exceptions\MessagingPersistenceException When the store fails
     */
    public function purgeDeliveredBefore(DateTimeImmutable $before, int $limit = 1000): int;
}
