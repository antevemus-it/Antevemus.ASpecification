<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Subquery;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Sql\IFieldMapper;
use Antevemus\ASpecification\Sql\Exceptions\UnsafeIdentifierException;
use Closure;

/**
 * InSubquerySpecification - Membership in a Column of Another Table (2.5.0, forward 048, D17)
 *
 * Built by `Spec::inSubquery('moduleStateId', 'module_states', 'id', $where)`, always inside a property
 * clause: the value of the property is one of the values of `$column` in the rows of `$table` that satisfy
 * `$where`.
 *
 * - toSql(): `"col" IN (SELECT "sq"."id" FROM "module_states" "sq" WHERE "sq"."id" IS NOT NULL AND (...))`,
 *   the parameters of `$where` named apart (`:ps1_1`), guarded against a NULL column like any comparison;
 * - toCriteria(): `col IN (SELECT sq.id FROM module_states sq WHERE sq.id IS NOT NULL AND (...))`, written by
 *   TSubqueryFilter with the filters of `$where` bound by the TFilter of Adianti (`:par_N`), never through
 *   the raw `(SELECT` passthrough of TFilter;
 * - the negation is `NOT IN`, true for a NULL column (as the other negated comparisons) and two-valued:
 *   the selected column is always filtered with `IS NOT NULL`, so a NULL in the other table never turns
 *   `NOT IN` into "unknown".
 *
 * The other table is read as it is (no soft delete, no prefilters; write them in `$where`); in memory it
 * is an evaluation error (see AbstractSubquerySpecification). Inside a PdoRepository, use Spec::inQuery(),
 * which applies the mapping and the read scope of the other repository.
 *
 * @template T
 * @extends AbstractSubquerySpecification<T>
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Subquery
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InSubquerySpecification extends AbstractSubquerySpecification
{
    /**
     * @param string $table Other table (optionally schema-qualified)
     * @param string $column Column of the other table whose values are selected
     * @param ISpecification $where Filter over the columns of the other table
     * @param IFieldMapper|array<string, string>|Closure(string): string|null $innerFieldMap Property to column
     *        mapping of the other table
     * @throws UnsafeIdentifierException When the table or the column is not a valid identifier
     */
    public function __construct(
        string $table,
        private readonly string $column,
        ISpecification $where,
        IFieldMapper|array|Closure|null $innerFieldMap = null
    ) {
        parent::__construct($table, $where, $innerFieldMap);
        self::assertIdentifier($column);
    }

    /** The selected column of the other table, as written. */
    public function getColumn(): string
    {
        return $this->column;
    }

    /** {@inheritdoc} */
    public function __toString(): string
    {
        return sprintf('InSubquery(%s.%s WHERE %s)', $this->getTable(), $this->column, (string) $this->getWhere());
    }
}
