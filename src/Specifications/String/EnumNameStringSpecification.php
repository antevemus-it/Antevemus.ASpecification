<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\String;

use Antevemus\ASpecification\AbstractSpecification;
use InvalidArgumentException;
use ReflectionEnum;

/**
 * EnumNameStringSpecification - Leaf specification validating that a string matches a PHP Enum case name.
 *
 * Validates whether the candidate string corresponds to a defined case name in a native PHP 8.1+ Enum (UnitEnum or BackedEnum).
 *
 * Features:
 * - Enum class existence verification via `enum_exists`
 * - Case resolution via PHP `defined()` constant lookup
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
class EnumNameStringSpecification extends AbstractSpecification
{
    /**
     * Initializes the specification with an Enum class name.
     *
     * @param class-string $enumClass Fully qualified PHP Enum class name
     * @throws InvalidArgumentException If class is not a valid PHP Enum
     */
    public function __construct(private readonly string $enumClass)
    {
        if (!enum_exists($enumClass)) {
            throw new InvalidArgumentException("The provided class is not a valid PHP Enum.");
        }
    }

    /**
     * Verifies whether the candidate string matches one of the valid Enum case names.
     *
     * @param mixed $candidate Target string candidate to validate
     * @return bool True if candidate matches an Enum case name
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!is_string($candidate)) {
            return false;
        }
        
        return defined($this->enumClass . '::' . $candidate);
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return 'string';
    }
}
