<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\String;

use Antevemus\ASpecification\AbstractSpecification;

/**
 * WildcardExpressionMatcherIgnoreCaseStringSpecification - Leaf specification for case-insensitive wildcard matching.
 *
 * Validates whether the candidate string matches a wildcard pattern (e.g. `*.txt`) in a case-insensitive manner.
 *
 * Features:
 * - Case-insensitive glob matching using lowercase conversion and `fnmatch`
 * - Safe rejection of non-string candidates
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    1.1.0
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
        
        return fnmatch(strtolower($this->pattern), strtolower($candidate));
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return 'string';
    }
}
