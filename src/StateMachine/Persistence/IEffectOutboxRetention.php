<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use DateTimeImmutable;

/**
 * IEffectOutboxRetention - Retention of the Outbox of Effects of the State Machine
 *
 * The outbox keeps every effect, delivered or not, so it only grows (forward 065, D12). A separate
 * interface, implemented by InMemoryEffectOutbox and PdoEffectOutbox, because a method added to
 * IEffectOutbox would break the implementations outside the library.
 *
 * Only delivered entries are removed. A pending or failed entry, exhausted or not, is never removed: it
 * is an effect whose handler did not succeed, kept for an investigation. The removal goes in batches of
 * at most $limit entries, the oldest delivery first, so that one call never becomes a huge DELETE; call
 * it again until it returns less than $limit.
 *
 * What a purge can and cannot reopen. The outbox does not deduplicate: an effect is written once, by the
 * step that produced it, under its key (instance, version of the instance, position), and the runner
 * never writes a step again; a repeated event is answered by the idempotency store of the runner
 * (`state_machine_idempotency`) with the original result and no new effect. So purging a delivered effect
 * never makes it run again. What deduplicates a delivery is the handler, by the effect key it receives
 * (at least once: a dispatcher that died between the handler and the mark delivers it again). The purge
 * cannot reopen anything; what reopens is a purge of the deduplication of the handler, or of the
 * idempotency store of the runner, shorter than the delay of the copies they absorb.
 *
 * @version    2.16.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IEffectOutboxRetention
{
    /**
     * Removes at most $limit delivered entries whose delivery is older than $before (strictly), the
     * oldest delivery first (ties by instance id, instance version and position).
     *
     * @param DateTimeImmutable $before Entries delivered before this instant are removed
     * @param int $limit Largest number of entries removed by this call (>= 1)
     * @return int Number of entries removed
     * @throws \InvalidArgumentException When $limit < 1
     * @throws \Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException When the store fails
     */
    public function purgeDeliveredBefore(DateTimeImmutable $before, int $limit = 1000): int;
}
