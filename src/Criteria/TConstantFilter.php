<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Criteria;

use Adianti\Database\TFilter;

/**
 * TConstantFilter - The Constant Conditions `1 = 1` and `1 = 0` of a TCriteria, Never Bound
 *
 * The TCriteria visitor wrote the tautology and the contradiction (alwaysTrue(), alwaysFalse(), their
 * negations, `in([])` and `notIn([])`) as `new TFilter('1', '=', 1)` / `('1', '=', 0)`. With `prep = 1` Adianti
 * binds the value as a parameter (`1 = :par_1`) and `TRepository` passes it to `execute()`, which sends it as
 * text: on SQLite `1 = '1'` is false (no affinity between a literal and a parameter), so the tautology selected
 * no row there (PostgreSQL and MySQL convert, and answered right). This subclass owns its rendering: dump() is
 * the plain text `1 = 1` or `1 = 0` in both modes and getPreparedVars() is empty, so the condition means the same
 * on every database (2.5.0, BUG of forward 048).
 *
 * @internal Built by CriteriaSpecificationVisitor; not meant to be constructed by consumers.
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Criteria
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class TConstantFilter extends TFilter
{
    private function __construct(private readonly bool $holds)
    {
        parent::__construct('1', '=', $holds ? 1 : 0);
    }

    /** `1 = 1`: true for every row. */
    public static function alwaysTrue(): self
    {
        return new self(true);
    }

    /** `1 = 0`: true for no row. */
    public static function alwaysFalse(): self
    {
        return new self(false);
    }

    /**
     * The constant condition, as plain text in both modes.
     *
     * @param bool $prepared Ignored: there is no value to bind
     * @return string
     */
    public function dump($prepared = false): string
    {
        return $this->holds ? '1 = 1' : '1 = 0';
    }

    /**
     * No value is bound.
     *
     * @return array<string, mixed>
     */
    public function getPreparedVars(): array
    {
        return [];
    }
}
