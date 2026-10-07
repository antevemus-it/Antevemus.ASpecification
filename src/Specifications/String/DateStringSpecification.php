<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\String;

use Antevemus\ASpecification\AbstractSpecification;
use DateTimeImmutable;

/**
 * DateStringSpecification - Leaf specification validating that a string is a valid formatted date.
 *
 * Validates whether a candidate string conforms strictly to an expected date format using DateTimeImmutable.
 *
 * Features:
 * - Strict roundtrip format validation (`createFromFormat` and re-formatting check)
 * - Safe handling of non-string and empty string candidates
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    1.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\String
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DateStringSpecification extends AbstractSpecification
{
    /**
     * Initializes the specification with an expected date format.
     *
     * @param string $format Expected format string (e.g. 'Y-m-d')
     */
    public function __construct(private readonly string $format)
    {
    }

    /**
     * Verifies whether the provided string candidate is a valid date matching the expected format.
     *
     * @param mixed $candidate Target string to validate
     * @return bool True if string is a valid date strictly matching format
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!is_string($candidate) || trim($candidate) === '') {
            return false;
        }
        
        $date = DateTimeImmutable::createFromFormat($this->format, $candidate);
        // PHP returns the date when parsing succeeds; we then check that the output round-trips to the original string (strict validation)
        return $date !== false && $date->format($this->format) === $candidate;
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return 'string';
    }
}
