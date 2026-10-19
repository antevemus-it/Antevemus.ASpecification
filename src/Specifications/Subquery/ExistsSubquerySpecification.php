<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Subquery;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Sql\IFieldMapper;
use Antevemus\ASpecification\Sql\Exceptions\UnsafeIdentifierException;
use Closure;
use InvalidArgumentException;

/**
 * ExistsSubquerySpecification - A Related Row Exists in Another Table (2.5.0, forward 048, D17)
 *
 * Built by `Spec::existsIn('claim_document', ['claim_id' => 'id'], $where, 'claim')`: some row of `$table`,
 * linked to the candidate row by `$on` (column of the other table => property of the candidate), satisfies
 * `$where`. It is not bound to a property: it is a condition of the row.
 *
 * - toSql(): `EXISTS (SELECT 1 FROM "claim_document" "sq" WHERE "sq"."claim_id" = "claim"."id" AND (...))`,
 *   the outer property passing through the outer field mapping and qualified with `$outerTable`;
 * - toCriteria(): the same text, unquoted, written by TSubqueryFilter (`claim` must be the table of the
 *   model, the one TRepository selects from);
 * - the negation is `NOT EXISTS`.
 *
 * `$outerTable` is required: an unqualified outer column would be resolved against the other table when
 * both have a column of that name. An outer property already qualified by the field mapping keeps its
 * qualifier. In memory it is an evaluation error (see AbstractSubquerySpecification).
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
final class ExistsSubquerySpecification extends AbstractSubquerySpecification
{
    /** @var array<string, string> Column of the other table => property of the candidate */
    private readonly array $on;

    /**
     * @param string $table Other table (optionally schema-qualified)
     * @param array<string, string> $on Column of the other table => property of the candidate (one or more)
     * @param ISpecification $where Filter over the columns of the other table
     * @param string $outerTable Table (or alias) of the outer query the properties belong to
     * @param IFieldMapper|array<string, string>|Closure(string): string|null $innerFieldMap Property to column
     *        mapping of the other table
     * @throws InvalidArgumentException When $on is empty or not a map of strings
     * @throws UnsafeIdentifierException When a table, a column or a property is not a valid identifier
     */
    public function __construct(
        string $table,
        array $on,
        ISpecification $where,
        private readonly string $outerTable,
        IFieldMapper|array|Closure|null $innerFieldMap = null
    ) {
        parent::__construct($table, $where, $innerFieldMap);
        self::assertIdentifier($outerTable);
        if ($on === []) {
            throw new InvalidArgumentException('An EXISTS subquery needs at least one link (column of the other table => property of the candidate).');
        }
        $links = [];
        foreach ($on as $inner => $outer) {
            if (!is_string($inner) || !is_string($outer)) {
                throw new InvalidArgumentException('The links of an EXISTS subquery map a column name of the other table to a property name.');
            }
            self::assertIdentifier($inner);
            self::assertIdentifier($outer);
            $links[$inner] = $outer;
        }
        $this->on = $links;
    }

    /**
     * The links: column of the other table => property of the candidate.
     *
     * @return array<string, string>
     */
    public function getOn(): array
    {
        return $this->on;
    }

    /** The table (or alias) of the outer query, as written. */
    public function getOuterTable(): string
    {
        return $this->outerTable;
    }

    /** {@inheritdoc} */
    public function __toString(): string
    {
        return sprintf('ExistsIn(%s ON %s WHERE %s)', $this->getTable(), json_encode($this->on), (string) $this->getWhere());
    }
}
