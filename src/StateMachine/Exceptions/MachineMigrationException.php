<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Exceptions;

/**
 * MachineMigrationException - Refused Version Migration
 *
 * Thrown by MachineRunner::migrate() when no migration is registered for the (key, from, to)
 * triple, or when the migrated snapshot does not conform to the target definition: wrong key or
 * version, regions that differ from the target's, or a state the target region does not declare
 * (forward 023, D16).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class MachineMigrationException extends StateMachineException
{
}
