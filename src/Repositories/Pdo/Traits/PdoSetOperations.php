<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Traits;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\PdoRepositoryException;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnMapping;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Repositories\Pdo\PdoAggregateQuery;
use Antevemus\ASpecification\Repositories\Pdo\PdoSpecificationCompiler;
use Antevemus\ASpecification\Repositories\Pdo\PdoValueConverter;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;
use Antevemus\ASpecification\Sql\Exceptions\UnsupportedSqlOperationException;
use Antevemus\ASpecification\Sql\SqlWhereClause;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use PDO;
use PDOStatement;

/**
 * PdoSetOperations - Projections, Aggregates and Subqueries of PdoRepository (since 1.13.0, forward 031, D6)
 *
 * Since 2.11.0, when the class was split into traits (the methods are the ones of 2.10.0, unchanged):
 * select(), aggregate(), toSubquery() and updateWhere(), with their private helpers. The canonical text
 * of a decimal aggregate, a private static method of the class until 2.10.0, is
 * PdoValueConverter::canonicalDecimal() (same body).
 *
 * - set-based reads and writes (D6): select() projects properties into arrays without building
 *   entities; aggregate() returns a PdoAggregateQuery (groupBy, count, sum, avg, min, max) computed by
 *   the database; both apply the read scope. updateWhere() is one UPDATE over the matching live rows,
 *   stamping #[UpdatedAt] and incrementing #[Version] on every touched row, WITHOUT the per-entity
 *   hooks. Spec::inQuery('prop', $other, $where, 'otherProp') compiles to `col IN (SELECT ...)` over
 *   another repository on the same connection (its own read scope; toSubquery() shows the statement)
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
trait PdoSetOperations
{
    /**
     * Some properties of the entities satisfying the specification, as arrays, without building any
     * entity: `SELECT "a", "b" FROM ... WHERE ...` ordered by the identifier, streamed (one row at a
     * time; the query runs on the first pull, the specification is compiled at the call).
     *
     * Each row is keyed by the property names given, in that order, each value converted like a read
     * value of its column (a NULL column gives null). Only readable mapped properties (the rule of the
     * specifications) can be selected. The read scope applies (soft delete: live rows only, or what a
     * withTrashed()/onlyTrashed() view sees). With the in-memory fallback on, a specification that
     * cannot be translated reads the entities and projects them in PHP.
     *
     * @param ISpecification $specification Filter
     * @param list<string> $properties Properties to read (at least one, no repetition)
     * @return iterable<int, array<string, mixed>>
     * @throws InvalidArgumentException When a property is not readable or repeated, or none is given
     * @throws NonTranslatableSpecificationException When the specification cannot be translated (and no fallback)
     */
    public function select(ISpecification $specification, array $properties): iterable
    {
        $this->validateSpecification($specification);
        $columns = $this->readableColumns($properties, 'select');

        return $this->readConcurrently(function () use ($specification, $columns): Generator {
            $where = $this->compileOrFallback($specification);

            return $this->project($where, $where === null ? $specification : null, $columns);
        });
    }

    /**
     * Grouped aggregates of the entities satisfying the specification, computed by the database:
     * `aggregate($spec)->groupBy('region')->sum('amount')->count()->get()`. The specification is
     * compiled now (it must be translatable: the in-memory fallback does not apply); the read scope of
     * this repository (or view) applies. See PdoAggregateQuery for the result shape, the conversions
     * and the NULL semantics.
     *
     * @throws NonTranslatableSpecificationException When the specification cannot be translated
     * @throws UnsupportedSqlOperationException When the dialect lacks an operation the specification needs
     */
    public function aggregate(ISpecification $specification): PdoAggregateQuery
    {
        $this->validateSpecification($specification);
        $where = $this->scoped($this->compiler()->translate($specification));

        return new PdoAggregateQuery(
            $this->mapping,
            fn(array $groups, array $aggregates): array => $this->readConcurrently(
                fn(): array => $this->runAggregate($where, $groups, $aggregates)
            )
        );
    }

    /**
     * The subquery of Spec::inQuery() over this repository: `SELECT "column" FROM "table" WHERE <spec>
     * AND <read scope> AND "column" IS NOT NULL`, its parameters named with the prefix (diagnostics, and
     * the compiler of another repository on the same connection). NULL values are left out so that a
     * NOT over `col IN (subquery)` answers what memory answers (SQL makes `x NOT IN (..., NULL)` unknown).
     *
     * @param ISpecification $specification Filter of the subquery (must be translatable)
     * @param string $property Readable mapped property whose column is selected
     * @param string $parameterPrefix Prefix of the parameter names (letters, digits, underscore)
     * @throws InvalidArgumentException When the property is not readable or the prefix is invalid
     * @throws NonTranslatableSpecificationException When the specification cannot be translated
     */
    public function toSubquery(ISpecification $specification, string $property, string $parameterPrefix = 'q'): SqlWhereClause
    {
        $this->validateSpecification($specification);
        $column = $this->readableColumns([$property], 'select')[$property];
        $where = $this->compiler($parameterPrefix)->translate($specification);
        $where = $this->scoped($where)->and(new SqlWhereClause($this->quote($column->column) . ' IS NOT NULL'));

        return new SqlWhereClause(
            'SELECT ' . $this->quote($column->column) . ' FROM ' . $this->tableSql . $this->whereSql($where),
            $where->getParameters()
        );
    }

    /**
     * Mass UPDATE (since 1.13.0, forward 031, D6): sets the given properties on every row satisfying
     * the specification, in one statement and one transaction, without loading any entity.
     *
     * - values are converted by the type of their column, as a written value (an invalid one fails
     *   before any statement); the identifier, the version counter and the lifecycle columns
     *   (#[CreatedAt], #[UpdatedAt], #[SoftDelete]) cannot be set
     * - #[UpdatedAt] is stamped with the instant of the repository's clock; a #[Version] counter is
     *   incremented on every touched row (`COALESCE(version, 0) + 1`), so a copy loaded before is stale
     *   and its put() throws OptimisticLockException, as after any other write of the row
     * - with #[SoftDelete] only live rows are touched, whatever the view (onlyTrashed() touches none)
     * - NO hook runs (beforePut/afterPut are per entity, and no entity is loaded), and the decorators of
     *   the contract (TriggeringRepository) do not see it: a TriggerSweeper notices the changes; use
     *   putAll() when the hooks or the triggers must run
     * - with the in-memory fallback on, a specification that cannot be translated loads the matching
     *   live entities and updates their rows by identifier
     * - the returned count is the driver's: MySQL reports the rows whose values CHANGED (unless the
     *   connection sets PDO::MYSQL_ATTR_FOUND_ROWS), the others the rows matched; with #[UpdatedAt] or
     *   #[Version] every matched row changes. MySQL also refuses a subquery (Spec::inQuery()) on the
     *   table being updated
     *
     * @param ISpecification $specification Rows to update
     * @param array<string, mixed> $values Property => new value (at least one)
     * @return int Number of rows updated (see above for MySQL)
     * @throws InvalidArgumentException When a property is not mapped, cannot be set, or its value does not fit
     * @throws NonTranslatableSpecificationException When the specification cannot be translated (and no fallback)
     * @throws PdoRepositoryException When the driver refuses the statement
     */
    public function updateWhere(ISpecification $specification, array $values): int
    {
        $this->validateSpecification($specification);
        if ($values === []) {
            throw new InvalidArgumentException('updateWhere() needs at least one property to set.');
        }
        $sets = [];
        $params = [];
        $i = 0;
        foreach ($values as $property => $value) {
            $column = is_string($property) ? $this->mapping->getColumn($property) : null;
            if ($column === null) {
                throw new InvalidArgumentException(sprintf('Cannot update "%s": not a mapped property of %s.', (string) $property, $this->mapping->getEntityClass()));
            }
            $role = match (true) {
                $column->isId => 'the identifier',
                $column->isVersion => 'the version counter (incremented by every write)',
                $column->isCreatedAt => 'the creation time (#[CreatedAt])',
                $column->isUpdatedAt => 'the modification time (#[UpdatedAt], stamped by every write)',
                $column->isSoftDelete => 'the soft delete mark (use removeBy())',
                default => null,
            };
            if ($role !== null) {
                throw new InvalidArgumentException(sprintf('Cannot update "%s": it is %s.', $property, $role));
            }
            $name = ':set' . $i++;
            $sets[] = $this->quote($column->column) . ' = ' . $name;
            $params[$name] = $this->converter->toDatabase($column, $value);
        }

        return $this->writeExclusively(function () use ($specification, $sets, $params): int {
            $where = $this->compileOrFallback($specification);

            return $this->transactions->transactional(function () use ($where, $specification, $sets, $params): int {
                if ($this->softDelete !== null && $this->scope === self::SCOPE_ONLY_TRASHED) {
                    return 0;   // writes only touch live rows: none is visible here
                }
                $updatedAt = $this->mapping->getUpdatedAt();
                if ($updatedAt !== null) {
                    $sets[] = $this->quote($updatedAt->column) . ' = :lc_updated_at';
                    $params[':lc_updated_at'] = $this->converter->toDatabase($updatedAt, $this->stamp($updatedAt, $this->now()));
                }
                $version = $this->mapping->getVersion();
                if ($version !== null) {
                    $quoted = $this->quote($version->column);
                    $sets[] = $quoted . ' = COALESCE(' . $quoted . ', 0) + 1';
                }
                $head = 'UPDATE ' . $this->tableSql . ' SET ' . implode(', ', $sets);

                if ($where !== null) {
                    $where = $this->liveOnly($where);

                    return $this->run($head . $this->whereSql($where), $params + $where->getParameters())->rowCount();
                }

                // Evaluated in PHP: the matching live rows, then UPDATE by identifier
                $ids = [];
                foreach ($this->stream(null, $specification, null, null, [], self::SCOPE_LIVE) as $entity) {
                    $ids[] = $this->identifierOf($entity);
                }
                $updated = 0;
                foreach (array_chunk($ids, self::DELETE_CHUNK) as $chunk) {
                    $in = $this->inList($chunk, 'd');
                    $where = $this->liveOnly(new SqlWhereClause($this->quote($this->idColumn->column) . ' IN (' . $in['sql'] . ')', $in['params']));
                    $updated += $this->run($head . $this->whereSql($where), $params + $where->getParameters())->rowCount();
                }

                return $updated;
            });
        });
    }

    /**
     * The columns of readable mapped properties (the rule of the specifications), keyed by property.
     *
     * @param array<mixed> $properties
     * @return array<string, ColumnMapping>
     * @throws InvalidArgumentException When none is given, one is repeated or one is not readable
     */
    private function readableColumns(array $properties, string $operation): array
    {
        if ($properties === []) {
            throw new InvalidArgumentException(sprintf('Nothing to %s: give at least one property.', $operation));
        }
        $columns = [];
        foreach ($properties as $property) {
            $reason = null;
            $column = is_string($property) ? $this->mapping->findQueryColumn($property, $reason) : null;
            if ($column === null) {
                throw new InvalidArgumentException(sprintf('Cannot %s "%s": %s.', $operation, is_scalar($property) ? (string) $property : get_debug_type($property), (string) ($reason ?? 'not a property name')));
            }
            if (isset($columns[$property])) {
                throw new InvalidArgumentException(sprintf('Cannot %s "%s" twice.', $operation, $property));
            }
            $columns[$property] = $column;
        }

        return $columns;
    }

    /**
     * Streams the projection of select(): the columns of the rows (SQL filter), or the properties of the
     * entities evaluated in PHP (fallback).
     *
     * @param array<string, ColumnMapping> $columns
     * @return Generator<int, array<string, mixed>>
     */
    private function project(?SqlWhereClause $where, ?ISpecification $memoryFilter, array $columns): Generator
    {
        if ($memoryFilter !== null) {
            foreach ($this->stream(null, $memoryFilter) as $entity) {
                $row = [];
                foreach ($columns as $property => $column) {
                    $row[$property] = $this->mapping->readValue($entity, $column->property);
                }
                yield $row;
            }
            return;
        }

        $where = $this->scoped($where);
        $sql = 'SELECT ' . implode(', ', array_map(fn(ColumnMapping $c): string => $this->quote($c->column), array_values($columns)))
            . ' FROM ' . $this->tableSql . $this->whereSql($where) . $this->orderSql([]);
        $statement = $this->run($sql, $where->getParameters());
        try {
            while (($raw = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                $row = [];
                $i = 0;
                foreach ($columns as $property => $column) {
                    $row[$property] = $this->converter->fromDatabase($column, $raw[$i++] ?? null);
                }
                yield $row;
            }
        } finally {
            $statement->closeCursor();
        }
    }

    /**
     * Runs an aggregate query (PdoAggregateQuery::get()).
     *
     * @param array<string, ColumnMapping> $groups
     * @param list<array{function: string, alias: string, column: ?ColumnMapping}> $aggregates
     * @return list<array<string, mixed>>
     */
    private function runAggregate(SqlWhereClause $where, array $groups, array $aggregates): array
    {
        $select = [];
        foreach ($groups as $column) {
            $select[] = $this->quote($column->column);
        }
        foreach ($aggregates as $aggregate) {
            $column = $aggregate['column'];
            if ($column === null) {
                $select[] = 'COUNT(*)';
                continue;
            }
            $expression = $this->quote($column->column);
            if ($this->family === 'sqlite' && $column->type === ColumnType::Decimal && $aggregate['function'] !== 'count') {
                // SQLite has no decimal type: compare and add numbers, never the stored text
                $expression = 'CAST(' . $expression . ' AS REAL)';
            }
            $select[] = strtoupper($aggregate['function']) . '(' . $expression . ')';
        }

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . $this->tableSql . $this->whereSql($where);
        if ($groups !== []) {
            $keys = array_map(fn(ColumnMapping $c): string => $this->quote($c->column), array_values($groups));
            $sql .= ' GROUP BY ' . implode(', ', $keys);
            $order = [];
            foreach ($keys as $key) {
                // NULL group first on every database (they disagree on where NULL sorts)
                $order[] = 'CASE WHEN ' . $key . ' IS NULL THEN 0 ELSE 1 END';
                $order[] = $key;
            }
            $sql .= ' ORDER BY ' . implode(', ', $order);
        }

        $statement = $this->run($sql, $where->getParameters());
        $rows = [];
        try {
            while (($raw = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                $row = [];
                $i = 0;
                foreach ($groups as $property => $column) {
                    $row[$property] = $this->converter->fromDatabase($column, $raw[$i++] ?? null);
                }
                foreach ($aggregates as $aggregate) {
                    $row[$aggregate['alias']] = $this->aggregateValue($aggregate['function'], $aggregate['column'], $raw[$i++] ?? null);
                }
                $rows[] = $row;
            }
        } finally {
            $statement->closeCursor();
        }

        return $rows;
    }

    /**
     * PHP value of an aggregate (see PdoAggregateQuery).
     */
    private function aggregateValue(string $function, ?ColumnMapping $column, mixed $raw): mixed
    {
        if ($function === 'count') {
            return (int) $raw;
        }
        if ($raw === null || $column === null) {
            return null;
        }
        if ($column->type === ColumnType::Decimal) {
            return PdoValueConverter::canonicalDecimal($raw);
        }
        if ($function === 'sum' && $column->type === ColumnType::Integer) {
            if (is_int($raw)) {
                return $raw;
            }
            $text = is_float($raw) ? sprintf('%.0F', $raw) : PdoValueConverter::canonicalDecimal($raw);
            return preg_match('/^-?[0-9]+$/', $text) === 1 && (string) (int) $text === $text ? (int) $text : $text;
        }
        if ($function === 'sum' || $function === 'avg') {
            return (float) $raw;
        }

        return $this->converter->fromDatabase($column, $raw);
    }

    // What this trait calls and PdoRepository or another of its traits implements

    abstract private function compiler(string $parameterPrefix = 'p'): PdoSpecificationCompiler;

    abstract private function compileOrFallback(ISpecification $specification): ?SqlWhereClause;

    abstract private function scoped(?SqlWhereClause $where, ?string $scope = null): SqlWhereClause;

    abstract private function liveOnly(?SqlWhereClause $where): SqlWhereClause;

    abstract private function stream(?SqlWhereClause $where, ?ISpecification $memoryFilter, ?int $limit = null, ?int $offset = null, array $orderBy = [], ?string $scope = null): Generator;

    abstract private function whereSql(SqlWhereClause $where): string;

    abstract private function orderSql(array $orderBy): string;

    abstract private function now(): DateTimeImmutable;

    abstract private function stamp(ColumnMapping $column, DateTimeImmutable $now): DateTimeInterface;

    abstract private function inList(array $values, string $prefix): array;

    abstract private function identifierOf(IEntity $entity): mixed;

    abstract private function quote(string $identifier): string;

    abstract private function run(string $sql, array $params = []): PDOStatement;
}
