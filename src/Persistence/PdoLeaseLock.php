<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Persistence;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * PdoLeaseLock - Lease Locks over PDO
 *
 * Table `lease_lock` of `resources/sql/lease_lock.ansi.sql` (forward 065, D9), one row per lock name
 * (the primary key), in the conventions of the PDO stores of the library: portable parameterized SQL
 * (named placeholders used once each), the table, schema and columns validated as plain identifiers, a
 * column map, the error mode of the connection left untouched, the driver message never repeated. One
 * table holds the locks of every dispatcher, each under its own name.
 *
 * Taking a lock is portable, without `SELECT ... FOR UPDATE SKIP LOCKED` (SQLite and older MySQL do not
 * have it):
 * - a conditional UPDATE of the row of the name, `WHERE lock_name = :name AND (locked_until < :now OR
 *   owner = :owner)`: the database serializes two of them on the row, and the second one sees the lease
 *   the first one wrote;
 * - when it changed no row, the row is read: there is none (the first acquisition, or after a release),
 *   so it is INSERTed, and a violation of the primary key means another worker inserted it first (the
 *   lock is not taken); or the row is the caller's own lease unchanged (MySQL and MariaDB count changed
 *   rows, not matched ones, and a renewal at the same instant changes nothing), so the lock is held; or
 *   another owner holds it;
 * - the INSERT runs inside a SAVEPOINT when the connection is in a transaction, rolled back on the
 *   violation, so that the transaction of the caller goes on (a failed statement aborts a PostgreSQL
 *   transaction); each savepoint has a name of its own (MySQL and MariaDB replace a savepoint of the
 *   same name, so a nested one would release the outer one). The connection may be the one of the
 *   outbox.
 *
 * A release deletes the row of the name, only when the caller owns it. Take a lock outside a long
 * transaction of the caller (the dispatchers run outside the write's transaction, as they always did):
 * the other workers see the lock only once it is committed, and on MySQL and MariaDB the UPDATE of a
 * transaction holds a gap lock that makes another connection's INSERT wait for its commit.
 *
 * @version    2.16.0
 * @package    Antevemus\ASpecification
 * @subpackage Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoLeaseLock implements ILeaseLock
{
    /** Default table name, mirroring the reference DDL. */
    public const DEFAULT_TABLE = 'lease_lock';

    /** Canonical columns of the table, in DDL order. */
    public const COLUMNS = ['lock_name', 'owner', 'locked_until', 'acquired_at'];

    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** Prefix of the savepoint of an INSERT inside the caller's transaction (a number makes each one unique). */
    private const SAVEPOINT = 'aspec_lease_lock_';

    /** Savepoints taken by this process: MySQL replaces a savepoint of the same name, so each one gets its own. */
    private static int $savepoints = 0;

    private readonly string $table;

    /** @var array<string, string> Canonical column => real column */
    private readonly array $columns;

    /**
     * @param PDO $pdo Connection to the database holding the table (its error mode is left untouched)
     * @param string $table Table name
     * @param string $schema Optional schema (or database) qualifying the table; '' = unqualified
     * @param array<string, string> $columns Real column by canonical column (COLUMNS); missing ones keep their name
     * @throws InvalidArgumentException When a name is not a plain SQL identifier or a column is unknown
     */
    public function __construct(private readonly PDO $pdo, string $table = self::DEFAULT_TABLE, string $schema = '', array $columns = [])
    {
        foreach (['table' => $table, 'schema' => $schema] as $kind => $name) {
            if (($kind === 'table' || $name !== '') && preg_match(self::IDENTIFIER, $name) !== 1) {
                throw new InvalidArgumentException(sprintf('Lease lock %s "%s" is not a plain SQL identifier.', $kind, $name));
            }
        }
        $this->table = $schema !== '' ? $schema . '.' . $table : $table;
        $map = array_combine(self::COLUMNS, self::COLUMNS);
        foreach ($columns as $column => $real) {
            if (!is_string($column) || !isset($map[$column])) {
                throw new InvalidArgumentException(sprintf('Unknown lease lock column "%s". Expected one of: %s.', (string) $column, implode(', ', self::COLUMNS)));
            }
            if (!is_string($real) || preg_match(self::IDENTIFIER, $real) !== 1) {
                throw new InvalidArgumentException(sprintf('Lease lock column "%s" (canonical %s) is not a plain SQL identifier.', is_string($real) ? $real : get_debug_type($real), $column));
            }
            $map[$column] = $real;
        }
        $this->columns = $map;
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
     * Real column name of every canonical column.
     *
     * @return array<string, string>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /** {@inheritdoc} */
    public function tryAcquire(string $name, string $owner, DateTimeImmutable $now, int $leaseSeconds): bool
    {
        LeaseLockSupport::checkAcquire($name, $owner, $leaseSeconds);
        $c = $this->columns;
        $at = LeaseLockSupport::toText($now);
        $until = LeaseLockSupport::toText(LeaseLockSupport::leaseEnd($now, $leaseSeconds));
        // acquired_at first: MySQL and MariaDB evaluate the assignments of an UPDATE from left to right, so
        // the CASE must read the owner before it is replaced.
        $updated = $this->execute(sprintf(
            'UPDATE %1$s SET %2$s = CASE WHEN %3$s = :same_owner THEN %2$s ELSE :acquired_at END, %3$s = :owner, %4$s = :locked_until WHERE %5$s = :lock_name AND (%4$s < :now OR %3$s = :current_owner)',
            $this->table,
            $c['acquired_at'],
            $c['owner'],
            $c['locked_until'],
            $c['lock_name']
        ), [
            ':same_owner' => $owner,
            ':acquired_at' => $at,
            ':owner' => $owner,
            ':locked_until' => $until,
            ':lock_name' => $name,
            ':now' => $at,
            ':current_owner' => $owner,
        ], 'acquire', $name)->rowCount();
        if ($updated > 0) {
            return true;
        }
        $row = $this->lease($name);
        if ($row === null) {
            return $this->insert($name, $owner, $at, $until);
        }

        return $row['owner'] === $owner && $row['locked_until'] === $until;
    }

    /** {@inheritdoc} */
    public function release(string $name, string $owner): void
    {
        $this->execute(
            sprintf('DELETE FROM %s WHERE %s = :lock_name AND %s = :owner', $this->table, $this->columns['lock_name'], $this->columns['owner']),
            [':lock_name' => $name, ':owner' => $owner],
            'release',
            $name
        );
    }

    /**
     * Who holds the lock $name at $now (inspection and tests): null when nobody does or the lease ran out.
     *
     * @throws LeaseLockException When the store fails
     */
    public function holder(string $name, DateTimeImmutable $now): ?string
    {
        $lease = $this->lease($name);

        return $lease !== null && $lease['locked_until'] >= LeaseLockSupport::toText($now) ? $lease['owner'] : null;
    }

    /**
     * The row of the lock $name (inspection and tests), also when its lease ran out; null when released.
     *
     * @return array{owner: string, locked_until: string, acquired_at: string}|null Instants as stored (UTC text)
     * @throws LeaseLockException When the store fails
     */
    public function lease(string $name): ?array
    {
        $c = $this->columns;
        $rows = $this->execute(
            sprintf('SELECT %s AS owner, %s AS locked_until, %s AS acquired_at FROM %s WHERE %s = :lock_name', $c['owner'], $c['locked_until'], $c['acquired_at'], $this->table, $c['lock_name']),
            [':lock_name' => $name],
            'read',
            $name
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }
        $row = array_change_key_case($rows[0], CASE_LOWER);

        return ['owner' => (string) $row['owner'], 'locked_until' => (string) $row['locked_until'], 'acquired_at' => (string) $row['acquired_at']];
    }

    /**
     * Inserts the row of the lock; false when another worker inserted it first.
     *
     * @throws LeaseLockException When the INSERT fails for another reason
     */
    private function insert(string $name, string $owner, string $at, string $until): bool
    {
        $c = $this->columns;
        $savepoint = $this->pdo->inTransaction() ? self::SAVEPOINT . ++self::$savepoints : null;
        if ($savepoint !== null) {
            $this->exec('SAVEPOINT ' . $savepoint, $name);
        }
        try {
            $this->execute(
                sprintf('INSERT INTO %s (%s, %s, %s, %s) VALUES (:lock_name, :owner, :locked_until, :acquired_at)', $this->table, $c['lock_name'], $c['owner'], $c['locked_until'], $c['acquired_at']),
                [':lock_name' => $name, ':owner' => $owner, ':locked_until' => $until, ':acquired_at' => $at],
                'acquire',
                $name
            );
        } catch (LeaseLockException $e) {
            if ($savepoint !== null) {
                $this->exec('ROLLBACK TO SAVEPOINT ' . $savepoint, $name);
            }
            if ($e->isIntegrityViolation()) {
                return false;
            }
            throw $e;
        }
        if ($savepoint !== null) {
            $this->exec('RELEASE SAVEPOINT ' . $savepoint, $name);
        }

        return true;
    }

    /**
     * Runs a parameterized statement.
     *
     * @param array<string, string> $params Named parameters
     * @throws LeaseLockException When the driver reports an error (without its message)
     */
    private function execute(string $sql, array $params, string $operation, string $name): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
            if (!$statement instanceof PDOStatement) {
                throw $this->failure($this->pdo->errorInfo(), $operation, $name);
            }
            foreach ($params as $placeholder => $value) {
                $statement->bindValue($placeholder, $value, PDO::PARAM_STR);
            }
            if (!$statement->execute()) {
                throw $this->failure($statement->errorInfo(), $operation, $name);
            }
        } catch (PDOException $e) {
            throw $this->failure(self::infoOf($e), $operation, $name);
        }

        return $statement;
    }

    /**
     * Runs a statement without parameters through PDO::exec() (the savepoints, which the prepared statement
     * protocol of MySQL may refuse).
     *
     * @throws LeaseLockException
     */
    private function exec(string $sql, string $name): void
    {
        try {
            if ($this->pdo->exec($sql) === false) {
                throw $this->failure($this->pdo->errorInfo(), 'acquire', $name);
            }
        } catch (PDOException $e) {
            throw $this->failure(self::infoOf($e), 'acquire', $name);
        }
    }

    /** @return array<int, mixed> */
    private static function infoOf(PDOException $e): array
    {
        return is_array($e->errorInfo) ? $e->errorInfo : [is_string($e->getCode()) ? $e->getCode() : null];
    }

    /**
     * @param array<int, mixed> $errorInfo PDO errorInfo() triple
     */
    private function failure(array $errorInfo, string $operation, string $name): LeaseLockException
    {
        $state = isset($errorInfo[0]) && $errorInfo[0] !== '' ? (string) $errorInfo[0] : null;
        $code = isset($errorInfo[1]) ? (string) $errorInfo[1] : null;

        return new LeaseLockException(sprintf(
            'Lease lock statement failed: %s of lock "%s" on %s (SQLSTATE %s, driver code %s). The driver message is not repeated.',
            $operation,
            $name,
            $this->table,
            $state ?? 'unknown',
            $code ?? 'unknown'
        ), $state, $code);
    }
}
