<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo;

use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnMapping;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\EntityMapping;
use Closure;
use InvalidArgumentException;

/**
 * PdoAggregateQuery - Grouped Aggregates of a PdoRepository, Computed by the Database
 *
 * Built by PdoRepository::aggregate($specification) (since 1.13.0, forward 031, D6); immutable: every
 * call returns a new query.
 *
 * <code>
 * $rows = $sales->aggregate(Spec::property('qty', Spec::greaterThan(0)))
 *     ->groupBy('region')
 *     ->sum('amount')->avg('qty')->min('soldOn')->max('soldOn')->count()
 *     ->get();
 * // [['region' => null, 'sum_amount' => '12.5', 'avg_qty' => 2.5, ...], ['region' => 'north', ...]]
 * </code>
 *
 * - only readable mapped properties (the rule of the specifications, EntityMapping::findQueryColumn())
 *   are grouped or aggregated; column names come from the mapping, every value of the filter is bound
 * - the filter applies the read scope of the repository (soft delete: live rows only, or what the
 *   withTrashed()/onlyTrashed() view sees)
 * - get() returns one array per group, keyed by property name for the group keys (in groupBy() order)
 *   and by the alias of each aggregate (default `count`, `count_<prop>`, `sum_<prop>`, `avg_<prop>`,
 *   `min_<prop>`, `max_<prop>`; or the alias given), groups ordered by their keys ascending with the
 *   NULL group first (the order of strings follows the database's collation)
 * - values: group keys and min/max are converted like a read value of their column (a date column
 *   gives a date object, an enum column the case); count is an int; sum of an Integer column is an int
 *   (a decimal string when it exceeds PHP_INT_MAX); avg of an Integer column and sum/avg of a Float
 *   column are floats; sum, avg, min and max of a Decimal column are decimal strings in canonical form
 *   (no trailing fractional zeros: '12.5', not '12.50'; the database's precision for avg; SQLite has no
 *   decimal arithmetic and computes them in floating point, rounded here to 15 significant digits)
 * - NULL semantics are SQL's: sum/avg/min/max ignore NULL values and give null over a group without
 *   values (sum of nothing is null, not 0); count() counts rows, count('prop') non-null values;
 *   without groupBy() there is exactly one row, even over an empty table (count 0, the others null);
 *   with groupBy() an empty selection gives no row; the NULL values of a key form one group
 * - sum/avg take Integer, Float and Decimal columns; min/max any column but Boolean and Json;
 *   groupBy() any column but Json; count() any column
 * - a specification that cannot be translated to SQL is refused even with the in-memory fallback on
 *   (the aggregate is the database's)
 *
 * @version    1.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoAggregateQuery
{
    /** @var array<string, ColumnMapping> Group keys: property => column */
    private array $groups = [];

    /** @var list<array{function: string, alias: string, column: ?ColumnMapping}> */
    private array $aggregates = [];

    /**
     * @param EntityMapping $mapping Mapping of the repository (effective one)
     * @param Closure(array<string, ColumnMapping>, list<array{function: string, alias: string, column: ?ColumnMapping}>): list<array<string, mixed>> $executor
     * @internal Built by PdoRepository::aggregate()
     */
    public function __construct(
        private readonly EntityMapping $mapping,
        private readonly Closure $executor
    ) {
    }

    /**
     * Groups by one or more properties (added after the ones already given).
     *
     * @throws InvalidArgumentException When a property is not readable, is a Json column or is repeated
     */
    public function groupBy(string ...$properties): self
    {
        if ($properties === []) {
            throw new InvalidArgumentException('groupBy() needs at least one property.');
        }
        $copy = clone $this;
        foreach ($properties as $property) {
            $column = $this->column($property, 'group by');
            if ($column->type === ColumnType::Json) {
                throw new InvalidArgumentException(sprintf('Cannot group by "%s": a Json column has no portable equality.', $property));
            }
            if (isset($copy->groups[$property])) {
                throw new InvalidArgumentException(sprintf('"%s" is already a group key.', $property));
            }
            $copy->assertFreeKey($property);
            $copy->groups[$property] = $column;
        }

        return $copy;
    }

    /**
     * Counts the rows (no property) or the non-null values of a property.
     *
     * @throws InvalidArgumentException When the property is not readable or the alias is taken
     */
    public function count(?string $property = null, ?string $alias = null): self
    {
        return $this->add('count', $property, $alias);
    }

    /**
     * Sum of a numeric property (Integer, Float or Decimal column).
     *
     * @throws InvalidArgumentException When the property is not readable or not numeric, or the alias is taken
     */
    public function sum(string $property, ?string $alias = null): self
    {
        return $this->add('sum', $property, $alias);
    }

    /**
     * Average of a numeric property (Integer, Float or Decimal column).
     *
     * @throws InvalidArgumentException When the property is not readable or not numeric, or the alias is taken
     */
    public function avg(string $property, ?string $alias = null): self
    {
        return $this->add('avg', $property, $alias);
    }

    /**
     * Smallest value of a property (not Boolean nor Json).
     *
     * @throws InvalidArgumentException When the property is not readable or not comparable, or the alias is taken
     */
    public function min(string $property, ?string $alias = null): self
    {
        return $this->add('min', $property, $alias);
    }

    /**
     * Largest value of a property (not Boolean nor Json).
     *
     * @throws InvalidArgumentException When the property is not readable or not comparable, or the alias is taken
     */
    public function max(string $property, ?string $alias = null): self
    {
        return $this->add('max', $property, $alias);
    }

    /**
     * Runs the query: one array per group (see the class description).
     *
     * @return list<array<string, mixed>>
     * @throws InvalidArgumentException When neither a group nor an aggregate was given
     * @throws Exceptions\PdoRepositoryException When the driver refuses the statement
     */
    public function get(): array
    {
        if ($this->groups === [] && $this->aggregates === []) {
            throw new InvalidArgumentException('An aggregate query needs groupBy() or at least one aggregate.');
        }

        return ($this->executor)($this->groups, $this->aggregates);
    }

    private function add(string $function, ?string $property, ?string $alias): self
    {
        $column = null;
        if ($property !== null) {
            $column = $this->column($property, $function);
            $type = $column->type;
            if (($function === 'sum' || $function === 'avg')
                && !in_array($type, [ColumnType::Integer, ColumnType::Float, ColumnType::Decimal], true)) {
                throw new InvalidArgumentException(sprintf('Cannot %s "%s": a %s column is not numeric.', $function, $property, $type->value));
            }
            if (($function === 'min' || $function === 'max') && ($type === ColumnType::Boolean || $type === ColumnType::Json)) {
                throw new InvalidArgumentException(sprintf('Cannot take the %s of "%s": a %s column has no portable order.', $function, $property, $type->value));
            }
        }
        $alias ??= $property === null ? $function : $function . '_' . $property;
        if (trim($alias) === '') {
            throw new InvalidArgumentException('An aggregate alias cannot be empty.');
        }

        $copy = clone $this;
        $copy->assertFreeKey($alias);
        $copy->aggregates[] = ['function' => $function, 'alias' => $alias, 'column' => $column];

        return $copy;
    }

    private function column(string $property, string $operation): ColumnMapping
    {
        $reason = null;
        $column = $this->mapping->findQueryColumn($property, $reason);
        if ($column === null) {
            throw new InvalidArgumentException(sprintf('Cannot %s "%s": %s.', $operation, $property, (string) $reason));
        }

        return $column;
    }

    private function assertFreeKey(string $key): void
    {
        $taken = isset($this->groups[$key]);
        foreach ($this->aggregates as $aggregate) {
            $taken = $taken || $aggregate['alias'] === $key;
        }
        if ($taken) {
            throw new InvalidArgumentException(sprintf('The result key "%s" is already used by this aggregate query.', $key));
        }
    }
}
