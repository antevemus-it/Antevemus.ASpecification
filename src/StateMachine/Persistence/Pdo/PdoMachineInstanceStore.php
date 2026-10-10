<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\Pdo;

use Antevemus\ASpecification\Sql\SqlQueryVisitor;
use Antevemus\ASpecification\StateMachine\Exceptions\ConcurrencyConflictException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachineInstanceAlreadyExistsException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException;
use Antevemus\ASpecification\StateMachine\MachineSnapshot;
use Antevemus\ASpecification\StateMachine\Persistence\DueDeadline;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineInstanceStore;
use Antevemus\ASpecification\StateMachine\Persistence\MachineInstance;
use Antevemus\ASpecification\StateMachine\Persistence\PersistenceSupport;
use DateTimeImmutable;
use Throwable;

/**
 * PdoMachineInstanceStore - Instance Store over PDO
 *
 * Tables `state_machine_instance` (the snapshot as JSON, the truth) and `state_machine_region`
 * (the per-region projection: current state and due instant, rewritten on every save; no due
 * instant once the instance is done). The compare-and-set is a single
 * `UPDATE ... WHERE instance_id = :id AND version = :expected` whose row count decides
 * (forward 023, RN-08). findDue() compiles DueDeadline::specification() to SQL with the SQL
 * visitor in the connection's dialect (D18).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\Pdo
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoMachineInstanceStore implements IMachineInstanceStore
{
    public function __construct(private readonly PdoMachineSchema $schema)
    {
    }

    /** {@inheritdoc} */
    public function load(string $instanceId): ?MachineInstance
    {
        $rows = $this->schema->fetchAll(sprintf(
            'SELECT %s FROM %s WHERE %s = :instance_id',
            $this->schema->selectList(PdoMachineSchema::TABLE_COLUMNS['instance']),
            $this->schema->table('instance'),
            $this->schema->column('instance_id')
        ), [':instance_id' => $instanceId]);

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
            PersistenceSupport::fromText((string) $row['updated_at'])
        );
    }

    /** {@inheritdoc} */
    public function insert(MachineInstance $instance): void
    {
        if ($this->currentVersion($instance->instanceId) !== null) {
            throw new MachineInstanceAlreadyExistsException(sprintf('Machine instance "%s" already exists.', $instance->instanceId));
        }
        try {
            $this->schema->insert('instance', [
                'instance_id' => $instance->instanceId,
                'machine_key' => $instance->snapshot->machineKey,
                'machine_version' => $instance->snapshot->machineVersion,
                'version' => $instance->version,
                'snapshot' => PersistenceSupport::encodeJson($instance->snapshot->toArray()),
                'done' => $instance->snapshot->isDone() ? 'Y' : 'N',
                'created_at' => PersistenceSupport::toText($instance->createdAt),
                'updated_at' => PersistenceSupport::toText($instance->updatedAt),
            ]);
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
        $where = (new SqlQueryVisitor($this->schema->getDialect(), [
            DueDeadline::PROPERTY_DUE_AT => $this->schema->column('due_at'),
            DueDeadline::PROPERTY_MACHINE_KEY => $this->schema->column('machine_key'),
        ]))->translate(DueDeadline::specification($now, $machineKey));

        $sql = $this->schema->limit(sprintf(
            'SELECT %s FROM %s WHERE %s ORDER BY %s, %s, %s',
            $this->schema->selectList(['instance_id', 'machine_key', 'region', 'current_state', 'due_at']),
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
                PersistenceSupport::fromText((string) $row['due_at'])
            ),
            $this->schema->fetchAll($sql, $where->getParameters())
        );
    }

    /**
     * Writes the per-region projection of the snapshot (no due instant once the instance is done).
     */
    private function writeRegions(MachineInstance $instance): void
    {
        $order = 0;
        foreach ($instance->snapshot->getStates() as $region => $state) {
            $due = $instance->snapshot->isDone() ? null : $instance->snapshot->dueAt($region);
            $this->schema->insert('region', [
                'instance_id' => $instance->instanceId,
                'region' => $region,
                'machine_key' => $instance->snapshot->machineKey,
                'current_state' => $state,
                'due_at' => $due === null ? null : PersistenceSupport::toText($due),
                'sort_order' => $order++,
            ]);
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
