<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Subquery;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Sql\IFieldMapper;
use Antevemus\ASpecification\Specifications\Exceptions\DatabaseOnlySpecificationException;
use Antevemus\ASpecification\Sql\Exceptions\UnsafeIdentifierException;
use Antevemus\ASpecification\Sql\FieldMapper;
use Closure;
use ReflectionClass;

/**
 * AbstractSubquerySpecification - A Condition Over Another Table, Evaluated by the Database (2.5.0)
 *
 * Base of the subqueries of Spec::inSubquery() and Spec::existsIn() (forward 048, L5). The other table is
 * read with the alias `sq`, and the filter `$where` is an ordinary specification over its columns,
 * translated by the same visitor as the rest of the tree: every value is a bound parameter, and only
 * identifiers validated here reach the SQL text (D18).
 *
 * Identifiers: a table, a column or a property is one or more segments joined by dots; a segment is plain,
 * `[A-Za-z_][A-Za-z0-9_$]*` (the grammar of the SQL dialects), or written between double quotes with no
 * double quote, backslash or control character inside (`"aflow-engine".module_states`). Anything else is
 * an UnsafeIdentifierException when the specification is built. A plain segment is quoted by the dialect
 * in toSql(); in a TCriteria every identifier goes out as written, like the column names of TFilter.
 *
 * Evaluation: only by the database. In memory (isSatisfiedBy(), the ALinq visitor) it raises
 * DatabaseOnlySpecificationException, so evaluate() answers an evaluation error, never true nor false, and
 * a NOT does not invert it (D21). isTautology()/isContradiction() are false and the algebra treats the
 * leaf as opaque (reflexivity only); equals() compares the table, the columns, the outer link, the filter
 * and the field mapping of the other table (which changes the SQL emitted). Not serializable (D22).
 *
 * The other table receives none of the filters an ORM adds to its own model (soft delete, prefilters of a
 * TRepository): write them in `$where` (`Spec::property('deletedAt', Spec::isNull())`) (D23). JOIN, UNION
 * and aggregates inside the subquery are out of reach (D24).
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Subquery
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractSubquerySpecification extends AbstractSpecification implements ILeafSpecification
{
    /** Alias of the other table inside the subquery. */
    public const ALIAS = 'sq';

    /** One segment: plain, or between double quotes without `"`, `\` and control characters. */
    private const SEGMENT = '(?:[A-Za-z_][A-Za-z0-9_$]*|"[^"\\\\\x00-\x1F\x7F]+")';

    /** Grammar of an identifier: segments joined by dots. */
    private const IDENTIFIER = '/^' . self::SEGMENT . '(?:\.' . self::SEGMENT . ')*$/D';

    /** Text of the grammar, for the exception. */
    private const GRAMMAR = 'A subquery accepts identifiers made of dot-separated segments, each plain '
        . '([A-Za-z_][A-Za-z0-9_$]*) or between double quotes without ", \\ or control characters '
        . '("aflow-engine".module_states).';

    /**
     * @param string $table Other table (optionally schema-qualified)
     * @param ISpecification $where Filter over the columns of the other table
     * @param IFieldMapper|array<string, string>|Closure(string): string|null $innerFieldMap Property to column
     *        mapping of the other table (null: the property name, camelCase as snake_case)
     * @throws UnsafeIdentifierException When the table is not a valid identifier
     */
    protected function __construct(
        private readonly string $table,
        private readonly ISpecification $where,
        private readonly IFieldMapper|array|Closure|null $innerFieldMap = null
    ) {
        self::assertIdentifier($table);
    }

    /** The other table, as written. */
    public function getTable(): string
    {
        return $this->table;
    }

    /** The filter over the other table. */
    public function getWhere(): ISpecification
    {
        return $this->where;
    }

    /**
     * The field mapping of the other table, as given.
     *
     * @return IFieldMapper|array<string, string>|Closure(string): string|null
     */
    public function getInnerFieldMap(): IFieldMapper|array|Closure|null
    {
        return $this->innerFieldMap;
    }

    /**
     * The mapper the visitors use for `$where`: the given mapping, every column qualified with the alias
     * `sq` (a column already qualified, or an expression, is kept as mapped). Qualifying keeps a column the
     * other table lacks from silently resolving to the outer query.
     */
    public function innerColumnMapper(): IFieldMapper
    {
        $mapper = FieldMapper::from($this->innerFieldMap);

        return new FieldMapper(static fn(string $property): string => $mapper->mapField($property), self::ALIAS);
    }

    /** {@inheritdoc} */
    public function getType(): string
    {
        return 'mixed';
    }

    /**
     * Never evaluated in memory.
     *
     * @throws DatabaseOnlySpecificationException Always
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        throw new DatabaseOnlySpecificationException((new ReflectionClass($this))->getShortName());
    }

    /**
     * Validates an identifier of a subquery (table, column, property).
     *
     * @throws UnsafeIdentifierException When it is outside the grammar
     */
    protected static function assertIdentifier(string $identifier): void
    {
        if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
            throw new UnsafeIdentifierException($identifier, self::GRAMMAR);
        }
    }

    /**
     * The segments of a validated identifier, each with whether it was written between double quotes (the
     * quotes removed).
     *
     * @return list<array{0: string, 1: bool}>
     */
    public static function segments(string $identifier): array
    {
        self::assertIdentifier($identifier);
        preg_match_all('/' . self::SEGMENT . '/', $identifier, $matches);

        return array_map(
            static fn(string $segment): array => str_starts_with($segment, '"') ? [substr($segment, 1, -1), true] : [$segment, false],
            $matches[0]
        );
    }
}
