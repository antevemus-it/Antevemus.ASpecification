<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\InMemory;

use Antevemus\ASpecification\StateMachine\Exceptions\ConcurrencyConflictException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachineInstanceAlreadyExistsException;
use Antevemus\ASpecification\StateMachine\Persistence\DueDeadline;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineInstanceLister;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineInstanceStore;
use Antevemus\ASpecification\StateMachine\Persistence\ITenantScopedInstanceStore;
use Antevemus\ASpecification\StateMachine\Persistence\MachineInstance;
use Antevemus\ASpecification\StateMachine\Persistence\PersistenceSupport;
use DateTimeImmutable;
use InvalidArgumentException;
use stdClass;

/**
 * InMemoryMachineInstanceStore - Instance Store in Memory
 *
 * Same contract as PdoMachineInstanceStore (forward 023, D14): insert, compare-and-set by
 * version, and findDue() evaluating DueDeadline::specification() over the same per-region
 * projection the PDO store keeps in its region table (no due instant once the instance is done;
 * a paused region has no due instant either, so it is never due, forward 025, D11).
 *
 * Since 2.7.0 it keeps the tenant of each instance (ITenantScopedInstanceStore: loadForTenant() and
 * findDueForTenant(), the same specification with the tenant) and lists the instances of a
 * definition version by pages (IMachineInstanceLister), as the PDO store does.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\InMemory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryMachineInstanceStore implements IMachineInstanceStore, IInMemoryTransactional, ITenantScopedInstanceStore, IMachineInstanceLister
{
    /** @var array<string, MachineInstance> */
    private array $instances = [];

    /** {@inheritdoc} */
    public function load(string $instanceId): ?MachineInstance
    {
        return $this->instances[$instanceId] ?? null;
    }

    /** {@inheritdoc} */
    public function insert(MachineInstance $instance): void
    {
        if (isset($this->instances[$instance->instanceId])) {
            throw new MachineInstanceAlreadyExistsException(sprintf('Machine instance "%s" already exists.', $instance->instanceId));
        }
        $this->instances[$instance->instanceId] = $instance;
    }

    /** {@inheritdoc} */
    public function compareAndSet(MachineInstance $instance, int $expectedVersion): void
    {
        $current = $this->instances[$instance->instanceId] ?? null;
        if ($current === null || $current->version !== $expectedVersion) {
            throw new ConcurrencyConflictException($instance->instanceId, $expectedVersion, $current?->version);
        }
        $this->instances[$instance->instanceId] = $instance;
    }

    /** {@inheritdoc} */
    public function findDue(DateTimeImmutable $now, ?string $machineKey = null, int $limit = 100): array
    {
        return $this->findDueWhere($now, $machineKey, null, $limit);
    }

    /** {@inheritdoc} */
    public function loadForTenant(string $instanceId, string $tenant): ?MachineInstance
    {
        $instance = $this->instances[$instanceId] ?? null;

        return $instance !== null && $instance->tenant === $tenant ? $instance : null;
    }

    /** {@inheritdoc} */
    public function findDueForTenant(DateTimeImmutable $now, string $tenant, ?string $machineKey = null, int $limit = 100): array
    {
        return $this->findDueWhere($now, $machineKey, $tenant, $limit);
    }

    /** {@inheritdoc} */
    public function idsByMachineVersion(string $machineKey, int $version, int $limit = 100, ?string $after = null): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('The limit must be at least 1, %d given.', $limit));
        }
        $ids = [];
        foreach ($this->instances as $id => $instance) {
            $id = (string) $id;
            if ($instance->snapshot->machineKey === $machineKey && $instance->snapshot->machineVersion === $version
                && ($after === null || strcmp($id, $after) > 0)) {
                $ids[] = $id;
            }
        }
        usort($ids, strcmp(...));

        return array_slice($ids, 0, $limit);
    }

    /**
     * DueDeadline::specification() evaluated over the per-region projection.
     *
     * @return list<DueDeadline>
     */
    private function findDueWhere(DateTimeImmutable $now, ?string $machineKey, ?string $tenant, int $limit): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('The limit must be at least 1, %d given.', $limit));
        }
        $specification = DueDeadline::specification($now, $machineKey, $tenant);
        $rows = [];
        foreach ($this->instances as $instance) {
            foreach (self::projection($instance) as $row) {
                if ($specification->isSatisfiedBy($row)) {
                    $rows[] = $row;
                }
            }
        }
        // Binary string order, as the SQL ORDER BY under a binary collation (numeric-looking ids too).
        usort($rows, static fn(stdClass $a, stdClass $b): int => strcmp((string) $a->dueAt, (string) $b->dueAt)
            ?: strcmp($a->instanceId, $b->instanceId)
            ?: strcmp($a->region, $b->region));

        return array_map(
            static fn(stdClass $r): DueDeadline => new DueDeadline($r->instanceId, $r->machineKey, $r->region, $r->state, PersistenceSupport::fromText($r->dueAt), $r->tenant),
            array_slice($rows, 0, $limit)
        );
    }

    /**
     * The per-region projection of an instance (what the PDO region table holds).
     *
     * @return list<stdClass>
     */
    private static function projection(MachineInstance $instance): array
    {
        $rows = [];
        foreach ($instance->snapshot->getStates() as $region => $state) {
            $due = $instance->snapshot->isDone() ? null : $instance->snapshot->dueAt($region);
            $paused = $instance->snapshot->isDone() ? null : $instance->snapshot->pausedRemaining($region);
            $row = new stdClass();
            $row->instanceId = $instance->instanceId;
            $row->machineKey = $instance->snapshot->machineKey;
            $row->region = $region;
            $row->state = $state;
            $row->dueAt = $due === null ? null : PersistenceSupport::toText($due);
            $row->pausedRemaining = $paused;
            $row->tenant = $instance->tenant;
            $rows[] = $row;
        }

        return $rows;
    }

    /** {@inheritdoc} */
    public function exportState(): array
    {
        return $this->instances;
    }

    /** {@inheritdoc} */
    public function importState(array $state): void
    {
        $this->instances = $state;
    }
}
