<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Runner;

use Antevemus\ASpecification\StateMachine\MachineSnapshot;

/**
 * IMachineMigration - Explicit Migration of an Instance to Another Definition Version
 *
 * An instance stays pinned to the version it was born with (forward 023, D16). Moving it to
 * another version is explicit: a migration registered for (key, from, to) maps the old snapshot
 * to a snapshot of the target version (same key, target version, the target's regions, states
 * the target declares; due instants as the migration decides). The runner checks the result
 * against the target definition, which it validates first, and writes the migration to the
 * history. Pure: no I/O, no clock.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Runner
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IMachineMigration
{
    /**
     * Maps a snapshot of the source version to a snapshot of the target version.
     *
     * @param MachineSnapshot $snapshot Snapshot pinned to the source version
     * @return MachineSnapshot Snapshot pinned to the target version
     */
    public function migrate(MachineSnapshot $snapshot): MachineSnapshot;
}
