<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents\State;

use Antevemus\ASpecification\DomainEvents\Exceptions\DomainEventPersistenceException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * ITriggerStateStore - Persisted Last Known State of Every Trigger for Every Entity
 *
 * The memory of the domain event triggers (forward 030, D2): one TriggerState per (trigger, entity
 * class, entity id). TriggerSweeper compares it with the truth at the sweep instant to find the
 * crossings caused by time alone; TriggeringRepository reads its "before" from it and updates it on
 * every write (D6), so the two never publish the same crossing twice.
 *
 * Writes are compare-and-set on the version read: compareAndSet() with a null expected version
 * inserts and fails when the row already exists; with a version it updates only the row still at
 * that version. A failed write returns false and changes nothing, so two sweepers (or a sweeper and
 * a writer) racing on one crossing publish it once: the loser sees false and publishes nothing.
 *
 * Implementations: InMemoryTriggerStateStore (tests, single process) and PdoTriggerStateStore (table
 * `domain_event_trigger_state` of `resources/sql/domain_events.ansi.sql`). Instants are stored in
 * UTC; entity ids are compared and ordered as text.
 *
 * @version    1.12.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents\State
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ITriggerStateStore
{
    /**
     * The state of one trigger for one entity, or null when none was recorded.
     *
     * @throws DomainEventPersistenceException
     */
    public function find(string $triggerName, string $entityClass, string $entityId): ?TriggerState;

    /**
     * Every recorded state of one entity, by trigger name.
     *
     * @return array<string, TriggerState>
     * @throws DomainEventPersistenceException
     */
    public function findByEntity(string $entityClass, string $entityId): array;

    /**
     * The recorded states of one trigger for several entities of a class, by entity id (ids
     * without a state are absent from the result).
     *
     * @param list<string> $entityIds Entity ids, in text form
     * @return array<string, TriggerState>
     * @throws DomainEventPersistenceException
     */
    public function findMany(string $triggerName, string $entityClass, array $entityIds): array;

    /**
     * One page of the satisfied states of one trigger and class, in entity id order, after an
     * entity id (keyset pagination: rows changed while paging do not shift the pages).
     *
     * @param int $limit Page size (>= 1)
     * @param string|null $afterEntityId Last entity id of the previous page; null = first page
     * @return list<TriggerState>
     * @throws InvalidArgumentException When the limit is below 1
     * @throws DomainEventPersistenceException
     */
    public function findSatisfied(string $triggerName, string $entityClass, int $limit, ?string $afterEntityId = null): array;

    /**
     * Compare-and-set: records the state only when the row is still as read.
     *
     * @param int|null $expectedVersion Version read; null = the row must not exist (insert, version 1)
     * @param bool $satisfied New satisfaction
     * @param DateTimeImmutable $evaluatedAt Instant of the evaluation
     * @return bool True when written; false when the row changed (or appeared) since it was read
     * @throws InvalidArgumentException When the key is invalid
     * @throws DomainEventPersistenceException
     */
    public function compareAndSet(
        string $triggerName,
        string $entityClass,
        string $entityId,
        ?int $expectedVersion,
        bool $satisfied,
        DateTimeImmutable $evaluatedAt
    ): bool;

    /**
     * Compare-and-delete: removes the row only when it is still at the version read.
     *
     * @return bool True when deleted; false when the row changed or is gone
     * @throws DomainEventPersistenceException
     */
    public function compareAndDelete(string $triggerName, string $entityClass, string $entityId, int $expectedVersion): bool;

    /**
     * Number of recorded states.
     *
     * @throws DomainEventPersistenceException
     */
    public function count(): int;
}
