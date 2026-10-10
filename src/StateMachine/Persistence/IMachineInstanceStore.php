<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use Antevemus\ASpecification\StateMachine\Exceptions\ConcurrencyConflictException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachineInstanceAlreadyExistsException;
use DateTimeImmutable;

/**
 * IMachineInstanceStore - Port of the Instance Snapshots (Compare-and-Set)
 *
 * Holds one MachineInstance per id (forward 023, D13, D14): the snapshot pinned to its machine
 * version (D16) and the optimistic lock version. Writes are an insert at start and a
 * compare-and-set afterwards (RN-08): the store never overwrites a version it was not told to
 * expect. Deadlines (D18) are searchable through findDue(); a store keeps, per region, the due
 * instant of the snapshot (none once the instance is done).
 *
 * Every method runs inside the caller's transaction (ITransactionManager); the store never opens
 * nor commits one.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IMachineInstanceStore
{
    /**
     * Loads an instance, or null when the id is unknown.
     *
     * @param string $instanceId Instance id
     * @return MachineInstance|null
     */
    public function load(string $instanceId): ?MachineInstance;

    /**
     * Inserts a new instance (version 1 at start).
     *
     * @param MachineInstance $instance Instance to insert
     * @throws MachineInstanceAlreadyExistsException When the id is taken
     */
    public function insert(MachineInstance $instance): void;

    /**
     * Replaces the stored instance when, and only when, its stored version equals $expectedVersion.
     *
     * @param MachineInstance $instance New state of the instance (normally version $expectedVersion + 1)
     * @param int $expectedVersion Version the writer read
     * @throws ConcurrencyConflictException When the stored version differs (or the instance vanished)
     */
    public function compareAndSet(MachineInstance $instance, int $expectedVersion): void;

    /**
     * Regions whose deadline is due at $now (`due_at <= now`), ordered by due instant, instance id
     * and region; at most $limit of them.
     *
     * @param DateTimeImmutable $now Reference instant
     * @param string|null $machineKey Only instances of this machine, when given
     * @param int $limit Maximum number of rows (>= 1)
     * @return list<DueDeadline>
     */
    public function findDue(DateTimeImmutable $now, ?string $machineKey = null, int $limit = 100): array;
}
