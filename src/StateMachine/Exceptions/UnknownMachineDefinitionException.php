<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Exceptions;

/**
 * UnknownMachineDefinitionException - Machine Definition Not Registered
 *
 * Thrown by an IMachineDefinitionRepository when the (key, version) pair asked for is not
 * registered, so the runner cannot load the version an instance is pinned to (forward 023, D16).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class UnknownMachineDefinitionException extends StateMachineException
{
}
