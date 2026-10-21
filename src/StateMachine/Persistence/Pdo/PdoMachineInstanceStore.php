<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\Pdo;

use Antevemus\ASpecification\Sql\SqlQueryVisitor;
use Antevemus\ASpecification\StateMachine\DefinitionSupport;
use Antevemus\ASpecification\StateMachine\Exceptions\ConcurrencyConflictException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachineInstanceAlreadyExistsException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException;
use Antevemus\ASpecification\StateMachine\MachineSnapshot;
use Antevemus\ASpecification\StateMachine\Persistence\DueDeadline;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineInstanceLister;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineInstanceStore;
use Antevemus\ASpecification\StateMachine\Persistence\ITenantScopedInstanceStore;
use Antevemus\ASpecification\StateMachine\Persistence\MachineInstance;
use Antevemus\ASpecification\StateMachine\Persistence\PersistenceSupport;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * PdoMachineInstanceStore - Instance Store over PDO
 *
 * Tables `state_machine_instance` (the snapshot as JSON, the truth) and `state_machine_region`
 * (the per-region projection: current state, due instant and, since 1.9.0, the remaining seconds
 * of a paused deadline, rewritten on every save; neither once the instance is done). A paused
 * region has no due instant, so findDue() never returns it (forward 025, D11). The column
 * `paused_remaining` is only written for a paused region, so a database created by the 1.8 DDL
 * keeps working until a definition pauses a deadline (then the column must be added, see the DDL
 * header). The compare-and-set is a single
 * `UPDATE ... WHERE instance_id = :id AND version = :expected` whose row count decides
 * (forward 023, RN-08). findDue() compiles DueDeadline::specification() to SQL with the SQL
 * visitor in the connection's dialect (D18).
 *
 * Since 2.7.0, with the opt-in columns mapped (PdoMachineSchema::COLUMNS_SINCE_2_7):
 * - the tenant of the instance goes to `state_machine_instance.tenant` and to the projection
 *   `state_machine_region.tenant`, so findDueForTenant() filters without a join and loadForTenant()
 *   never crosses tenants (ITenantScopedInstanceStore); without the column an instance without tenant
 *   is written as before, and one with a tenant is refused naming the column;
 * - the legacy code of the current state of each region goes to
 *   `state_machine_region.current_state_code`, derived from the definition the instance is pinned to
 *   (so the store needs the definition repository: mapping the column without it is refused on
 *   construction), as an integer when the code is one, so the column can be INTEGER with a foreign
 *   key to the legacy table.
 * It also lists the instances of a definition version by pages (IMachineInstanceLister).
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\Pdo
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoMachineInstanceStore implements IMachineInstanceStore, ITenantScopedInstanceStore, IMachineInstanceLister
{
    /**
     * @param PdoMachineSchema $schema Tables, column map and statements
     * @param IMachineDefinitionRepository|null $definitions Definitions the instances are pinned to;
     *                                                      required when `current_state_code` is mapped (2.7.0)
     * @throws InvalidArgumentException When `current_state_code` is mapped without a definition repository
     */
    public function __construct(
        private readonly PdoMachineSchema $schema,
        private readonly ?IMachineDefinitionRepository $definitions = null
    ) {
        if ($schema->hasColumn('current_state_code') && $definitions === null) {
            throw new InvalidArgumentException(
                'The column current_state_code is mapped, but the instance store has no definition repository to derive the state codes from: pass it (MachinePersistence::pdo(..., definitions: $repository)).'
            );
        }
    }

    /** {@inheritdoc} */
    public function load(string $instanceId): ?MachineInstance
    {
        return $this->loadWhere($instanceId, null);
    }

    /** {@inheritdoc} */
    public function loadForTenant(string $instanceId, string $tenant): ?MachineInstance
    {
        $this->requireTenantColumn('loadForTenant()');

        return $this->loadWhere($instanceId, $tenant);
    }

    /** {@inheritdoc} */
    public function insert(MachineInstance $instance): void
    {
        $this->assertTenantStorable($instance);
        if ($this->currentVersion($instance->instanceId) !== null) {
            throw new MachineInstanceAlreadyExistsException(sprintf('Machine instance "%s" already exists.', $instance->instanceId));
        }
        $row = [
            'instance_id' => $instance->instanceId,
            'machine_key' => $instance->snapshot->machineKey,
            'machine_version' => $instance->snapshot->machineVersion,
            'version' => $instance->version,
            'snapshot' => PersistenceSupport::encodeJson($instance->snapshot->toArray()),
            'done' => $instance->snapshot->isDone() ? 'Y' : 'N',
            'created_at' => PersistenceSupport::toText($instance->createdAt),
            'updated_at' => PersistenceSupport::toText($instance->updatedAt),
        ];
        if ($this->schema->hasColumn('tenant')) {
            $row['tenant'] = $instance->tenant;
        }
        try {
            $this->schema->insert('instance', $row);
        } catch (MachinePersistenceException $e) {
            if ($e->isIntegrityViolation()) {
                throw new MachineInstanceAlreadyExistsException(sprintf('Machine instance "%s" already exists.', $instance->instanceId), 0, $e);
            }
            throw $e;
        }
        $this->writeRegions($instance);
    }

    /** {@inheritdoc} */
    public function compareAndSet(MachineInstance $instance, int $expectedVersion): void
    {
        $this->assertTenantStorable($instance);
        $statement = $this->schema->execute(sprintf(
            'UPDATE %s SET %s = :machine_key, %s = :machine_version, %s = :version, %s = :snapshot, %s = :done, %s = :updated_at WHERE %s = :instance_id AND %s = :expected_version',
            $this->schema->table('instance'),
            $this->schema->column('machine_key'),
            $this->schema->column('machine_version'),
            $this->schema->column('version'),
            $this->schema->column('snapshot'),
            $this->schema->column('done'),
            $this->schema->column('updated_at'),
            $this->schema->column('instance_id'),
            $this->schema->column('version')
        ), [
            ':machine_key' => $instance->snapshot->machineKey,
            ':machine_version' => $instance->snapshot->machineVersion,
            ':version' => $instance->version,
            ':snapshot' => PersistenceSupport::encodeJson($instance->snapshot->toArray()),
            ':done' => $instance->snapshot->isDone() ? 'Y' : 'N',
            ':updated_at' => PersistenceSupport::toText($instance->updatedAt),
            ':instance_id' => $instance->instanceId,
            ':expected_version' => $expectedVersion,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new ConcurrencyConflictException($instance->instanceId, $expectedVersion, $this->currentVersion($instance->instanceId));
        }

        $this->schema->execute(
            sprintf('DELETE FROM %s WHERE %s = :instance_id', $this->schema->table('region'), $this->schema->column('instance_id')),
            [':instance_id' => $instance->instanceId]
        );
        $this->writeRegions($instance);
    }

    /** {@inheritdoc} */
    public function findDue(DateTimeImmutable $now, ?string $machineKey = null, int $limit = 100): array
    {
        return $this->findDueWhere($now, $machineKey, null, $limit);
    }

    /** {@inheritdoc} */
    public function findDueForTenant(DateTimeImmutable $now, string $tenant, ?string $machineKey = null, int $limit = 100): array
    {
        $this->requireTenantColumn('findDueForTenant()');

        return $this->findDueWhere($now, $machineKey, $tenant, $limit);
    }

    /** {@inheritdoc} */
    public function idsByMachineVersion(string $machineKey, int $version, int $limit = 100, ?string $after = null): array
    {
        $params = [':machine_key' => $machineKey, ':machine_version' => $version];
        $where = sprintf('%s = :machine_key AND %s = :machine_version', $this->schema->column('machine_key'), $this->schema->column('machine_version'));
        if ($after !== null) {
            $where .= sprintf(' AND %s > :after', $this->schema->column('instance_id'));
            $params[':after'] = $after;
        }
        $sql = $this->schema->limit(sprintf(
            'SELECT %s FROM %s WHERE %s ORDER BY %s',
            $this->schema->selectList(['instance_id']),
            $this->schema->table('instance'),
            $where,
            $this->schema->column('instance_id')
        ), $limit);

        return array_map(static fn(array $row): string => (string) $row['instance_id'], $this->schema->fetchAll($sql, $params));
    }

    /**
     * Loads an instance by id, confined to a tenant when one is given.
     */
    private function loadWhere(string $instanceId, ?string $tenant): ?MachineInstance
    {
        $columns = PdoMachineSchema::TABLE_COLUMNS['instance'];
        if ($this->schema->hasColumn('tenant')) {
            $columns[] = 'tenant';
        }
        $where = sprintf('%s = :instance_id', $this->schema->column('instance_id'));
        $params = [':instance_id' => $instanceId];
        if ($tenant !== null) {
            $where .= sprintf(' AND %s = :tenant', $this->schema->column('tenant'));
            $params[':tenant'] = $tenant;
        }
        $rows = $this->schema->fetchAll(sprintf(
            'SELECT %s FROM %s WHERE %s',
            $this->schema->selectList($columns),
            $this->schema->table('instance'),
            $where
        ), $params);

        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        try {
            $snapshot = MachineSnapshot::fromArray(PersistenceSupport::decodeJson((string) $row['snapshot'], 'snapshot'));
        } catch (MachinePersistenceException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new MachinePersistenceException(sprintf('Stored snapshot of machine instance "%s" is malformed: %s', $instanceId, $e->getMessage()), 0, $e);
        }

        return new MachineInstance(
            (string) $row['instance_id'],
            $snapshot,
            (int) $row['version'],
            PersistenceSupport::fromText((string) $row['created_at']),
            PersistenceSupport::fromText((string) $row['updated_at']),
            isset($row['tenant']) ? (string) $row['tenant'] : null
        );
    }

    /**
     * The SQL of DueDeadline::specification() over the region table (the tenant read, and filtered
     * when given, only with the column mapped).
     *
     * @return list<DueDeadline>
     */
    private function findDueWhere(DateTimeImmutable $now, ?string $machineKey, ?string $tenant, int $limit): array
    {
        $properties = [
            DueDeadline::PROPERTY_DUE_AT => $this->schema->column('due_at'),
            DueDeadline::PROPERTY_MACHINE_KEY => $this->schema->column('machine_key'),
        ];
        $columns = ['instance_id', 'machine_key', 'region', 'current_state', 'due_at'];
        if ($this->schema->hasColumn('tenant')) {
            $properties[DueDeadline::PROPERTY_TENANT] = $this->schema->column('tenant');
            $columns[] = 'tenant';
        }
        $where = (new SqlQueryVisitor($this->schema->getDialect(), $properties))->translate(DueDeadline::specification($now, $machineKey, $tenant));

        $sql = $this->schema->limit(sprintf(
            'SELECT %s FROM %s WHERE %s ORDER BY %s, %s, %s',
            $this->schema->selectList($columns),
            $this->schema->table('region'),
            $where->toSql(),
            $this->schema->column('due_at'),
            $this->schema->column('instance_id'),
            $this->schema->column('region')
        ), $limit);

        return array_map(
            static fn(array $row): DueDeadline => new DueDeadline(
                (string) $row['instance_id'],
                (string) $row['machine_key'],
                (string) $row['region'],
                (string) $row['current_state'],
                PersistenceSupport::fromText((string) $row['due_at']),
                isset($row['tenant']) ? (string) $row['tenant'] : null
            ),
            $this->schema->fetchAll($sql, $where->getParameters())
        );
    }

    /**
     * Writes the per-region projection of the snapshot (no due instant nor paused balance once the
     * instance is done). `paused_remaining` is only in the column list of a paused region's row; the
     * tenant and the state code (2.7.0) only when their columns are mapped.
     */
    private function writeRegions(MachineInstance $instance): void
    {
        $order = 0;
        $done = $instance->snapshot->isDone();
        $definition = $this->schema->hasColumn('current_state_code')
            ? $this->definitions?->get($instance->snapshot->machineKey, $instance->snapshot->machineVersion)
            : null;
        foreach ($instance->snapshot->getStates() as $region => $state) {
            $due = $done ? null : $instance->snapshot->dueAt($region);
            $paused = $done ? null : $instance->snapshot->pausedRemaining($region);
            $row = [
                'instance_id' => $instance->instanceId,
                'region' => $region,
                'machine_key' => $instance->snapshot->machineKey,
                'current_state' => $state,
                'due_at' => $due === null ? null : PersistenceSupport::toText($due),
                'sort_order' => $order++,
            ];
            if ($paused !== null) {
                $row['paused_remaining'] = $paused;
            }
            if ($this->schema->hasColumn('tenant')) {
                $row['tenant'] = $instance->tenant;
            }
            if ($definition !== null) {
                $code = $definition->codeOf($region, $state);
                $row['current_state_code'] = $code === null ? null : DefinitionSupport::normalizeCode($code);
            }
            $this->schema->insert('region', $row);
        }
    }

    /**
     * @throws MachinePersistenceException When the instance has a tenant and the column is not mapped
     */
    private function assertTenantStorable(MachineInstance $instance): void
    {
        if ($instance->tenant !== null && !$this->schema->hasColumn('tenant')) {
            throw new MachinePersistenceException(sprintf(
                'Machine instance "%s" belongs to tenant "%s", but the column tenant (state_machine_instance.tenant and state_machine_region.tenant) is not mapped: run the additive migration of the DDL and pass columns: PdoMachineSchema::COLUMNS_SINCE_2_7.',
                $instance->instanceId,
                $instance->tenant
            ));
        }
    }

    /**
     * @throws MachinePersistenceException When the tenant column is not mapped
     */
    private function requireTenantColumn(string $method): void
    {
        if (!$this->schema->hasColumn('tenant')) {
            throw new MachinePersistenceException(sprintf(
                '%s needs the column tenant (state_machine_instance.tenant and state_machine_region.tenant), which is not mapped: run the additive migration of the DDL and pass columns: PdoMachineSchema::COLUMNS_SINCE_2_7.',
                $method
            ));
        }
    }

    private function currentVersion(string $instanceId): ?int
    {
        $rows = $this->schema->fetchAll(sprintf(
            'SELECT %s FROM %s WHERE %s = :instance_id',
            $this->schema->selectList(['version']),
            $this->schema->table('instance'),
            $this->schema->column('instance_id')
        ), [':instance_id' => $instanceId]);

        return $rows === [] ? null : (int) $rows[0]['version'];
    }
}
