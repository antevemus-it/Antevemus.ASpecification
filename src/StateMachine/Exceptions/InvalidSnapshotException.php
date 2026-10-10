<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Exceptions;

/**
 * InvalidSnapshotException - Exception Thrown on a Snapshot Foreign to the Definition
 *
 * Occurs when a MachineSnapshot is interpreted with a MachineDefinition it does not belong to:
 * different machine key or version, missing or extra regions, or a current state that the
 * region does not declare. It signals a programming error (wrong definition loaded, corrupted
 * persistence), never a business denial.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class InvalidSnapshotException extends StateMachineException
{
}
