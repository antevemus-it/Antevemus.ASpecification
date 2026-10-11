<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents\State;

use Antevemus\ASpecification\Contracts\Sql\ISqlDialect;
use Antevemus\ASpecification\DomainEvents\Exceptions\DomainEventPersistenceException;
use Antevemus\ASpecification\DomainEvents\Outbox\DomainEventSupport;
use Antevemus\ASpecification\Sql\Dialects\AbstractSqlDialect;
use Antevemus\ASpecification\Sql\Dialects\SqlDialectFactory;
use Antevemus\ASpecification\Sql\SqlDialect;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * PdoTriggerStateStore - Trigger States over PDO
 *
 * Table `domain_event_trigger_state` of `resources/sql/domain_events.ansi.sql` (forward 030, D2), in
 * the conventions of PdoDomainEventOutbox:
 * - Portable, parameterized SQL; the table name (and optional schema) validated as plain
 *   identifiers; named placeholders used once each (safe under native prepares)
 * - compareAndSet() with a version is one `UPDATE ... SET version = version + 1 WHERE <key> AND
 *   version = :expected`, and wins only when it changed exactly one row; compareAndDelete() is the
 *   same `DELETE`. Under concurrency the database serializes the two writers on the row: the
 *   second one finds the new version and changes nothing
 * - compareAndSet() without a version (first record of a row) checks that the row is absent, then
 *   inserts; when another writer inserted it in between, the primary key rejects the INSERT and the
 *   call returns false (an integrity error while the row is still absent propagates). On PostgreSQL
 *   inside a transaction the INSERT runs under a savepoint, so the rejected INSERT does not abort
 *   the caller's transaction. Under REPEATABLE READ or SERIALIZABLE (PostgreSQL) a race may surface
 *   as an exception instead (serialization failure): the work is rolled back and can be retried; it
 *   never publishes twice
 * - `satisfied` is stored as 0 or 1 (SMALLINT), instants in UTC as fixed-width text; the error mode
 *   of the connection is left untouched: failed statements are detected both ways and reported as
 *   DomainEventPersistenceException with the driver message and SQLSTATE
 * - findSatisfied() pages by entity id with `entity_id > :after ORDER BY entity_id` (keyset); the
 *   order is the column collation's. On MySQL declare the text columns with a binary collation
 *   (`utf8mb4_bin`) when two ids may differ only by case or accents
 *
 * Share the PDO connection (and its transaction manager) with the outbox, so that a crossing's
 * state and its event commit or roll back together.
 *
 * @version    1.12.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents\State
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoTriggerStateStore implements ITriggerStateStore
{
    /** Default table name, mirroring the reference DDL. */
    public const DEFAULT_TABLE = 'domain_event_trigger_state';

    /** Columns of the table, in DDL order. */
    public const COLUMNS = ['trigger_name', 'entity_class', 'entity_id', 'satisfied', 'evaluated_at', 'version'];

    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private const SAVEPOINT = 'aspec_trigger_state';

    private readonly string $table;

    private readonly ISqlDialect $dialect;

    private readonly bool $postgres;

    private readonly bool $mysql;

    /**
     * @param PDO $pdo Connection to the database holding the table (its error mode is left untouched)
     * @param string $table Table name
     * @param string $schema Optional schema (or database) qualifying the table; '' = unqualified
     * @throws InvalidArgumentException When the table or the schema is not a plain SQL identifier
     */
    public function __construct(
        private readonly PDO $pdo,
        string $table = self::DEFAULT_TABLE,
        string $schema = ''
    ) {
        foreach (['table' => $table, 'schema' => $schema] as $what => $name) {
            if (($what === 'table' || $name !== '') && preg_match(self::IDENTIFIER, $name) !== 1) {
                throw new InvalidArgumentException(sprintf('Trigger state store %s "%s" is not a plain SQL identifier.', $what, $name));
            }
        }
        $this->table = $schema !== '' ? $schema . '.' . $table : $table;
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->dialect = SqlDialectFactory::create(SqlDialect::fromDriver($driver));
        $this->postgres = $driver === 'pgsql';
        $this->mysql = $driver === 'mysql';
    }

    /** The connection. */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /** Schema-qualified table name. */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * {@inheritdoc}
     *
     * Inside a transaction on MySQL this is a locking read (`FOR UPDATE`): under REPEATABLE READ a
     * plain read would return the transaction's snapshot, not the row another writer just committed,
     * and a writer that lost a compare-and-set reads the row again to decide on its new value.
     */
    public function find(string $triggerName, string $entityClass, string $entityId): ?TriggerState
    {
        return $this->read($triggerName, $entityClass, $entityId, $this->mysql && $this->pdo->inTransaction());
    }

    /** {@inheritdoc} */
    public function findByEntity(string $entityClass, string $entityId): array
    {
        $found = [];
        foreach ($this->fetchAll(sprintf(
            'SELECT %s FROM %s WHERE entity_class = :entity_class AND entity_id = :entity_id ORDER BY trigger_name',
            implode(', ', self::COLUMNS),
            $this->table
        ), [':entity_class' => $entityClass, ':entity_id' => $entityId]) as $row) {
            $state = $this->hydrate($row);
            $found[$state->triggerName] = $state;
        }

        return $found;
    }

    /** {@inheritdoc} */
    public function findMany(string $triggerName, string $entityClass, array $entityIds): array
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique(array_map('strval', $entityIds))), 500) as $chunk) {
            $params = [':trigger_name' => $triggerName, ':entity_class' => $entityClass];
            foreach ($chunk as $i => $id) {
                $params[':id' . $i] = $id;
            }
            $rows = $this->fetchAll(sprintf(
                'SELECT %s FROM %s WHERE trigger_name = :trigger_name AND entity_class = :entity_class AND entity_id IN (%s)',
                implode(', ', self::COLUMNS),
                $this->table,
                implode(', ', array_slice(array_keys($params), 2))
            ), $params);
            foreach ($rows as $row) {
                $state = $this->hydrate($row);
                $found[$state->entityId] = $state;
            }
        }

        return $found;
    }

    /** {@inheritdoc} */
    public function findSatisfied(string $triggerName, string $entityClass, int $limit, ?string $afterEntityId = null): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('The limit must be at least 1, %d given.', $limit));
        }
        $params = [':trigger_name' => $triggerName, ':entity_class' => $entityClass];
        $after = '';
        if ($afterEntityId !== null) {
            $after = ' AND entity_id > :after';
            $params[':after'] = $afterEntityId;
        }
        $sql = sprintf(
            'SELECT %s FROM %s WHERE trigger_name = :trigger_name AND entity_class = :entity_class AND satisfied = 1%s ORDER BY entity_id',
            implode(', ', self::COLUMNS),
            $this->table,
            $after
        );
        if ($this->dialect instanceof AbstractSqlDialect) {
            $sql = $this->dialect->paginate($sql, $limit);
        }

        return array_slice(array_map($this->hydrate(...), $this->fetchAll($sql, $params)), 0, $limit);
    }

    /** {@inheritdoc} */
    public function compareAndSet(
        string $triggerName,
        string $entityClass,
        string $entityId,
        ?int $expectedVersion,
        bool $satisfied,
        DateTimeImmutable $evaluatedAt
    ): bool {
        TriggerState::assertKey($triggerName, $entityClass, $entityId);
        $key = [':trigger_name' => $triggerName, ':entity_class' => $entityClass, ':entity_id' => $entityId];
        if ($expectedVersion !== null) {
            $statement = $this->execute(sprintf(
                'UPDATE %s SET satisfied = :satisfied, evaluated_at = :evaluated_at, version = version + 1'
                . ' WHERE trigger_name = :trigger_name AND entity_class = :entity_class AND entity_id = :entity_id AND version = :expected',
                $this->table
            ), [':satisfied' => $satisfied ? 1 : 0, ':evaluated_at' => DomainEventSupport::toText($evaluatedAt)] + $key + [':expected' => $expectedVersion]);

            return $statement->rowCount() === 1;
        }

        // A plain read first (no lock on an absent row, which on MySQL would be a gap lock)
        if ($this->read($triggerName, $entityClass, $entityId, false) !== null) {
            return false;
        }
        $savepoint = $this->postgres && $this->pdo->inTransaction();
        if ($savepoint) {
            $this->execute('SAVEPOINT ' . self::SAVEPOINT);
        }
        try {
            $this->execute(sprintf(
                'INSERT INTO %s (%s) VALUES (:trigger_name, :entity_class, :entity_id, :satisfied, :evaluated_at, 1)',
                $this->table,
                implode(', ', self::COLUMNS)
            ), $key + [':satisfied' => $satisfied ? 1 : 0, ':evaluated_at' => DomainEventSupport::toText($evaluatedAt)]);
        } catch (DomainEventPersistenceException $e) {
            if ($savepoint) {
                $this->execute('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
            }
            // Another writer recorded the row since it was read absent: the compare failed. Any
            // other integrity error (a CHECK of a consumer table) is not a race and propagates
            if ($e->isIntegrityViolation() && $this->find($triggerName, $entityClass, $entityId) !== null) {
                return false;
            }
            throw $e;
        }
        if ($savepoint) {
            $this->execute('RELEASE SAVEPOINT ' . self::SAVEPOINT);
        }

        return true;
    }

    /** {@inheritdoc} */
    public function compareAndDelete(string $triggerName, string $entityClass, string $entityId, int $expectedVersion): bool
    {
        return $this->execute(sprintf(
            'DELETE FROM %s WHERE trigger_name = :trigger_name AND entity_class = :entity_class AND entity_id = :entity_id AND version = :expected',
            $this->table
        ), [
            ':trigger_name' => $triggerName,
            ':entity_class' => $entityClass,
            ':entity_id' => $entityId,
            ':expected' => $expectedVersion,
        ])->rowCount() === 1;
    }

    /** {@inheritdoc} */
    public function count(): int
    {
        return (int) $this->execute(sprintf('SELECT COUNT(*) FROM %s', $this->table))->fetchColumn();
    }

    /**
     * One row by key, optionally with a locking read.
     */
    private function read(string $triggerName, string $entityClass, string $entityId, bool $forUpdate): ?TriggerState
    {
        $rows = $this->fetchAll(sprintf(
            'SELECT %s FROM %s WHERE trigger_name = :trigger_name AND entity_class = :entity_class AND entity_id = :entity_id%s',
            implode(', ', self::COLUMNS),
            $this->table,
            $forUpdate ? ' FOR UPDATE' : ''
        ), [':trigger_name' => $triggerName, ':entity_class' => $entityClass, ':entity_id' => $entityId]);

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): TriggerState
    {
        try {
            return new TriggerState(
                (string) $row['trigger_name'],
                (string) $row['entity_class'],
                (string) $row['entity_id'],
                (int) $row['satisfied'] === 1,
                DomainEventSupport::fromText((string) $row['evaluated_at']),
                (int) $row['version']
            );
        } catch (InvalidArgumentException $e) {
            throw new DomainEventPersistenceException('Stored trigger state is invalid: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->execute($sql, $params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return array_map(static fn(array $row): array => array_change_key_case($row, CASE_LOWER), $rows);
    }

    /**
     * @param array<string, mixed> $params
     * @throws DomainEventPersistenceException
     */
    private function execute(string $sql, array $params = []): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
            if (!$statement instanceof PDOStatement) {
                throw $this->driverError($this->pdo->errorInfo());
            }
            foreach ($params as $name => $value) {
                $statement->bindValue($name, $value, match (true) {
                    $value === null => PDO::PARAM_NULL,
                    is_int($value) => PDO::PARAM_INT,
                    default => PDO::PARAM_STR,
                });
            }
            if (!$statement->execute()) {
                throw $this->driverError($statement->errorInfo());
            }
        } catch (PDOException $e) {
            $info = $e->errorInfo;
            throw new DomainEventPersistenceException(
                'Trigger state store statement failed: ' . $e->getMessage(),
                0,
                $e,
                is_array($info) && isset($info[0]) ? (string) $info[0] : (is_string($e->getCode()) ? $e->getCode() : null)
            );
        }

        return $statement;
    }

    /**
     * @param array<int, mixed> $errorInfo PDO errorInfo() triple
     */
    private function driverError(array $errorInfo): DomainEventPersistenceException
    {
        return new DomainEventPersistenceException(
            sprintf('Trigger state store statement failed: [%s] %s', (string) ($errorInfo[0] ?? ''), (string) ($errorInfo[2] ?? 'unknown driver error')),
            0,
            null,
            isset($errorInfo[0]) ? (string) $errorInfo[0] : null
        );
    }
}
