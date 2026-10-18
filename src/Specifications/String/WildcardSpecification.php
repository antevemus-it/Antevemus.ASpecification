<?php
declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\String;

use Antevemus\ASpecification\AbstractSpecification;
use InvalidArgumentException;

/**
 * WildcardSpecification - Leaf specification for wildcard pattern matching.
 *
 * Uses native PHP `fnmatch` to verify whether the candidate string matches a wildcard pattern (e.g. `*.txt`).
 *
 * Features:
 * - Simple glob wildcard pattern matching via `fnmatch`
 * - Safe rejection of non-string candidates
 * - The comparison as a TextComparison (2.3.0), as LiteralPatternSpecification since 2.1.0: `true`
 *   (default) compares exactly, as before; `false`/`TextComparison::IgnoreCase` lowers pattern and
 *   candidate with `mb_strtolower(..., 'UTF-8')` before `fnmatch()`, the rule of
 *   WildcardExpressionMatcherIgnoreCaseStringSpecification; `TextComparison::IgnoreCaseAndAccents`
 *   folds both with TextComparison::foldCaseAndAccents() (NFD, nonspacing marks removed, NFC, lower
 *   case), so `'*configuracao*'` matches `'Configuração'`. It requires `ext-intl` (building it without
 *   the extension throws MissingExtensionException) and a pattern in valid UTF-8; a candidate that is
 *   not valid UTF-8 is not satisfied. `getPattern()` returns the pattern as written. A visitor that
 *   translates this class must read getComparison() (or ignoresAccents()) first: up to 2.2.0 it was
 *   always case-sensitive.
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    2.3.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\String
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class WildcardSpecification extends AbstractSpecification
{
    private readonly TextComparison $comparison;

    /** The pattern folded by TextComparison::foldCaseAndAccents(), with IgnoreCaseAndAccents only. */
    private readonly ?string $foldedPattern;

    /**
     * Initializes the specification with a wildcard pattern.
     *
     * @param string $pattern Wildcard expression pattern
     * @param bool|TextComparison $caseSensitive `true` (CaseSensitive, default), `false` (IgnoreCase) or a
     *        TextComparison (2.3.0)
     * @throws \Antevemus\ASpecification\Specifications\Exceptions\MissingExtensionException With
     *         IgnoreCaseAndAccents when ext-intl is not loaded
     * @throws InvalidArgumentException With IgnoreCaseAndAccents when the pattern is not valid UTF-8
     */
    public function __construct(private readonly string $pattern, bool|TextComparison $caseSensitive = true)
    {
        $this->comparison = TextComparison::of($caseSensitive);

        $folded = null;
        if ($this->comparison->ignoresAccents()) {
            $folded = TextComparison::foldCaseAndAccents($pattern);
            if ($folded === null) {
                throw new InvalidArgumentException('A text compared ignoring case and accents must be valid UTF-8.');
            }
        }
        $this->foldedPattern = $folded;
    }

    /**
     * Returns the configured wildcard pattern, as written.
     *
     * @return string
     */
    public function getPattern(): string
    {
        return $this->pattern;
    }

    /**
     * The comparison the specification was built with (2.3.0).
     *
     * @return TextComparison
     */
    public function getComparison(): TextComparison
    {
        return $this->comparison;
    }

    /**
     * Whether matching is case-sensitive (false for IgnoreCase and IgnoreCaseAndAccents, 2.3.0).
     *
     * @return bool
     */
    public function isCaseSensitive(): bool
    {
        return $this->comparison->isCaseSensitive();
    }

    /**
     * Whether matching ignores accents (TextComparison::IgnoreCaseAndAccents, 2.3.0).
     *
     * @return bool
     */
    public function ignoresAccents(): bool
    {
        return $this->comparison->ignoresAccents();
    }

    /**
     * Verifies whether the provided candidate satisfies this wildcard rule.
     *
     * @param mixed $candidate Value or object to validate
     * @return bool True if candidate string matches the wildcard pattern
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!is_string($candidate)) {
            return false;
        }

        if ($this->foldedPattern !== null) {
            $folded = TextComparison::foldCaseAndAccents($candidate);

            return $folded !== null && fnmatch($this->foldedPattern, $folded);
        }

        if (!$this->comparison->isCaseSensitive()) {
            // The rule of WildcardExpressionMatcherIgnoreCaseStringSpecification (1.5.0, RN-03)
            return fnmatch(mb_strtolower($this->pattern, 'UTF-8'), mb_strtolower($candidate, 'UTF-8'));
        }

        return fnmatch($this->pattern, $candidate);
    }

    /**
     * Returns the type of candidate validated by this specification.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'string';
    }
}
