<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use DateTimeInterface;

/**
 * GreaterThanOrEqualSpecification - Leaf specification for greater-than-or-equal comparison (`>=`).
 *
 * Named value-bound leaf behind `greaterThanOrEqualTo()`, `atLeast()`, `afterOrAt()` and
 * `isAfterOrAtTheSameTimeAs()`. Being a leaf (instead of the former `x > v OR x = v` composite)
 * gives it the full interval algebra: `x >= 5 ⊇ x > 5`, `x >= 5 ⟂ x < 5`, `¬(x >= 5) ≡ x < 5`.
 *
 * @template T
 * @extends AbstractComparableValueBoundSpecification<T>
 * @version    1.4.4
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class GreaterThanOrEqualSpecification extends AbstractComparableValueBoundSpecification
{
    /**
     * @param int|float|string|DateTimeInterface $value Inclusive lower bound threshold
     */
    public function __construct(int|float|string|DateTimeInterface $value)
    {
        parent::__construct($value);
    }

    /**
     * Returns the configured inclusive lower bound.
     */
    public function getValue(): int|float|string|DateTimeInterface
    {
        return $this->value;
    }

    /**
     * {@inheritdoc}
     */
    public function getRelationalOperator(): RelationalOperator
    {
        return RelationalOperator::GREATER_THAN_OR_EQUAL;
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }
        TypeCompatibility::assertOrderable($candidate, $this->value, 'GreaterThanOrEqualSpecification');
        return $candidate >= $this->value;
    }
}
