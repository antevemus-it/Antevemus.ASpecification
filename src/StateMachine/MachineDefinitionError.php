<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Stringable;

/**
 * MachineDefinitionError - One Blocking Problem of a Machine Definition
 *
 * Produced by the definition model (structural invariants) and by MachineDefinitionValidator
 * (forward 023, D17). A definition with at least one error is refused: the errors travel together
 * in an InvalidMachineDefinitionException.
 *
 * Features:
 * - Stable kind code (KIND_* constants) for programmatic handling
 * - Location (region, state, transition id) when applicable
 * - Human-readable message
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MachineDefinitionError implements Stringable
{
    /** Structural problem of the model (empty name, malformed array, non JSON-friendly parameter...). */
    public const KIND_MALFORMED = 'malformed';

    /** Two regions, two states of a region or two transitions share the same name/id. */
    public const KIND_DUPLICATE = 'duplicate';

    /** The region declares no initial state. */
    public const KIND_MISSING_INITIAL = 'missing_initial';

    /** The initial state of the region is not one of its states. */
    public const KIND_UNKNOWN_INITIAL = 'unknown_initial';

    /** A transition targets a state that does not exist in its region. */
    public const KIND_UNKNOWN_TARGET = 'unknown_target';

    /** A transition targets a state that belongs to another region. */
    public const KIND_CROSS_REGION_TARGET = 'cross_region_target';

    /** An inState guard references a region or state that does not exist. */
    public const KIND_UNKNOWN_IN_STATE = 'unknown_in_state';

    /** A guard name has no factory in the GuardRegistry. */
    public const KIND_UNKNOWN_GUARD = 'unknown_guard';

    /** A guard factory failed to build the specification with the declared parameters. */
    public const KIND_GUARD_BUILD_FAILED = 'guard_build_failed';

    /** An action name is not in the list of known actions. */
    public const KIND_UNKNOWN_ACTION = 'unknown_action';

    /** A final state declares outgoing transitions (final states are inert). */
    public const KIND_FINAL_STATE_WITH_EXIT = 'final_state_with_exit';

    /**
     * A deadline declares no source or more than one among `after`, `afterFrom` and `atFrom`, or a
     * context path that is not `context.<path>` (forward 025, D12).
     */
    public const KIND_INVALID_DEADLINE = 'invalid_deadline';

    /**
     * A deadline names a calendar that the DeadlineCalendarRegistry given to the validator does not
     * know (2.7.0). Combining a calendar with `atFrom`, or with a duration in years or months, is
     * refused when the deadline is built, with KIND_INVALID_DEADLINE.
     */
    public const KIND_UNKNOWN_CALENDAR = 'unknown_calendar';

    /**
     * @param string $kind One of the KIND_* constants
     * @param string $message Human-readable explanation
     * @param string|null $region Region involved, when any
     * @param string|null $state State involved, when any
     * @param string|null $transitionId Transition involved, when any
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $message,
        public readonly ?string $region = null,
        public readonly ?string $state = null,
        public readonly ?string $transitionId = null
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
