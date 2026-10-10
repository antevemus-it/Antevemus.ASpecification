<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

/**
 * TransitionStatus - Single Status of a TransitionResult
 *
 * Success is derived from this status only (forward 023, D20): accepted is success, rejected and
 * unhandled are not.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum TransitionStatus: string
{
    /** At least one region transitioned and none rejected. */
    case ACCEPTED = 'accepted';

    /** Some region had candidate transitions and none passed: nothing changed (all or nothing). */
    case REJECTED = 'rejected';

    /** No region had a candidate transition for the event: nothing changed. */
    case UNHANDLED = 'unhandled';
}
