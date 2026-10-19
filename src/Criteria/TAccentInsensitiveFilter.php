<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Criteria;

use Adianti\Database\TFilter;
use Antevemus\ASpecification\Contracts\Sql\ISqlAccentInsensitiveLike;
use Antevemus\ASpecification\Sql\LikePattern;
use LogicException;

/**
 * TAccentInsensitiveFilter - TFilter Comparing Text Ignoring Case and Accents for a Declared Database
 *
 * The Adianti TFilter renders `variable operator value` with the value quoted or bound inside, so a
 * folding function cannot wrap the value; the variable, however, goes out as written. This subclass owns
 * its rendering (dump() and getPreparedVars(), like TEscapedLikeFilter owns the ESCAPE clause): it writes
 * exactly the condition the dialect writes for toSql(), `formatAccentInsensitiveLike(column, :aspf_N)`
 * (plus ` ESCAPE '!'` when the pattern needs it) or `accentInsensitiveRegex()`, with the value bound raw
 * under a placeholder of its own, so the database folds both sides as in toSql() (forward 048, D14). The
 * placeholder is renewed at every dump(), from a counter of its own (`:aspf_N` never meets the `:par_N` of
 * Adianti).
 *
 * - Negation: `NOT (<condition>)`; the visitor adds `col IS NULL OR` around it, as for every negated
 *   comparison (a null value fails the comparison in memory, so its negation accepts it).
 * - Case: fixed by the condition; setCaseInsensitive() (TCriteria::dump() resets every child) is ignored.
 * - Prepared mode (D15): the placeholder is written and bound in both modes, as Adianti does with the
 *   pseudo-variables of a subselect. A connection with `prep = 1` executes it; one with `prep = 0` gets an
 *   SQL error at execution (a placeholder without value), never a value concatenated into the text.
 *
 * @internal Built by CriteriaSpecificationVisitor (TCriteriaBuilder::withAccentFolding()); not meant to be
 *           constructed by consumers.
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Criteria
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class TAccentInsensitiveFilter extends TFilter
{
    /** Prefix of the placeholders of this filter. */
    public const PLACEHOLDER_PREFIX = ':aspf_';

    private static int $placeholderCounter = 0;

    /** @var array<string, mixed> Values bound by the last dump() */
    private array $boundValues = [];

    /**
     * @param string $column Column (or mapped expression), as TFilter writes it
     * @param ISqlAccentInsensitiveLike $dialect Database the criteria will run on
     * @param LikePattern|string $operand LIKE pattern of the literal, or the folded body of a regular expression
     * @param bool $negated Whether the condition is negated
     */
    public function __construct(
        private readonly string $column,
        private readonly ISqlAccentInsensitiveLike $dialect,
        private readonly LikePattern|string $operand,
        private readonly bool $negated = false
    ) {
        parent::__construct(
            $column,
            $negated ? 'NOT LIKE' : 'LIKE',
            $operand instanceof LikePattern ? $operand->pattern : $operand
        );
    }

    /**
     * The condition of the dialect for the column, with a fresh placeholder.
     *
     * @param bool $prepared Ignored: the value is always bound (D15)
     * @return string
     * @throws LogicException When the dialect does not translate the comparison (refused before by the visitor)
     */
    public function dump($prepared = false): string
    {
        $placeholder = self::PLACEHOLDER_PREFIX . (++self::$placeholderCounter);

        if ($this->operand instanceof LikePattern) {
            $condition = $this->dialect->formatAccentInsensitiveLike($this->column, $placeholder);
            if ($condition === null) {
                throw new LogicException('The dialect does not compare text ignoring case and accents.');
            }
            $condition .= $this->operand->escapeClause();
            $this->boundValues = [$placeholder => $this->operand->pattern];
        } else {
            $clause = $this->dialect->accentInsensitiveRegex($this->column, $placeholder, $this->operand);
            if ($clause === null) {
                throw new LogicException('The dialect does not match a regular expression ignoring case and accents.');
            }
            $condition = $clause->getSql();
            $this->boundValues = $clause->getParameters();
        }

        return $this->negated ? "NOT ({$condition})" : $condition;
    }

    /**
     * The values bound by the last dump(), by placeholder.
     *
     * @return array<string, mixed>
     */
    public function getPreparedVars(): array
    {
        return $this->boundValues;
    }

    /**
     * Ignored: the condition fixes the comparison (TCriteria::dump() resets the flag of every child).
     *
     * @param bool $value
     */
    public function setCaseInsensitive(bool $value): void
    {
        // The condition of the dialect already ignores case: the flag of TCriteria::dump() has no effect here
    }
}
