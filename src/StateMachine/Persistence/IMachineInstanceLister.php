<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use InvalidArgumentException;

/**
 * IMachineInstanceLister - Instances Pinned to a Definition Version, by Pages (2.7.0)
 *
 * Opt-in capability of an instance store, used by MachineRunner::migrateAll() to move the instances
 * of one version to another in batches: the ids of the instances of (machine key, version), in order
 * of the id (binary in memory, the column's collation in SQL), after a given id (keyset paging: a
 * page never skips nor repeats an instance that stays on the version). InMemoryMachineInstanceStore and PdoMachineInstanceStore implement it.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IMachineInstanceLister
{
    /**
     * Ids of the instances pinned to (machine key, version), in order of the id, strictly after
     * $after when given; at most $limit of them.
     *
     * @param string $machineKey Machine key
     * @param int $version Definition version the instances are pinned to
     * @param int $limit Maximum number of ids (>= 1)
     * @param string|null $after Last id of the previous page; null = from the start
     * @return list<string>
     * @throws InvalidArgumentException When the limit is below 1
     */
    public function idsByMachineVersion(string $machineKey, int $version, int $limit = 100, ?string $after = null): array;
}
