<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Stringable;

/**
 * MachineDefinitionWarning - Non-Blocking Notice About a Machine Definition
 *
 * Produced by MachineDefinitionValidator (forward 023, D17). A warning never refuses the
 * definition: it points at something the author probably did not mean (a dead-end state, a
 * transition that can never run, guards whose declaration order silently decides). In the same
 * spirit as RuleCompilationWarning, detections based on the specification algebra are
 * conservative: no warning means "not proven problematic", never "proven sound".
 *
 * Features:
 * - Stable kind code (KIND_* constants) for programmatic handling
 * - Location (region, state, transition id, event type) when applicable
 * - Human-readable message
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MachineDefinitionWarning implements Stringable
{
    /** The state cannot be reached from the initial state of its region. */
    public const KIND_UNREACHABLE_STATE = 'unreachable_state';

    /** A non-final state has no transition that leaves it (a trap: the instance gets stuck). */
    public const KIND_DEAD_END_STATE = 'dead_end_state';

    /**
     * Two guarded transitions for the same event in the same list are not proven disjoint by the
     * specification algebra (both guards are in its decidable fragment): the declaration order
     * decides which one runs.
     */
    public const KIND_OVERLAPPING_GUARDS = 'overlapping_guards';

    /**
     * A transition without guard (or with a guard proven a tautology) precedes another transition
     * for the same event in the same list: the later one never runs.
     */
    public const KIND_SHADOWED_TRANSITION = 'shadowed_transition';

    /**
     * A declared guard is proven a tautology by the specification algebra: it always passes (e.g. a
     * role guard with an empty role list). Allowed, but it is the author's decision, not an accident.
     */
    public const KIND_TAUTOLOGICAL_GUARD = 'tautological_guard';

    /**
     * @param string $kind One of the KIND_* constants
     * @param string $message Human-readable explanation
     * @param string|null $region Region involved, when any
     * @param string|null $state State involved, when any (null for region-level transitions)
     * @param string|null $transitionId Transition involved, when any
     * @param string|null $eventType Event type involved, when any
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $message,
        public readonly ?string $region = null,
        public readonly ?string $state = null,
        public readonly ?string $transitionId = null,
        public readonly ?string $eventType = null
    ) {
    }

    /**
     * Returns the human-readable explanation.
     */
    public function __toString(): string
    {
        return $this->message;
    }
}
