<?php
declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\String;

use Antevemus\ASpecification\AbstractSpecification;

/**
 * WildcardSpecification - Leaf specification for wildcard pattern matching.
 *
 * Uses native PHP `fnmatch` to verify whether the candidate string matches a wildcard pattern (e.g. `*.txt`).
 *
 * Features:
 * - Simple glob wildcard pattern matching via `fnmatch`
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
class WildcardSpecification extends AbstractSpecification
{
    /**
     * Initializes the specification with a wildcard pattern.
     *
     * @param string $pattern Wildcard expression pattern
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
