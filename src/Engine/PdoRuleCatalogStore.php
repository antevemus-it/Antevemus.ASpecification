<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IRuleCatalogStore;
use Antevemus\ASpecification\Contracts\Sql\ISqlPagination;
use Antevemus\ASpecification\Engine\Exceptions\RuleCatalogStoreException;
use Antevemus\ASpecification\Persistence\ITransactionManager;
use Antevemus\ASpecification\Persistence\PdoTransactionManager;
use Antevemus\ASpecification\Sql\Dialects\SqlDialectFactory;
use Antevemus\ASpecification\Sql\SqlDialect;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * PdoRuleCatalogStore - The Rule Catalog and Its History in Relational Tables over PDO
 *
 * The relational IRuleCatalogStore (2.13.0, forward 060, D1): writes the `business_rule` table that PdoRuleCatalog
 * reads, and its history `business_rule_change`, both of the reference DDL `resources/sql/rule_catalog.ansi.sql`
 * once the additive migration 2.13.0 of its header has run (D13: the column `business_rule.version` and the table
 * `business_rule_change`). Same contract as InMemoryRuleCatalogStore.
 *
 * Existing tables are read where they are, in the conventions of PdoRuleCatalog:
 * - `$schema` and `$tables` (roles `rule` and `change`): plain SQL identifiers
 * - `$columns`: canonical column => real column of the rule table, for the columns of PdoRuleCatalog::RULE_COLUMNS
 *   (every one is required: the photo of a rule has all of them), `version` (required, `version` by default) and
 *   `spec_json` (absent by default, as in PdoRuleCatalog; mapped, e.g. with PdoRuleCatalog::COLUMNS_SINCE_2_4).
 *   forCatalog() takes the table and the column map of a PdoRuleCatalog, so that the editor writes what the
 *   catalog reads. The history table has the columns of the reference DDL (CHANGE_COLUMNS)
 * - every row of the rule table is read, active or not; every real column is selected under its canonical alias
 *
 * SQL: portable and parameterized; every identifier validated against `[A-Za-z_][A-Za-z0-9_]*`, every value bound,
 * named placeholders used once each. A change is a compare-and-set of the version (`UPDATE ... SET ..., version =
 * version + 1 WHERE code = :code AND version = :expected_version`, one row or nothing, RN-04); a row or a history
 * row is created by INSERT, a key violation answered as "already there" when the row is there now; on PostgreSQL
 * inside a transaction such an INSERT, and the probes of assertReady(), run under a savepoint, so that a rejected
 * statement does not abort the caller's transaction (the approach of PdoFeatureFlagStore). On SQLite, a write of the
 * row that meets another connection's write (SQLITE_BUSY, "database is locked", also the stale snapshot of a WAL
 * reader) is the lost race of RN-04: the compare-and-set and the insert of the row answer false. Instants are written in UTC as `Y-m-d H:i:s.u` (the fixed-width text of the
 * package DDLs). The error mode of the connection is left untouched.
 *
 * Failures are RuleCatalogStoreException with the operation and the SQLSTATE; the driver message, which may quote
 * the values of the row, stays in the previous exception (RN-05).
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoRuleCatalogStore implements IRuleCatalogStore
{
    /** Default table names by role, mirroring the reference DDL. */
    public const DEFAULT_TABLES = ['rule' => 'business_rule', 'change' => 'business_rule_change'];

    /** Columns of the history table (reference DDL, 2.13.0). */
    public const CHANGE_COLUMNS = [
        'change_id', 'rule_code', 'version', 'change_kind', 'snapshot', 'snapshot_hash', 'changed_fields', 'reason',
        'origin', 'changed_at', 'changed_by',
    ];

    /** Format of the instants written (fixed-width text, UTC). */
    public const TIMESTAMP_FORMAT = 'Y-m-d H:i:s.u';

    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private const SAVEPOINT = 'aspec_rule_catalog';

    /** SQLite result code of a busy (locked) database, as PDO reports it (errorInfo[1]). */
    private const SQLITE_BUSY = 5;

    /** @var array{rule: string, change: string} Schema-qualified table names */
    private readonly array $tables;

    /** @var array<string, string> Canonical column => real column of the rule table (spec_json only when mapped) */
    private readonly array $columns;

    private readonly ITransactionManager $transactions;

    private readonly ?ISqlPagination $pagination;

    private readonly string $driver;

    private bool $ready = false;

    /**
     * @param PDO $pdo Connection to the database holding the catalog (its error mode is left untouched)
     * @param string $schema Optional schema (or database) qualifying both tables; '' = unqualified
     * @param array<string, string> $tables Table names by role (`rule`, `change`); missing roles keep the default
     * @param array<string, string|null> $columns Real column of the rule table by canonical column; missing ones keep
     *                                            the canonical name; `spec_json` is absent unless mapped (null = absent)
     * @param ITransactionManager|null $transactions Unit of work (null = a PdoTransactionManager on $pdo)
     * @throws InvalidArgumentException When a name is not a plain SQL identifier, or a role or a column is unknown or required
     */
    public function __construct(
        private readonly PDO $pdo,
        string $schema = '',
        array $tables = self::DEFAULT_TABLES,
        array $columns = [],
        ?ITransactionManager $transactions = null
    ) {
        if ($schema !== '' && preg_match(self::IDENTIFIER, $schema) !== 1) {
            throw new InvalidArgumentException(sprintf('Rule catalog schema "%s" is not a plain SQL identifier.', $schema));
        }
        foreach (array_keys($tables) as $role) {
            if (!array_key_exists($role, self::DEFAULT_TABLES)) {
                throw new InvalidArgumentException(sprintf('Unknown rule catalog store table role "%s". Expected: rule, change.', (string) $role));
            }
        }
        $resolved = [];
        foreach (self::DEFAULT_TABLES as $role => $default) {
            $name = $tables[$role] ?? $default;
            if (!is_string($name) || preg_match(self::IDENTIFIER, $name) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Rule catalog table "%s" (role %s) is not a plain SQL identifier.',
                    is_string($name) ? $name : get_debug_type($name),
                    $role
                ));
            }
            $resolved[$role] = $schema !== '' ? $schema . '.' . $name : $name;
        }
        /** @var array{rule: string, change: string} $resolved */
        $this->tables = $resolved;
        $this->columns = self::resolveColumns($columns);

        $this->transactions = $transactions ?? new PdoTransactionManager($pdo);
        $this->driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $dialect = SqlDialectFactory::create(SqlDialect::fromDriver($this->driver));
        $this->pagination = $dialect instanceof ISqlPagination ? $dialect : null;
    }

    /**
     * The store over the rule table and the column map of a catalog, with the history table beside it (in the same
     * schema): the editor writes exactly what the catalog reads. A catalog that does not map `version` (built
     * without COLUMNS_SINCE_2_13) gives the default column name.
     *
     * @param PDO $pdo The connection of the catalog
     * @param PdoRuleCatalog $catalog The catalog whose table the editor writes
     * @param string $changeTable Name of the history table (unqualified)
     * @param ITransactionManager|null $transactions Unit of work (null = a PdoTransactionManager on $pdo)
     * @throws InvalidArgumentException When a name is not a plain SQL identifier
     */
    public static function forCatalog(PDO $pdo, PdoRuleCatalog $catalog, string $changeTable = 'business_rule_change', ?ITransactionManager $transactions = null): self
    {
        $parts = explode('.', $catalog->getTables()['rule']);
        $table = array_pop($parts);
        $known = [...PdoRuleCatalog::RULE_COLUMNS, 'spec_json', 'version'];

        return new self(
            $pdo,
            implode('.', $parts),
            ['rule' => $table, 'change' => $changeTable],
            array_intersect_key($catalog->getColumns(), array_flip($known)),
            $transactions
        );
    }

    /**
     * Schema-qualified table names in use, by role.
     *
     * @return array{rule: string, change: string}
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    /**
     * Real column of every canonical column of the rule table (`spec_json` only when mapped).
     *
     * @return array<string, string>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /** The connection. */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /** An instant as written in `changed_at`: UTC, `Y-m-d H:i:s.u`. */
    public static function instantText(DateTimeInterface $at): string
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP_FORMAT);
    }

    /**
     * {@inheritdoc}
     *
     * Two probes that read nothing (`WHERE 1 = 0`): the version column of the rule table and every column of the
     * history table. Once both answer, the store does not probe again.
     */
    public function assertReady(): void
    {
        if ($this->ready) {
            return;
        }
        $migration = 'run the additive migration 2.13.0 of the header of resources/sql/rule_catalog.ansi.sql';
        if (!$this->probe(sprintf('SELECT %s FROM %s WHERE 1 = 0', $this->columns['version'], $this->tables['rule']))) {
            throw new RuleCatalogStoreException(sprintf(
                'The rule table %s has no column %s (the version of a rule), which the audited path needs: %s.',
                $this->tables['rule'],
                $this->columns['version'],
                $migration
            ));
        }
        if (!$this->probe(sprintf('SELECT %s FROM %s WHERE 1 = 0', implode(', ', self::CHANGE_COLUMNS), $this->tables['change']))) {
            throw new RuleCatalogStoreException(sprintf(
                'The history table %s of the rule catalog is missing or lacks one of its columns (%s): %s.',
                $this->tables['change'],
                implode(', ', self::CHANGE_COLUMNS),
                $migration
            ));
        }
        $this->ready = true;
    }

    /** {@inheritdoc} */
    public function supportsSpecJson(): bool
    {
        return isset($this->columns['spec_json']);
    }

    /** {@inheritdoc} */
    public function findRow(string $code): ?array
    {
        $rows = $this->fetchAll(
            sprintf('SELECT %s FROM %s WHERE %s = :code', $this->ruleSelectList(), $this->tables['rule'], $this->columns['code']),
            [':code' => $code],
            'read the rule'
        );

        return $rows === [] ? null : RuleSnapshot::fromStored($rows[0]);
    }

    /** {@inheritdoc} */
    public function findRows(): array
    {
        $rows = array_map(
            RuleSnapshot::fromStored(...),
            $this->fetchAll(sprintf('SELECT %s FROM %s', $this->ruleSelectList(), $this->tables['rule']), [], 'read the rules')
        );
        // In PHP, not in SQL: the order of strcmp, whatever the collation of the database
        usort($rows, static fn(array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $rows;
    }

    /** {@inheritdoc} */
    public function insertRow(array $row): bool
    {
        $values = $this->toDatabase(RuleSnapshot::fromStored($row));
        $columns = [];
        $params = [];
        foreach ($values as $canonical => $value) {
            $columns[] = $this->columns[$canonical];
            $params[':v_' . $canonical] = $value;
        }
        $code = $row['code'];

        try {
            return $this->insertUnlessRaced(
                sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->tables['rule'], implode(', ', $columns), implode(', ', array_keys($params))),
                $params,
                fn(): bool => $this->exists(sprintf('SELECT %1$s FROM %2$s WHERE %1$s = :code', $this->columns['code'], $this->tables['rule']), [':code' => $code]),
                'insert the rule',
                $code
            );
        } catch (RuleCatalogStoreException $e) {
            if ($e->getPrevious() instanceof PDOException && $this->isSqliteBusy($e->getPrevious())) {
                return false;
            }
            throw $e;
        }
    }

    /** {@inheritdoc} */
    public function compareAndSetRow(string $code, int $expectedVersion, array $changes): bool
    {
        unset($changes['version'], $changes['code']);
        $set = [];
        $params = [];
        foreach ($this->toDatabase($changes) as $canonical => $value) {
            $set[] = $this->columns[$canonical] . ' = :v_' . $canonical;
            $params[':v_' . $canonical] = $value;
        }
        $set[] = sprintf('%1$s = %1$s + 1', $this->columns['version']);
        $params[':code'] = $code;
        $params[':expected_version'] = $expectedVersion;
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = :code AND %s = :expected_version',
            $this->tables['rule'],
            implode(', ', $set),
            $this->columns['code'],
            $this->columns['version']
        );
        try {
            $statement = $this->prepareAndExecute($sql, $params);
        } catch (PDOException $e) {
            if ($this->isSqliteBusy($e)) {
                return false;
            }
            throw $this->wrap($e, 'change the rule', $code);
        }

        return $statement->rowCount() === 1;
    }

    /** {@inheritdoc} */
    public function appendRevision(RuleRevision $revision): bool
    {
        $values = [
            'change_id' => $revision->changeId,
            'rule_code' => $revision->code,
            'version' => $revision->version,
            'change_kind' => $revision->kind->value,
            'snapshot' => $revision->snapshot,
            'snapshot_hash' => $revision->snapshotHash,
            'changed_fields' => $revision->changedFields === [] ? null : implode(',', $revision->changedFields),
            'reason' => $revision->reason,
            'origin' => $revision->origin,
            'changed_at' => self::instantText($revision->changedAt),
            'changed_by' => $revision->changedBy,
        ];
        $params = [];
        foreach ($values as $column => $value) {
            $params[':v_' . $column] = $value;
        }

        return $this->insertUnlessRaced(
            sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->tables['change'], implode(', ', array_keys($values)), implode(', ', array_keys($params))),
            $params,
            fn(): bool => $this->exists(
                sprintf('SELECT rule_code FROM %s WHERE rule_code = :code AND version = :version', $this->tables['change']),
                [':code' => $revision->code, ':version' => $revision->version]
            ),
            'append to the history of the rule',
            $revision->code
        );
    }

    /** {@inheritdoc} */
    public function findRevision(string $code, int $version): ?RuleRevision
    {
        $rows = $this->fetchAll(
            sprintf('SELECT %s FROM %s WHERE rule_code = :code AND version = :version', implode(', ', self::CHANGE_COLUMNS), $this->tables['change']),
            [':code' => $code, ':version' => $version],
            'read the history of the rule'
        );

        return $rows === [] ? null : $this->hydrateRevision($rows[0]);
    }

    /**
     * {@inheritdoc}
     *
     * One query: the rows whose version is the largest of their rule (a correlated subquery, portable).
     */
    public function lastRevisions(?string $code = null): array
    {
        $params = [];
        $sql = sprintf(
            'SELECT %s FROM %s h WHERE h.version = (SELECT MAX(m.version) FROM %s m WHERE m.rule_code = h.rule_code)',
            implode(', ', self::CHANGE_COLUMNS),
            $this->tables['change'],
            $this->tables['change']
        );
        if ($code !== null) {
            $sql .= ' AND h.rule_code = :code';
            $params[':code'] = $code;
        }
        $last = [];
        foreach ($this->fetchAll($sql, $params, 'read the history of the rules') as $row) {
            $revision = $this->hydrateRevision($row);
            $last[$revision->code] = $revision;
        }
        ksort($last, SORT_STRING);

        return $last;
    }

    /** {@inheritdoc} */
    public function history(?string $code = null, int $limit = 50): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('The history limit must be at least 1, %d given.', $limit));
        }
        $params = [];
        $where = '';
        if ($code !== null) {
            $where = ' WHERE rule_code = :code';
            $params[':code'] = $code;
        }
        $sql = sprintf('SELECT %s FROM %s%s ORDER BY changed_at DESC, change_id DESC', implode(', ', self::CHANGE_COLUMNS), $this->tables['change'], $where);
        if ($this->pagination !== null) {
            $sql = $this->pagination->paginate($sql, $limit);
        }

        return array_map($this->hydrateRevision(...), array_slice($this->fetchAll($sql, $params, 'read the history of the rules'), 0, $limit));
    }

    /** {@inheritdoc} */
    public function getTransactionManager(): ITransactionManager
    {
        return $this->transactions;
    }

    /**
     * @param array<string, mixed> $columns
     * @return array<string, string>
     */
    private static function resolveColumns(array $columns): array
    {
        $known = [...PdoRuleCatalog::RULE_COLUMNS, 'version'];
        $map = array_combine($known, $known);
        foreach ($columns as $canonical => $real) {
            if (!is_string($canonical) || ($canonical !== 'spec_json' && !in_array($canonical, $known, true))) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown rule catalog store column "%s". Expected one of: %s.',
                    (string) $canonical,
                    implode(', ', [...$known, 'spec_json'])
                ));
            }
            if ($real === null && $canonical === 'spec_json') {
                unset($map[$canonical]);
                continue;
            }
            if ($real === null) {
                throw new InvalidArgumentException(sprintf('Rule catalog store column "%s" is required and cannot be absent.', $canonical));
            }
            if (!is_string($real) || preg_match(self::IDENTIFIER, $real) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Rule catalog store column "%s" (canonical %s) is not a plain SQL identifier.',
                    is_string($real) ? $real : get_debug_type($real),
                    $canonical
                ));
            }
            $map[$canonical] = $real;
        }

        return $map;
    }

    /** Select list of the rule table: every mapped column under its canonical alias. */
    private function ruleSelectList(): string
    {
        $select = [];
        foreach ($this->columns as $canonical => $real) {
            $select[] = $real === $canonical ? $real : $real . ' AS ' . $canonical;
        }

        return implode(', ', $select);
    }

    /**
     * The values bound for canonical values: `active` as 'Y'/'N', `parameters` as JSON text.
     *
     * @param array<string, mixed> $values Canonical values
     * @return array<string, mixed>
     * @throws RuleCatalogStoreException When `spec_json` is given and the store does not map it
     */
    private function toDatabase(array $values): array
    {
        $bound = [];
        foreach ($values as $canonical => $value) {
            if (!isset($this->columns[$canonical])) {
                if ($value === null) {
                    continue;
                }
                throw new RuleCatalogStoreException(sprintf('This rule catalog store does not map the column %s.', $canonical));
            }
            $bound[$canonical] = match ($canonical) {
                'active' => $value ? 'Y' : 'N',
                'parameters' => $value === null ? null : json_encode($value, RuleSnapshot::JSON_FLAGS | JSON_THROW_ON_ERROR),
                default => $value,
            };
        }

        return $bound;
    }

    /**
     * @param array<string, mixed> $row
     * @throws RuleCatalogStoreException When a value cannot be read
     */
    private function hydrateRevision(array $row): RuleRevision
    {
        $code = $row['rule_code'];
        $kind = RuleChangeKind::tryFrom((string) $row['change_kind'])
            ?? throw new RuleCatalogStoreException(sprintf('Rule "%s": a history row has an unknown change_kind.', $code), $code);
        try {
            $at = new DateTimeImmutable($row['changed_at'], new DateTimeZone('UTC'));
        } catch (Exception $e) {
            throw new RuleCatalogStoreException(sprintf('Rule "%s": a history row has a changed_at that is not an instant.', $code), $code, $e);
        }
        $fields = $row['changed_fields'] ?? '';
        $snapshot = $row['snapshot'];

        return new RuleRevision(
            $code,
            (int) $row['version'],
            $kind,
            is_resource($snapshot) ? (string) stream_get_contents($snapshot) : $snapshot,
            (string) $row['snapshot_hash'],
            $fields === '' ? [] : explode(',', $fields),
            (string) $row['reason'],
            (string) $row['origin'],
            $at,
            $row['changed_by'],
            $row['change_id']
        );
    }

    /**
     * Runs an INSERT that another writer may have raced: when the key rejects it and $exists confirms the row is
     * there now, returns false; any other failure is thrown. On PostgreSQL inside a transaction it runs under a
     * savepoint, so that a rejected INSERT leaves the transaction usable.
     *
     * @param array<string, mixed> $params
     * @param \Closure(): bool $exists
     */
    private function insertUnlessRaced(string $sql, array $params, \Closure $exists, string $operation, string $code): bool
    {
        $savepoint = $this->driver === 'pgsql' && $this->pdo->inTransaction();
        if ($savepoint) {
            $this->execute('SAVEPOINT ' . self::SAVEPOINT, [], $operation, $code);
        }
        try {
            $this->execute($sql, $params, $operation, $code);
        } catch (RuleCatalogStoreException $e) {
            if ($savepoint) {
                $this->execute('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT, [], $operation, $code);
            }
            if ($e->isIntegrityViolation() && $exists()) {
                return false;
            }
            throw $e;
        }
        if ($savepoint) {
            $this->execute('RELEASE SAVEPOINT ' . self::SAVEPOINT, [], $operation, $code);
        }

        return true;
    }

    /**
     * True when the query finds a row; inside a transaction on MySQL/MariaDB the read locks (`FOR UPDATE`), so that
     * it sees a row another writer committed after the snapshot of the transaction (the check after a rejected
     * INSERT, as in PdoFeatureFlagStore).
     *
     * @param array<string, mixed> $params
     */
    private function exists(string $sql, array $params): bool
    {
        if ($this->driver === 'mysql' && $this->pdo->inTransaction()) {
            $sql .= ' FOR UPDATE';
        }

        return $this->fetchAll($sql, $params, 'check the row') !== [];
    }

    /** True when the statement runs; false when it fails (under a savepoint on PostgreSQL inside a transaction). */
    private function probe(string $sql): bool
    {
        $savepoint = $this->driver === 'pgsql' && $this->pdo->inTransaction();
        if ($savepoint) {
            $this->pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }
        try {
            $this->prepareAndExecute($sql, [])->closeCursor();
        } catch (PDOException) {
            if ($savepoint) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
            }

            return false;
        }
        if ($savepoint) {
            $this->pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, array $params, string $operation): array
    {
        $statement = $this->execute($sql, $params, $operation, null);
        try {
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw $this->wrap($e, $operation, null);
        }
        $statement->closeCursor();

        return array_map(static fn(array $row): array => array_change_key_case($row, CASE_LOWER), $rows);
    }

    /**
     * @param array<string, mixed> $params
     * @throws RuleCatalogStoreException
     */
    private function execute(string $sql, array $params, string $operation, ?string $code): PDOStatement
    {
        try {
            return $this->prepareAndExecute($sql, $params);
        } catch (PDOException $e) {
            throw $this->wrap($e, $operation, $code);
        }
    }

    /**
     * Prepares, binds and executes one statement; a failure reported without an exception (silent error mode)
     * becomes a PDOException with the errorInfo of the driver.
     *
     * @param array<string, mixed> $params
     * @throws PDOException
     */
    private function prepareAndExecute(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw self::driverError($this->pdo->errorInfo());
        }
        // Every value bound as text: a NULL stays NULL, and an integer compared with or written to an integer
        // column is converted by the database (measured on SQLite, PostgreSQL 17, MySQL 8.4 and MariaDB 11.4)
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value);
        }
        if (!$statement->execute()) {
            throw self::driverError($statement->errorInfo());
        }

        return $statement;
    }

    /**
     * @param array<int, mixed> $errorInfo PDO errorInfo() triple
     */
    private static function driverError(array $errorInfo): PDOException
    {
        $e = new PDOException(sprintf('[%s] %s', (string) ($errorInfo[0] ?? ''), (string) ($errorInfo[2] ?? 'unknown driver error')));
        $e->errorInfo = $errorInfo;

        return $e;
    }

    /** True when SQLite reported a busy (locked) database: another connection wrote. */
    private function isSqliteBusy(PDOException $e): bool
    {
        return $this->driver === 'sqlite' && ($e->errorInfo[1] ?? null) === self::SQLITE_BUSY;
    }

    private function wrap(PDOException $e, string $operation, ?string $code): RuleCatalogStoreException
    {
        $sqlState = $e->errorInfo[0] ?? null;

        return new RuleCatalogStoreException(
            sprintf(
                'The rule catalog store could not %s%s (SQLSTATE %s); the driver error is the previous exception.',
                $operation,
                $code === null ? '' : sprintf(' "%s"', $code),
                $sqlState ?? 'unknown'
            ),
            $code,
            $e,
            $sqlState
        );
    }
}
