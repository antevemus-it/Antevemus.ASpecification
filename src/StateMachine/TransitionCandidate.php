<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

/**
 * TransitionCandidate - Immutable Candidate Evaluated by Every Guard
 *
 * Every guard is an ISpecification (forward 023, D4) evaluated against this candidate: the
 * domain `context` given by the caller (the machine keeps no context of its own), the `event`
 * being processed and the `snapshot` BEFORE the event (RN-04: every region sees the same
 * pre-event state, so region order never changes the outcome).
 *
 * Property paths work through the shared property accessor (`PropertyAccess`): `context.role`,
 * `event.payload.motive`, `event.type`, `snapshot.states.pending`.
 *
 * @version    2.0.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class TransitionCandidate
{
    /**
     * @param object|array<mixed>|null $context Domain context given by the caller
     * @param Event $event Event being processed
     * @param MachineSnapshot $snapshot Snapshot before the event
     */
    public function __construct(
        public readonly object|array|null $context,
        public readonly Event $event,
        public readonly MachineSnapshot $snapshot
    ) {
    }

    /**
     * Domain context given by the caller.
     *
     * @return object|array<mixed>|null
     */
    public function getContext(): object|array|null
    {
        return $this->context;
    }

    /** Event being processed. */
    public function getEvent(): Event
    {
        return $this->event;
    }

    /** Snapshot before the event. */
    public function getSnapshot(): MachineSnapshot
    {
        return $this->snapshot;
    }
}
