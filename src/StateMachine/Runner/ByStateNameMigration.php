<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Runner;

use Antevemus\ASpecification\StateMachine\Exceptions\MachineMigrationException;
use Antevemus\ASpecification\StateMachine\MachineDefinition;
use Antevemus\ASpecification\StateMachine\MachineSnapshot;

/**
 * ByStateNameMigration - The Identity Migration: Same States, the Target Version (2.7.0)
 *
 * Built by MachineMigrations::byStateName(). Each region stays in the state it is in, by name; the
 * snapshot only changes its version to the target's (in the target's region order). Due instants and
 * paused balances are kept as they are: a deadline already materialized is not recomputed. When a
 * state the instance is in no longer exists in the target (or a region was added or removed), the
 * runner refuses the result (MachineMigrationException) and nothing is written: that migration needs
 * code (CallbackMachineMigration).
 *
 * It covers the common edit of a screen (a new transition, a guard parameter changed, a new state no
 * instance is in) with no code. MachineDefinition::diff() tells beforehand which states were removed.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Runner
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class ByStateNameMigration implements IDefinitionAwareMachineMigration
{
    /**
     * Without the target definition there is no version to move to: the runner calls migrateTo().
     *
     * @throws MachineMigrationException Always
     */
    public function migrate(MachineSnapshot $snapshot): MachineSnapshot
    {
        throw new MachineMigrationException(sprintf(
            'The by-state-name migration of machine "%s" v%d needs the target definition: register it with MachineRunner::registerMigration(), which calls migrateTo().',
            $snapshot->machineKey,
            $snapshot->machineVersion
        ));
    }

    /** {@inheritdoc} */
    public function migrateTo(MachineSnapshot $snapshot, MachineDefinition $target): MachineSnapshot
    {
        $states = $snapshot->getStates();
        $ordered = [];
        foreach ($target->regionNames() as $region) {
            if (isset($states[$region])) {
                $ordered[$region] = $states[$region];
            }
        }
        if (count($ordered) === count($states)) {
            $states = $ordered;
        }

        return new MachineSnapshot(
            $target->key,
            $target->version,
            $states,
            $snapshot->getDueAt(),
            $snapshot->isDone(),
            $snapshot->getPausedRemaining()
        );
    }
}
