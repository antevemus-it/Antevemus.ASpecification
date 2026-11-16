<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\Support;

use Antevemus\ASpecification\Contracts\Sql\ISqlDialect;
use Antevemus\ASpecification\Contracts\Sql\ISqlPagination;
use Antevemus\ASpecification\Messaging\Exceptions\MessagingPersistenceException;
use Antevemus\ASpecification\Sql\Dialects\SqlDialectFactory;
use Antevemus\ASpecification\Sql\SqlDialect;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * PdoMessagingTable - Table, Column Map and Statement Helper of the PDO Stores of the Messaging Hook
 *
 * Shared by PdoMessageOutbox and PdoProcessedMessageStore (forward 063, D4, D8), in the conventions
 * of PdoMachineSchema (1.8.0) and PdoDomainEventOutbox (1.11.0):
 * - Portable, parameterized SQL; the table, the optional schema and every real column validated as
 *   plain identifiers (`[A-Za-z_][A-Za-z0-9_]*`); named placeholders used once each (safe under
 *   native prepares)
 * - Column map: `$columns` maps a canonical column (the name of the reference DDL) to the real
 *   column of the consumer table; each real column is selected under its canonical alias
 * - Row limits in the dialect of the driver (it must paginate)
 * - The error mode of the connection is left untouched: failed statements are detected both ways
 *   and reported as MessagingPersistenceException with the operation, the table, the SQLSTATE and
 *   the driver code, WITHOUT the driver message, which may quote a stored row and so the payload
 *   (RN-05)
 *
 * @internal
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\Support
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoMessagingTable
{
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private readonly string $table;

    /** @var array<string, string> Canonical column => real column */
    private readonly array $columns;

    private readonly ISqlDialect&ISqlPagination $dialect;

    /**
     * @param PDO $pdo Connection (its error mode is left untouched)
     * @param string $table Table name
     * @param string $schema Schema (or database) qualifying the table; '' = unqualified
     * @param array<string, string> $columns Real column by canonical column; missing ones keep the canonical name
     * @param list<string> $canonical Canonical columns of the table, in DDL order
     * @param string $what Human name of the store, for the messages ("Message outbox")
     * @param class-string $owner Store that uses the helper (named when the dialect cannot paginate)
     * @throws InvalidArgumentException When a name is not a plain SQL identifier or a column is unknown
     */
    public function __construct(
        private readonly PDO $pdo,
        string $table,
        string $schema,
        array $columns,
        private readonly array $canonical,
        private readonly string $what,
        string $owner
    ) {
        foreach (['table' => $table, 'schema' => $schema] as $kind => $name) {
            if (($kind === 'table' || $name !== '') && preg_match(self::IDENTIFIER, $name) !== 1) {
                throw new InvalidArgumentException(sprintf('%s %s "%s" is not a plain SQL identifier.', $what, $kind, $name));
            }
        }
        $this->table = $schema !== '' ? $schema . '.' . $table : $table;
        $map = array_combine($canonical, $canonical);
        foreach ($columns as $column => $real) {
            if (!is_string($column) || !isset($map[$column])) {
                throw new InvalidArgumentException(sprintf('Unknown %s column "%s". Expected one of: %s.', strtolower($what), (string) $column, implode(', ', $canonical)));
            }
            if (!is_string($real) || preg_match(self::IDENTIFIER, $real) !== 1) {
                throw new InvalidArgumentException(sprintf('%s column "%s" (canonical %s) is not a plain SQL identifier.', $what, is_string($real) ? $real : get_debug_type($real), $column));
            }
            $map[$column] = $real;
        }
        $this->columns = $map;
        $this->dialect = SqlDialectFactory::createPaginating(
            SqlDialect::fromDriver((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)),
            $owner
        );
    }

    /** The connection. */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /** Schema-qualified table name. */
    public function table(): string
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

    /** Real column name of a canonical column (an alias prefix when given). */
    public function column(string $canonical, string $alias = ''): string
    {
        return ($alias === '' ? '' : $alias . '.') . $this->columns[$canonical];
    }

    /** Select list of every canonical column, read under its canonical alias. */
    public function selectList(string $alias = ''): string
    {
        return implode(', ', array_map(function (string $canonical) use ($alias): string {
            $real = $this->column($canonical, $alias);

            return $real === $canonical ? $real : $real . ' AS ' . $canonical;
        }, $this->canonical));
    }

    /** Adds a row limit to a SELECT in the dialect's syntax. */
    public function limit(string $sql, int $limit): string
    {
        return $this->dialect->paginate($sql, $limit);
    }

    /**
     * Inserts a row given by canonical column.
     *
     * @param array<string, string|int|null> $row
     * @throws MessagingPersistenceException
     */
    public function insert(array $row, string $operation, ?string $messageId): void
    {
        $params = [];
        foreach ($row as $column => $value) {
            $params[':' . $column] = $value;
        }
        $this->execute(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', array_map(fn(string $c): string => $this->column($c), array_keys($row))),
            implode(', ', array_keys($params))
        ), $params, $operation, $messageId);
    }

    /**
     * Runs a query and returns its rows with lowercase keys.
     *
     * @param array<string, string|int|null> $params
     * @return list<array<string, mixed>>
     * @throws MessagingPersistenceException
     */
    public function fetchAll(string $sql, array $params, string $operation, ?string $messageId = null): array
    {
        $rows = $this->execute($sql, $params, $operation, $messageId)->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $row): array => array_change_key_case($row, CASE_LOWER), $rows);
    }

    /**
     * Runs a parameterized statement.
     *
     * @param array<string, string|int|null> $params Named parameters
     * @param string $operation What the statement does ("enqueue", "mark delivered"...), for the message
     * @param string|null $messageId Message concerned, for the message
     * @throws MessagingPersistenceException When the driver reports an error (without its message)
     */
    public function execute(string $sql, array $params, string $operation, ?string $messageId = null): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
            if (!$statement instanceof PDOStatement) {
                throw $this->failure($this->pdo->errorInfo(), $operation, $messageId);
            }
            foreach ($params as $name => $value) {
                $statement->bindValue($name, $value, match (true) {
                    $value === null => PDO::PARAM_NULL,
                    is_int($value) => PDO::PARAM_INT,
                    default => PDO::PARAM_STR,
                });
            }
            if (!$statement->execute()) {
                throw $this->failure($statement->errorInfo(), $operation, $messageId);
            }
        } catch (PDOException $e) {
            $info = is_array($e->errorInfo) ? $e->errorInfo : [is_string($e->getCode()) ? $e->getCode() : null];
            throw $this->failure($info, $operation, $messageId);
        }

        return $statement;
    }

    /**
     * @param array<int, mixed> $errorInfo PDO errorInfo() triple
     */
    private function failure(array $errorInfo, string $operation, ?string $messageId): MessagingPersistenceException
    {
        $state = isset($errorInfo[0]) && $errorInfo[0] !== '' ? (string) $errorInfo[0] : null;
        $code = isset($errorInfo[1]) ? (string) $errorInfo[1] : null;

        return new MessagingPersistenceException(sprintf(
            '%s statement failed: %s on %s%s (SQLSTATE %s, driver code %s). The driver message is not repeated: it may quote a stored row.',
            $this->what,
            $operation,
            $this->table,
            $messageId === null ? '' : ' for message id ' . $messageId,
            $state ?? 'unknown',
            $code ?? 'unknown'
        ), $state, $code);
    }
}
