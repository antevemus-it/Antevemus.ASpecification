<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Exceptions;

/**
 * MachineInstanceAlreadyExistsException - Instance Id Already Taken
 *
 * Thrown by MachineRunner::start() (and by the instance stores' insert()) when an instance with the
 * same id already exists: starting is not idempotent by id, it creates (forward 023, D13).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class MachineInstanceAlreadyExistsException extends StateMachineException
{
}
