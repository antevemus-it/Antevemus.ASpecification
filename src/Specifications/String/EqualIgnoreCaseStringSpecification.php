<?php
declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\String;

use Antevemus\ASpecification\AbstractSpecification;

/**
 * EqualIgnoreCaseStringSpecification - Leaf specification for case-insensitive string equality.
 *
 * Uses native PHP `strcasecmp` to compare strings regardless of uppercase or lowercase characters.
 *
 * Features:
 * - Case-insensitive string comparison (`strcasecmp`)
 * - Safe handling of non-string candidates
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
class EqualIgnoreCaseStringSpecification extends AbstractSpecification
{
    /**
     * Initializes the specification with the target reference string.
     *
     * @param string $value Target string for comparison
     */
    public function __construct(private readonly string $value)
    {
    }

    /**
     * Returns the target comparison string value.
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Verifies whether the provided candidate satisfies this specification.
     *
     * @param mixed $candidate Target value to validate
     * @return bool True if candidate is a string matching value case-insensitively
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!is_string($candidate)) {
            return false;
        }

        return strcasecmp($candidate, $this->value) === 0;
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
