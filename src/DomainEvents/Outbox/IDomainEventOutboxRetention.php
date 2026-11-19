<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents\Outbox;

use DateTimeImmutable;

/**
 * IDomainEventOutboxRetention - Retention of the Domain Event Outbox
 *
 * The outbox keeps every entry, delivered or not, so it only grows (forward 065, D12). A separate
 * interface, implemented by InMemoryDomainEventOutbox and PdoDomainEventOutbox, because a method added to
 * IDomainEventOutbox would break the implementations outside the library.
 *
 * Only delivered entries are removed. A pending or failed entry, exhausted or not, is never removed: it
 * is an event whose handlers did not all succeed, kept for an investigation. The removal goes in batches
 * of at most $limit entries, the oldest delivery first, so that one call never becomes a huge DELETE;
 * call it again until it returns less than $limit.
 *
 * The window: enqueue() skips an event whose idempotency key is stored, and only while it is stored. The
 * key is derived from the trigger, the entity, the kind and the instant of the crossing, so a crossing
 * recorded again (a write retried, a sweep over the same state) publishes the same key: once its entry
 * is purged, it is published again. So the window of the outbox must be longer than any retry that may
 * record the same crossing again, and the deduplication of each handler (the idempotency store of the
 * state machine, for MachineEventBridge) must keep its rows at least that long plus the longest delay of
 * the dispatcher.
 *
 * @version    2.16.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents\Outbox
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IDomainEventOutboxRetention
{
    /**
     * Removes at most $limit delivered entries whose delivery is older than $before (strictly), the
     * oldest delivery first (ties by event id).
     *
     * @param DateTimeImmutable $before Entries delivered before this instant are removed
     * @param int $limit Largest number of entries removed by this call (>= 1)
     * @return int Number of entries removed
     * @throws \InvalidArgumentException When $limit < 1
     * @throws \Antevemus\ASpecification\DomainEvents\Exceptions\DomainEventPersistenceException When the store fails
     */
    public function purgeDeliveredBefore(DateTimeImmutable $before, int $limit = 1000): int;
}
