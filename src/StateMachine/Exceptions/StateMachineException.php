<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Exceptions;

use RuntimeException;

/**
 * StateMachineException - Base Exception for the Spec-Driven State Machine
 *
 * Thrown for programming and configuration errors of the state machine subsystem: an invalid
 * definition, a guard name with no registered factory, a snapshot that does not belong to the
 * definition it is interpreted with. A denied transition is never an exception: it is a
 * TransitionResult with status `rejected` (forward 023, D6).
 *
 * Features:
 * - Typed base exception for the StateMachine subsystem
 * - Preserves contextual message and root cause
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class StateMachineException extends RuntimeException
{
}
