<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\Pdo;

use Antevemus\ASpecification\Contracts\Sql\ISqlDialect;
use Antevemus\ASpecification\Sql\Dialects\AbstractSqlDialect;
use Antevemus\ASpecification\Sql\Dialects\SqlDialectFactory;
use Antevemus\ASpecification\Sql\SqlDialect;
use Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * PdoMachineSchema - Tables, Column Map and Statement Helper of the PDO Stores
 *
 * Shared by every PDO store of the state machine runner (forward 023, D14): the six tables of
 * `resources/sql/state_machine.ansi.sql` by role, the column map and the parameterized statement
 * helper, in the conventions of PdoRuleCatalog:
 * - Portable SQL, parameterized; unquoted identifiers validated against `[A-Za-z_][A-Za-z0-9_]*`;
 *   named placeholders used once each (safe under native prepares)
 * - Optional schema (or database) qualifying every table; table names by role
 *   (`instance`, `region`, `history`, `outbox`, `idempotency`, `rejection`)
 * - Column map: `$columns` maps a canonical column (the English name of the reference DDL) to the
 *   real column of the consumer table; each real column is selected under its canonical alias. A
 *   canonical column shared by several tables (`instance_id`, `machine_key`, `region`,
 *   `sort_order`, `status`...) maps to the same real name in all of them
 * - The SQL dialect (for the findDue predicate compiled from a specification, and for row limits)
 *   follows the PDO driver name
 * - The PDO error mode is left untouched: failed statements are detected both ways and reported
 *   as MachinePersistenceException with the driver message and SQLSTATE
 *
 * The findDue() predicate goes through the SQL visitor, which quotes identifiers: with a column
 * map, give the real names exactly as the catalog stores them (lowercase for unquoted DDL in
 * PostgreSQL).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\Pdo
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoMachineSchema
{
    /** Default table names by role, mirroring the reference DDL. */
    public const DEFAULT_TABLES = [
        'instance' => 'state_machine_instance',
        'region' => 'state_machine_region',
        'history' => 'state_machine_history',
        'outbox' => 'state_machine_outbox',
        'idempotency' => 'state_machine_idempotency',
        'rejection' => 'state_machine_rejection',
    ];

    /** Canonical columns of each table, in DDL order. */
    public const TABLE_COLUMNS = [
        'instance' => [
            'instance_id', 'machine_key', 'machine_version', 'version', 'snapshot', 'done', 'created_at', 'updated_at',
        ],
        'region' => ['instance_id', 'region', 'machine_key', 'current_state', 'due_at', 'sort_order'],
        'history' => [
            'instance_id', 'instance_version', 'sort_order', 'machine_key', 'machine_version', 'region',
            'from_state', 'to_state', 'event_type', 'transition_id', 'payload', 'actor', 'idempotency_key',
            'event_origin', 'occurred_at',
        ],
        'outbox' => [
            'instance_id', 'instance_version', 'sort_order', 'machine_key', 'machine_version', 'action_name',
            'params', 'region', 'phase', 'state_name', 'transition_id', 'status', 'attempts', 'last_error',
            'enqueued_at', 'delivered_at', 'last_attempt_at',
        ],
        'idempotency' => ['instance_id', 'instance_version', 'idempotency_key', 'transition_result', 'recorded_at'],
        'rejection' => [
            'rejection_id', 'instance_id', 'instance_version', 'machine_key', 'machine_version', 'status',
            'event_type', 'payload', 'actor', 'idempotency_key', 'event_origin', 'reason', 'occurred_at',
        ],
    ];

    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var array<string, string> Role => schema-qualified table name */
    private readonly array $tables;

    /** @var array<string, string> Canonical column => real column, for every canonical column */
    private readonly array $columns;

    private readonly ISqlDialect $dialect;

    /**
     * @param PDO $pdo Connection to the database holding the tables (its error mode is left untouched)
     * @param string $schema Optional schema (or database) qualifying every table; '' = unqualified
     * @param array<string, string> $tables Table names by role; missing roles keep the default
     * @param array<string, string> $columns Real column names by canonical column; missing columns keep
     *                                       the canonical name
     * @throws InvalidArgumentException When the schema, a role, a table, a canonical column or a real
     *                                  column name is not a plain SQL identifier or is unknown
     */
    public function __construct(
        private readonly PDO $pdo,
        string $schema = '',
        array $tables = [],
        array $columns = []
    ) {
        if ($schema !== '' && preg_match(self::IDENTIFIER, $schema) !== 1) {
            throw new InvalidArgumentException(sprintf('State machine schema "%s" is not a plain SQL identifier.', $schema));
        }

        $resolved = [];
        foreach ($tables as $role => $_) {
            if (!array_key_exists($role, self::DEFAULT_TABLES)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown state machine table role "%s". Expected: %s.',
                    (string) $role,
                    implode(', ', array_keys(self::DEFAULT_TABLES))
                ));
            }
        }
        foreach (self::DEFAULT_TABLES as $role => $default) {
            $name = $tables[$role] ?? $default;
            if (!is_string($name) || preg_match(self::IDENTIFIER, $name) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'State machine table "%s" (role %s) is not a plain SQL identifier.',
                    is_string($name) ? $name : get_debug_type($name),
                    $role
                ));
            }
            $resolved[$role] = $schema !== '' ? $schema . '.' . $name : $name;
        }
        $this->tables = $resolved;

        $known = self::canonicalColumns();
        $map = array_combine($known, $known);
        foreach ($columns as $canonical => $real) {
            if (!is_string($canonical) || !in_array($canonical, $known, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown state machine column "%s". Expected one of: %s.',
                    (string) $canonical,
                    implode(', ', $known)
                ));
            }
            if (!is_string($real) || preg_match(self::IDENTIFIER, $real) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'State machine column "%s" (canonical %s) is not a plain SQL identifier.',
                    is_string($real) ? $real : get_debug_type($real),
                    $canonical
                ));
            }
            $map[$canonical] = $real;
        }
        $this->columns = $map;

        $this->dialect = SqlDialectFactory::create(SqlDialect::fromDriver((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)));
    }

    /**
     * Every canonical column of the six tables, without duplicates.
     *
     * @return list<string>
     */
    public static function canonicalColumns(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::TABLE_COLUMNS))));
    }

    /** The connection. */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Schema-qualified table names in use, by role.
     *
     * @return array<string, string>
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    /**
     * Real column name of every canonical column.
     *
     * @return array<string, string>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /** SQL dialect of the connection's driver. */
    public function getDialect(): ISqlDialect
    {
        return $this->dialect;
    }

    /** Schema-qualified table name of a role. */
    public function table(string $role): string
    {
        return $this->tables[$role];
    }

    /** Real column name of a canonical column. */
    public function column(string $canonical): string
    {
        return $this->columns[$canonical];
    }

    /**
     * Select list reading canonical columns under their canonical alias.
     *
     * @param list<string> $canonical Canonical columns
     * @param string $alias Table alias ('' = none)
     * @return string
     */
    public function selectList(array $canonical, string $alias = ''): string
    {
        return implode(', ', array_map(function (string $c) use ($alias): string {
            $real = $this->column($c);
            $reference = $alias !== '' ? $alias . '.' . $real : $real;

            return ($alias === '' && $real === $c) ? $reference : $reference . ' AS ' . $c;
        }, $canonical));
    }

    /**
     * Adds a row limit to a SELECT in the dialect's syntax (`LIMIT n`, `FETCH FIRST n ROWS ONLY`...).
     */
    public function limit(string $sql, int $limit): string
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('The limit must be at least 1, %d given.', $limit));
        }

        return $this->dialect instanceof AbstractSqlDialect ? $this->dialect->paginate($sql, $limit) : $sql;
    }

    /**
     * Inserts a row given by canonical column.
     *
     * @param string $role Table role
     * @param array<string, mixed> $row Canonical column => value
     * @throws MachinePersistenceException
     */
    public function insert(string $role, array $row): void
    {
        $columns = array_keys($row);
        $params = [];
        foreach ($row as $column => $value) {
            $params[':' . $column] = $value;
        }
        $this->execute(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table($role),
            implode(', ', array_map(fn(string $c): string => $this->column($c), $columns)),
            implode(', ', array_keys($params))
        ), $params);
    }

    /**
     * Runs a parameterized statement.
     *
     * @param string $sql Statement
     * @param array<string, mixed> $params Named parameters (int, string, null, bool)
     * @return PDOStatement
     * @throws MachinePersistenceException When the driver reports an error
     */
    public function execute(string $sql, array $params = []): PDOStatement
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
                    is_bool($value) => PDO::PARAM_BOOL,
                    default => PDO::PARAM_STR,
                });
            }
            if (!$statement->execute()) {
                throw $this->driverError($statement->errorInfo());
            }
        } catch (PDOException $e) {
            $info = $e->errorInfo;
            throw new MachinePersistenceException(
                'State machine statement failed: ' . $e->getMessage(),
                0,
                $e,
                is_array($info) && isset($info[0]) ? (string) $info[0] : (is_string($e->getCode()) ? $e->getCode() : null)
            );
        }

        return $statement;
    }

    /**
     * Runs a parameterized query and returns its rows with lowercase column keys.
     *
     * @param string $sql Query
     * @param array<string, mixed> $params Named parameters
     * @return list<array<string, mixed>>
     * @throws MachinePersistenceException
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $rows = $this->execute($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $row): array => array_change_key_case($row, CASE_LOWER), $rows);
    }

    /**
     * @param array<int, mixed> $errorInfo PDO errorInfo() triple
     */
    private function driverError(array $errorInfo): MachinePersistenceException
    {
        return new MachinePersistenceException(
            sprintf(
                'State machine statement failed: [%s] %s',
                (string) ($errorInfo[0] ?? ''),
                (string) ($errorInfo[2] ?? 'unknown driver error')
            ),
            0,
            null,
            isset($errorInfo[0]) ? (string) $errorInfo[0] : null
        );
    }
}
