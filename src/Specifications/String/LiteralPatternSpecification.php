<?php
declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\String;

use InvalidArgumentException;
use Antevemus\ASpecification\Serialization\SpecificationCodec;

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
 * - The comparison as a TextComparison (2.1.0): `true`/`false` keep their meaning and
 *   `TextComparison::IgnoreCaseAndAccents` also ignores accents. With it, the pattern is built over
 *   the folded literal (TextComparison::foldCaseAndAccents()) and matched against the folded
 *   candidate; `getLiteral()` still returns the literal as written. A visitor that translates this
 *   class must check `ignoresAccents()` first: `isCaseSensitive()` alone describes the 1.14 options.
 *
 * @template T
 * @extends RegexSpecification<T>
 * @version    2.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\String
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class LiteralPatternSpecification extends RegexSpecification
{
    private readonly TextComparison $comparison;

    /**
     * @param string $literal Literal text to look for (no pattern syntax)
     * @param bool|TextComparison $caseSensitive `true` (CaseSensitive), `false` (IgnoreCase) or a TextComparison
     * @throws \Antevemus\ASpecification\Specifications\Exceptions\MissingExtensionException With
     *         IgnoreCaseAndAccents when ext-intl is not loaded
     * @throws InvalidArgumentException With IgnoreCaseAndAccents when the literal is not valid UTF-8
     */
    public function __construct(
        private readonly string $literal,
        bool|TextComparison $caseSensitive = true
    ) {
        $this->comparison = TextComparison::of($caseSensitive);

        if ($this->comparison->ignoresAccents()) {
            $folded = TextComparison::foldCaseAndAccents($literal);
            if ($folded === null) {
                throw new InvalidArgumentException('A text compared ignoring case and accents must be valid UTF-8.');
            }
            parent::__construct($this->buildPattern(preg_quote($folded, '/')) . 'u');

            return;
        }

        // 1.14.0: the case-insensitive form folds Unicode letters ('É' matches 'é'), like
        // equalIgnoreCase() and the wildcard matcher; `i` alone folded ASCII only.
        $modifiers = $this->comparison->isCaseSensitive() ? '' : (mb_check_encoding($literal, 'UTF-8') ? 'iu' : 'i');
        parent::__construct($this->buildPattern(preg_quote($literal, '/')) . $modifiers);
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
     * Whether matching is case-sensitive (false for IgnoreCase and IgnoreCaseAndAccents).
     *
     * @return bool
     */
    public function isCaseSensitive(): bool
    {
        return $this->comparison->isCaseSensitive();
    }

    /**
     * The comparison the specification was built with (2.1.0).
     *
     * @return TextComparison
     */
    public function getComparison(): TextComparison
    {
        return $this->comparison;
    }

    /**
     * Whether matching ignores accents (TextComparison::IgnoreCaseAndAccents, 2.1.0).
     *
     * @return bool
     */
    public function ignoresAccents(): bool
    {
        return $this->comparison->ignoresAccents();
    }

    /**
     * {@inheritdoc}
     *
     * With IgnoreCaseAndAccents the candidate is folded like the literal before the match; a
     * candidate that is not valid UTF-8 has no folding and is not satisfied.
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!$this->comparison->ignoresAccents()) {
            return parent::isSatisfiedBy($candidate);
        }

        if (!is_string($candidate)) {
            return false;
        }

        $folded = TextComparison::foldCaseAndAccents($candidate);

        return $folded !== null && preg_match($this->getPattern(), $folded) === 1;
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

    /**
     * {@inheritdoc}
     *
     * Serialized as `{"t": "contains", "v": "<literal>"}` (2.4.0, forward 041; `startsWith` and `endsWith`
     * alike), with `"cmp"` (the value of the TextComparison) when it is not case-sensitive.
     */
    public function toArray(): array
    {
        $data = ['v' => $this->literal];
        if ($this->comparison !== TextComparison::CaseSensitive) {
            $data['cmp'] = $this->comparison->value;
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public static function fromArray(array $data, SpecificationCodec $codec): static
    {
        $codec->expectKeys($data, ['v'], ['cmp']);

        return new static($codec->string($data, 'v'), $codec->comparison($data, 'cmp', TextComparison::CaseSensitive));
    }
}
