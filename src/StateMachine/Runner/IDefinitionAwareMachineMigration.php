<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Runner;

use Antevemus\ASpecification\StateMachine\MachineDefinition;
use Antevemus\ASpecification\StateMachine\MachineSnapshot;

/**
 * IDefinitionAwareMachineMigration - A Migration That Reads the Target Definition (2.7.0)
 *
 * A migration registered for (key, from, to) that implements this interface receives the validated
 * target definition from the runner (migrateTo()) instead of migrate(): it can map a snapshot without
 * knowing the target version in advance, as MachineMigrations::byStateName() does. The runner still
 * checks the result against the target definition.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Runner
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IDefinitionAwareMachineMigration extends IMachineMigration
{
    /**
     * Maps a snapshot of the source version to a snapshot of the target definition.
     *
     * @param MachineSnapshot $snapshot Snapshot pinned to the source version
     * @param MachineDefinition $target Target definition (validated)
     * @return MachineSnapshot Snapshot pinned to the target version
     */
    public function migrateTo(MachineSnapshot $snapshot, MachineDefinition $target): MachineSnapshot;
}
