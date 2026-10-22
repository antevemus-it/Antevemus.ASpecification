<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Traits;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\PdoRepositoryException;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnMapping;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Sql\SqlWhereClause;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * PdoStatementKernel - The Statements of PdoRepository
 *
 * The statement machinery every operation of PdoRepository shares (since 2.11.0, when the class was
 * split into traits; the methods are the ones of 2.10.0, unchanged): quote() quotes an identifier by
 * the dialect; run() prepares and executes a statement, reports it to the query logger and turns a
 * driver error into a PdoRepositoryException (driverError()); inList() and inClause() build the IN
 * lists of a batch; identifierOf() reads the identifier of an entity; now() is the instant of the
 * repository's clock and stamp() writes it in the zone of a column.
 *
 * Every value goes through a prepared parameter; table and column names come only from the mapping,
 * validated as identifiers and quoted by the dialect (RN-04). Driver errors surface as
 * PdoRepositoryException whatever the error mode of the connection, which is left untouched.
 *
 * Like every trait of this folder it holds no state and is used only by PdoRepository, whose private
 * properties it reads.
 *
 * @version    2.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Traits
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait PdoStatementKernel
{
    private function quote(string $identifier): string
    {
        return $this->dialect->escapeIdentifier($identifier);
    }

    /**
     * Prepares and runs a statement with bound parameters, and logs it.
     *
     * @param array<string|int, mixed> $params Named parameters (or a list for positional `?` ones),
     *                                       already converted for their columns
     * @throws PdoRepositoryException When the driver reports an error
     */
    private function run(string $sql, array $params = []): PDOStatement
    {
        $started = $this->logger !== null ? hrtime(true) : 0;
        $bound = [];
        try {
            $statement = $this->pdo->prepare($sql);
            if (!$statement instanceof PDOStatement) {
                throw $this->driverError($this->pdo->errorInfo(), $sql);
            }
            foreach ($params as $name => $value) {
                $value = $this->converter->scalarOrString($value);
                $bound[$name] = $value;
                // An integer key is a positional placeholder (1-based in PDO)
                $statement->bindValue(is_int($name) ? $name + 1 : $name, $value, $this->converter->bindType($value));
            }
            if (!$statement->execute()) {
                throw $this->driverError($statement->errorInfo(), $sql);
            }
        } catch (PDOException $e) {
            $info = $e->errorInfo;
            throw new PdoRepositoryException(
                sprintf('Statement on table "%s" failed: %s', (string) $this->mapping->getTable(), $e->getMessage()),
                is_array($info) && isset($info[0]) ? (string) $info[0] : (is_string($e->getCode()) ? $e->getCode() : null),
                $e
            );
        } finally {
            if ($this->logger !== null) {
                try {
                    $this->logger->log($sql, count($params), (hrtime(true) - $started) / 1e6, $this->logParameters ? $bound : null);
                } catch (Throwable) {
                    // A broken logger never changes the outcome of a statement.
                }
            }
        }

        return $statement;
    }

    /**
     * @param array<int, mixed> $errorInfo PDO errorInfo() triple
     */
    private function driverError(array $errorInfo, string $sql): PdoRepositoryException
    {
        return new PdoRepositoryException(
            sprintf(
                'Statement on table "%s" failed: [%s] %s',
                (string) $this->mapping->getTable(),
                (string) ($errorInfo[0] ?? ''),
                (string) ($errorInfo[2] ?? 'unknown driver error')
            ),
            isset($errorInfo[0]) ? (string) $errorInfo[0] : null
        );
    }

    /**
     * Placeholders and parameters of an IN list.
     *
     * @param list<mixed> $values
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function inList(array $values, string $prefix): array
    {
        $params = [];
        foreach (array_values($values) as $i => $value) {
            $params[':' . $prefix . $i] = $value;
        }

        return ['sql' => implode(', ', array_keys($params)), 'params' => $params];
    }

    /**
     * `column = ?` or `column IN (?, ...)`: positional placeholders for a read (see insertRows():
     * thousands of named ones are quadratic on SQLite), named ones (`:<prefix>N`) for a clause that a
     * write combines with named SET parameters (a statement cannot mix both: SQLite binds them wrong
     * without an error).
     *
     * @param list<mixed> $values Bindable values
     * @param string|null $prefix Prefix of named placeholders; null = positional
     */
    private function inClause(ColumnMapping $column, array $values, ?string $prefix = null): SqlWhereClause
    {
        $values = array_values($values);
        if ($prefix !== null) {
            $in = $this->inList($values, $prefix);

            return new SqlWhereClause(
                $this->quote($column->column) . (count($values) === 1 ? ' = ' . $in['sql'] : ' IN (' . $in['sql'] . ')'),
                $in['params']
            );
        }
        if (count($values) === 1) {
            return new SqlWhereClause($this->quote($column->column) . ' = ?', $values);
        }

        return new SqlWhereClause(
            $this->quote($column->column) . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')',
            $values
        );
    }

    /**
     * Bindable identifier of an entity of the mapped class, or null when it has none yet (or is of
     * another class).
     */
    private function identifierOf(IEntity $entity): mixed
    {
        $class = $this->mapping->getEntityClass();
        if (!$entity instanceof $class) {
            return null;
        }
        $value = $this->mapping->readValue($entity, $this->idColumn->property);

        return $value === null ? null : $this->converter->toDatabase($this->idColumn, $value);
    }

    /**
     * The instant of the lifecycle columns for one write call: the clock, truncated to the second.
     */
    private function now(): DateTimeImmutable
    {
        $now = $this->clock !== null ? ($this->clock)() : new DateTimeImmutable('now');
        if (!$now instanceof DateTimeInterface) {
            throw new RuntimeException(sprintf('The clock of the repository returned %s instead of a DateTimeInterface.', get_debug_type($now)));
        }
        $now = DateTimeImmutable::createFromInterface($now);

        return $now->setTime((int) $now->format('G'), (int) $now->format('i'), (int) $now->format('s'), 0);
    }

    /**
     * The value of a lifecycle property for an instant: in the column's zone, of the property's date class.
     */
    private function stamp(ColumnMapping $column, DateTimeImmutable $now): DateTimeInterface
    {
        $value = $now->setTimezone(new DateTimeZone($column->timezone));
        if ($column->type === ColumnType::Date) {
            $value = $value->setTime(0, 0);   // what a Date column stores and hydrates
        }

        return $column->dateClass === DateTime::class ? DateTime::createFromImmutable($value) : $value;
    }
}
