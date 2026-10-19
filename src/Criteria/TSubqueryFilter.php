<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Criteria;

use Adianti\Database\TCriteria;
use Adianti\Database\TFilter;

/**
 * TSubqueryFilter - TFilter Holding a Subquery Whose Filter Is a TCriteria
 *
 * Written by the TCriteria visitor for Spec::inSubquery() (`col IN (SELECT sq.id FROM t sq WHERE
 * sq.id IS NOT NULL AND (...))`) and Spec::existsIn() (`EXISTS (SELECT 1 FROM t sq WHERE sq.fk = o.id AND
 * (...))`), negated as `NOT IN`/`NOT EXISTS` (forward 048, D19). The head of the subquery holds only
 * identifiers validated by the specification; the filter of the other table is a TCriteria built by the same
 * visitor, whose values are bound by the TFilter of Adianti itself (`:par_N`).
 *
 * The value of a TFilter never carries the subquery: TFilter::transform() is never reached, so there is no
 * raw `(SELECT` passthrough, no removal of `#`, `--` and `/*` from the literals and no `:[v]:`
 * pseudo-variable (the three defects measured in the Adianti 8.1 to 8.7 TFilter). dump() writes the head
 * and the dump of the inner criteria; getPreparedVars() returns the inner criteria's. The inner filters
 * behave as any filter of Adianti: a connection with `prep = 1` binds their values; with `prep = 0` Adianti
 * writes them quoted without escaping, as for every other TFilter.
 *
 * setCaseInsensitive() is forwarded to the inner criteria, which passes it on to its filters as if they
 * were direct children; a clone clones the inner criteria (TRepository clones the criteria it receives).
 *
 * @internal Built by CriteriaSpecificationVisitor; not meant to be constructed by consumers.
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Criteria
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class TSubqueryFilter extends TFilter
{
    /**
     * @param string $head The condition up to the inner filter, ending with `AND ` (identifiers only)
     * @param TCriteria $where Filter of the other table
     * @param string $variable Column of the outer query ('' for EXISTS), for TFilter
     * @param string $operator IN, NOT IN, EXISTS or NOT EXISTS, for TFilter
     */
    public function __construct(
        private readonly string $head,
        private TCriteria $where,
        string $variable,
        string $operator
    ) {
        parent::__construct($variable, $operator, '');
    }

    /**
     * The head, the dump of the inner criteria and the closing parenthesis.
     *
     * @param bool $prepared Whether the inner filters bind their values (Adianti's prepared mode)
     * @return string
     */
    public function dump($prepared = false): string
    {
        // The inner criteria is never empty (the visitor builds at least one filter), so dump() is a string
        return $this->head . $this->where->dump($prepared) . ')';
    }

    /**
     * The values bound by the inner filters in the last dump().
     *
     * @return array<string, mixed>
     */
    public function getPreparedVars(): array
    {
        return (array) $this->where->getPreparedVars();
    }

    /**
     * Forwarded to the inner criteria.
     *
     * @param bool $value
     */
    public function setCaseInsensitive(bool $value): void
    {
        $this->where->setCaseInsensitive($value);
    }

    public function __clone()
    {
        $this->where = clone $this->where;
    }
}
