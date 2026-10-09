<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Logical;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * DefaultValueSpecification - Leaf specification validating that the candidate matches the default value of its type.
 *
 * Port of the Domian `DefaultValueSpecification<T>`: null, false, 0 and the blank string are
 * default values. Untyped (the default, `defaultValue()`), it accepts the default value of any
 * type; typed (`blankString()` = 'string', `defaultNumber()` = 'number', `defaultValueOfType(T)`),
 * it accepts only null and the default value of that type, and two differently typed instances
 * are disjoint (a blank string is never zero).
 *
 * Features:
 * - Empty string and whitespace detection
 * - Zero and boolean false matching
 * - Optional type restriction ('string', 'number'/'int'/'float', 'bool', 'array')
 * - Explicit guard rejecting DateTimeInterface default evaluation
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ILeafSpecification<T>
 * @version    1.4.4
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Logical
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DefaultValueSpecification extends AbstractSpecification implements ILeafSpecification
{
    /**
     * @param string|null $type Restricting type ('string', 'number', 'int', 'float', 'bool', 'array') or null for any
     */
    public function __construct(
        private readonly ?string $type = null
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if ($candidate === null) {
            return true;
        }
        if ($candidate instanceof DateTimeInterface) {
            throw new InvalidArgumentException("Cannot check for default value of a DateTime object as it is a timestamp");
        }

        return match ($this->normalizedType()) {
            'string' => is_string($candidate) && trim($candidate) === '',
            'number' => (is_int($candidate) || is_float($candidate)) && $candidate == 0,
            'bool' => $candidate === false,
            'array' => $candidate === [],
            default => $this->isAnyDefault($candidate),
        };
    }

    private function isAnyDefault(mixed $candidate): bool
    {
        if (is_string($candidate)) {
            return trim($candidate) === '';
        }
        if (is_numeric($candidate)) {
            return $candidate == 0;
        }
        if (is_bool($candidate)) {
            return $candidate === false;
        }

        return empty($candidate);
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->type ?? 'mixed';
    }

    /**
     * {@inheritdoc}
     *
     * Default values of two different types are disjoint (Domian: blankString ⟂ defaultNumber).
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if (SpecificationAlgebra::baseDisjoint($this, $otherSpecification)) {
            return true;
        }
        $other = SpecificationAlgebra::resolve($otherSpecification);
        if ($other instanceof self) {
            $a = $this->normalizedType();
            $b = $other->normalizedType();
            return $a !== null && $b !== null && $a !== $b;
        }

        return false;
    }

    /**
     * Canonical name of the restricting type, or null when unrestricted.
     */
    private function normalizedType(): ?string
    {
        $type = $this->type === null ? null : strtolower($this->type);

        return match ($type) {
            null, 'mixed', '' => null,
            'string', 'str' => 'string',
            'number', 'numeric', 'int', 'integer', 'float', 'double' => 'number',
            'bool', 'boolean' => 'bool',
            'array' => 'array',
            default => $type,
        };
    }
}
