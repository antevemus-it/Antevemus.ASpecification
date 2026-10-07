<?php
declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\String;

/**
 * LiteralPatternSpecification - Base for prefix, suffix and substring matching of a literal.
 *
 * Keeps the literal the consumer wrote (`startsWith('Ab')`) next to the PCRE pattern that
 * evaluates it in memory, so that translators can emit the idiomatic portable form
 * (SQL `LIKE 'Ab%'`) instead of a regular expression (BUG-20261007-3E3F).
 *
 * Features:
 * - In-memory evaluation inherited from RegexSpecification (identical semantics, case flag)
 * - Original literal and case sensitivity exposed to visitors
 * - Position-aware wildcard shaping (`toWildcardPattern()`) independent of the target syntax
 *
 * @template T
 * @extends RegexSpecification<T>
 * @version    1.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\String
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class LiteralPatternSpecification extends RegexSpecification
{
    /**
     * @param string $literal Literal text to look for (no pattern syntax)
     * @param bool $caseSensitive Whether matching is case-sensitive
     */
    public function __construct(
        private readonly string $literal,
        private readonly bool $caseSensitive = true
    ) {
        parent::__construct($this->buildPattern(preg_quote($literal, '/')) . ($caseSensitive ? '' : 'i'));
    }

    /**
     * Returns the literal text the consumer asked for.
     *
     * @return string
     */
    public function getLiteral(): string
    {
        return $this->literal;
    }

    /**
     * Whether matching is case-sensitive.
     *
     * @return bool
     */
    public function isCaseSensitive(): bool
    {
        return $this->caseSensitive;
    }

    /**
     * Places the (already escaped) literal in a wildcard pattern for the target syntax.
     *
     * @param string $escapedLiteral Literal with the target syntax's wildcards escaped
     * @param string $any Wildcard meaning "any sequence" in the target syntax (`%` for SQL LIKE, `*` for globs)
     * @return string
     */
    abstract public function toWildcardPattern(string $escapedLiteral, string $any): string;

    /**
     * Builds the delimited PCRE pattern (without modifiers) around the quoted literal.
     *
     * @param string $quotedLiteral Literal already passed through preg_quote()
     * @return string
     */
    abstract protected function buildPattern(string $quotedLiteral): string;
}
