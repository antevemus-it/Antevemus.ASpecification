<?php
declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\String;

use Antevemus\ASpecification\AbstractSpecification;

/**
 * RegexSpecification - Leaf specification for regular expression pattern validation.
 *
 * Uses native PHP `preg_match` to verify whether the candidate string matches the PCRE pattern.
 *
 * Features:
 * - PCRE pattern matching via `preg_match`
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
class RegexSpecification extends AbstractSpecification
{
    /**
     * Initializes the specification with a regex pattern.
     *
     * @param string $pattern PCRE regular expression pattern
     */
    public function __construct(private readonly string $pattern)
    {
    }

    /**
     * Returns the configured regular expression pattern.
     *
     * @return string
     */
    public function getPattern(): string
    {
        return $this->pattern;
    }

    /**
     * Verifies whether the provided candidate satisfies the regex pattern.
     *
     * @param mixed $candidate Value or object to validate
     * @return bool True if candidate matches the regular expression pattern
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!is_string($candidate)) {
            return false;
        }

        return preg_match($this->pattern, $candidate) === 1;
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
