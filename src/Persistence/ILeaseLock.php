<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Persistence;

use DateTimeImmutable;

/**
 * ILeaseLock - A Named Lock With a Lease: One Worker at a Time
 *
 * The three dispatchers of an outbox (OutboxRelay of the messages, DomainEventDispatcher of the domain
 * events, EffectDispatcher of the effects of the state machine) read the heads of their outbox and mark
 * them after the delivery. Two of them over the same outbox keep the order of the first delivery of each
 * queue (an entry is a head only once the earlier ones of its queue were delivered), but they cost a late
 * duplicate delivered after the later entries of its queue and attempts counted twice. Given a lock
 * (`lock:`), a dispatcher works alone: it takes the lock under its own name before its first round,
 * renews it before every further round, stops when a renewal fails and releases it at the end
 * (forward 065, D9, D10). One lock (one table) serves every dispatcher of an application: each one uses
 * its own name, so two different outboxes never hold each other back.
 *
 * The lock is a lease: it is held until an instant (now + the lease), so that a worker that died does
 * not hold it forever; the next one takes it once the lease ran out. A worker paused for longer than its
 * lease (a long garbage collection, a handler or a broker that took too long) may find, on its next
 * renewal, that another one took over: it stops there, and what it delivered in the meantime may be
 * delivered twice (at least once; the consumers deduplicate). The lease must exceed the longest round.
 *
 * The instants are those of the caller (the clock of the dispatcher): the workers that share a lock
 * compare their clocks, so the lease must also exceed the skew between their machines.
 *
 * @version    2.16.0
 * @package    Antevemus\ASpecification
 * @subpackage Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ILeaseLock
{
    /** Longest lock name, the size of the column of the reference DDL. */
    public const MAX_NAME_LENGTH = 120;

    /** Longest owner, the size of the column of the reference DDL. */
    public const MAX_OWNER_LENGTH = 120;

    /**
     * Takes the lock $name for $owner until $now + $leaseSeconds when nobody holds it (or its lease ran
     * out before $now), or renews it when $owner already holds it (even past its lease, as long as nobody
     * else took it over).
     *
     * @param string $name The lock (1 to MAX_NAME_LENGTH characters): one per outbox
     * @param string $owner Who takes it (1 to MAX_OWNER_LENGTH characters): one id per worker process
     * @param DateTimeImmutable $now The instant of the caller
     * @param int $leaseSeconds How long the lock is held without a renewal (>= 1)
     * @return bool True when $owner holds the lock afterwards
     * @throws \InvalidArgumentException When the name or the owner is empty or too long, or the lease is below 1
     * @throws LeaseLockException When the store fails
     */
    public function tryAcquire(string $name, string $owner, DateTimeImmutable $now, int $leaseSeconds): bool;

    /**
     * Releases the lock $name when $owner holds it; nothing otherwise (another one took it over, or
     * nobody holds it).
     *
     * @throws LeaseLockException When the store fails
     */
    public function release(string $name, string $owner): void;
}
