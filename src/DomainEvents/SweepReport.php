<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

/**
 * SweepReport - Outcome of One TriggerSweeper::sweep() or baseline()
 *
 * What a run did (forward 030, D3, D4): the crossings it recorded and published (enter and exit),
 * the compare-and-set writes it lost to another sweeper or a write (that crossing was recorded by
 * the other one), the states a baseline recorded, the evaluation errors (reported to the error
 * reporter too, never swallowed), and two cost counters: the entities the repository built for the
 * run and the state rows it read. limitReached() tells a cron to run again: the limit cut the run
 * before every crossing was handled.
 *
 * @version    1.12.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class SweepReport
{
    /**
     * @param list<DomainEvent> $events Events published, in order
     * @param int $entered Enter crossings recorded
     * @param int $exited Exit crossings recorded
     * @param int $recorded States recorded without an event (baseline)
     * @param int $conflicts Compare-and-set writes lost (another writer recorded the row first)
     * @param list<TriggerEvaluationError> $errors Evaluation errors
     * @param int $entitiesLoaded Entities the repository built for the run
     * @param int $statesRead State rows read from the store
     * @param bool $limitReached True when the limit stopped the run early
     */
    public function __construct(
        private readonly array $events = [],
        private readonly int $entered = 0,
        private readonly int $exited = 0,
        private readonly int $recorded = 0,
        private readonly int $conflicts = 0,
        private readonly array $errors = [],
        private readonly int $entitiesLoaded = 0,
        private readonly int $statesRead = 0,
        private readonly bool $limitReached = false
    ) {
    }

    /**
     * The events published, in order.
     *
     * @return list<DomainEvent>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * Names of the events published, in order (assertions, logs).
     *
     * @return list<string>
     */
    public function eventNames(): array
    {
        return array_map(static fn(DomainEvent $e): string => $e->name, $this->events);
    }

    /** Enter crossings recorded (an enter is recorded even when the trigger has no enter event). */
    public function enteredCount(): int
    {
        return $this->entered;
    }

    /** Exit crossings recorded (an exit is recorded even when the trigger has no exit event). */
    public function exitedCount(): int
    {
        return $this->exited;
    }

    /** States a baseline recorded (new or changed rows); 0 for a sweep. */
    public function recordedCount(): int
    {
        return $this->recorded;
    }

    /** Compare-and-set writes lost to another sweeper or a write. */
    public function conflictCount(): int
    {
        return $this->conflicts;
    }

    /**
     * The evaluation errors.
     *
     * @return list<TriggerEvaluationError>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** True when at least one trigger or entity could not be evaluated. */
    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** Entities the repository built for the run (cost counter). */
    public function entitiesLoaded(): int
    {
        return $this->entitiesLoaded;
    }

    /** State rows read from the store (cost counter). */
    public function statesRead(): int
    {
        return $this->statesRead;
    }

    /** True when the limit stopped the run before every crossing was handled: run it again. */
    public function limitReached(): bool
    {
        return $this->limitReached;
    }

    /**
     * Array form (counters, event names, error messages).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'entered' => $this->entered,
            'exited' => $this->exited,
            'recorded' => $this->recorded,
            'conflicts' => $this->conflicts,
            'events' => $this->eventNames(),
            'errors' => array_map(static fn(TriggerEvaluationError $e): string => $e->getMessage(), $this->errors),
            'entities_loaded' => $this->entitiesLoaded,
            'states_read' => $this->statesRead,
            'limit_reached' => $this->limitReached,
        ];
    }
}
