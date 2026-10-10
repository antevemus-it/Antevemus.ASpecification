<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Exceptions;

/**
 * MachineInstanceNotFoundException - Unknown Machine Instance
 *
 * Thrown by the MachineRunner when an event, a migration or a deadline targets an instance id
 * that the instance store does not hold (forward 023, D13).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class MachineInstanceNotFoundException extends StateMachineException
{
}
