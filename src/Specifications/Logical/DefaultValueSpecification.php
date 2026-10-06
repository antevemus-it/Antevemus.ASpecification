<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Logical;

use Antevemus\ASpecification\AbstractSpecification;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * DefaultValueSpecification - Leaf specification validating that the candidate matches the default value of its type.
 *
 * Checks if the candidate corresponds to type-default values:
 * null, false, 0, or empty/blank string.
 *
 * Features:
 * - Empty string and whitespace detection
 * - Zero and boolean false matching
 * - Explicit guard rejecting DateTimeInterface default evaluation
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Logical
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DefaultValueSpecification extends AbstractSpecification
{
    /**
     * Checks if the candidate is considered a default/empty value.
     *
     * @param mixed $candidate Value or object to validate
     * @return bool True if candidate equals default value of its type
     * @throws InvalidArgumentException If candidate is a DateTimeInterface
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if ($candidate === null) {
            return true;
        }
        
        if (is_string($candidate)) {
            return trim($candidate) === '';
        }
        
        if (is_numeric($candidate)) {
            return $candidate == 0;
        }
        
        if (is_bool($candidate)) {
            return $candidate === false;
        }
        
        if ($candidate instanceof DateTimeInterface) {
            throw new InvalidArgumentException("Cannot check for default value of a DateTime object as it is a timestamp");
        }
        
        return empty($candidate);
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return 'mixed';
    }
}
