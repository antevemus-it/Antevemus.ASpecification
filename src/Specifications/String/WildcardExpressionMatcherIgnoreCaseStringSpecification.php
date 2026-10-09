<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\String;

use Antevemus\ASpecification\AbstractSpecification;

/**
 * WildcardExpressionMatcherIgnoreCaseStringSpecification - Leaf specification for case-insensitive wildcard matching.
 *
 * Validates whether the candidate string matches a wildcard pattern (e.g. `*.txt`) in a case-insensitive manner.
 *
 * Pattern and candidate are lowered with `mb_strtolower(..., 'UTF-8')` before `fnmatch()`, so the
 * case of any Unicode letter is ignored (`'ÁGUA*'` matches `'água mineral'`); before 1.5.0 the
 * lowering was the ASCII-only `strtolower()` (breaking change RN-03). `fnmatch()` itself works on
 * bytes: `?` matches one byte, so use `*` around multibyte characters. Requires `ext-mbstring`.
 *
 * Features:
 * - Unicode case-insensitive glob matching (`mb_strtolower` then `fnmatch`)
 * - Safe rejection of non-string candidates
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\String
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class WildcardExpressionMatcherIgnoreCaseStringSpecification extends AbstractSpecification
{
    /**
     * Initializes the specification with an expected wildcard pattern.
     *
     * @param string $pattern Expected wildcard pattern (e.g. `*.txt`)
     */
    public function __construct(private readonly string $pattern)
    {
    }

    /**
     * Returns the configured wildcard pattern.
     *
     * @return string
     */
    public function getPattern(): string
    {
        return $this->pattern;
    }

    /**
     * Verifies whether the string candidate matches the wildcard pattern (case-insensitive).
     *
     * @param mixed $candidate String candidate to validate
     * @return bool True if candidate matches pattern case-insensitively
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!is_string($candidate)) {
            return false;
        }
        
        // Unicode-aware (1.5.0, RN-03): both sides lowered with mbstring before fnmatch().
        return fnmatch(mb_strtolower($this->pattern, 'UTF-8'), mb_strtolower($candidate, 'UTF-8'));
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return 'string';
    }
}
