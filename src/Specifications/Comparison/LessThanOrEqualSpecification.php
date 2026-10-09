<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use DateTimeInterface;

/**
 * LessThanOrEqualSpecification - Leaf specification for less-than-or-equal comparison (`<=`).
 *
 * Named value-bound leaf behind `lessThanOrEqualTo()`, `atMost()`, `beforeOrAt()` and
 * `isBeforeOrAtTheSameTimeAs()`. Being a leaf (instead of the former `x < v OR x = v` composite)
 * gives it the full interval algebra: `x <= 5 ⊇ x < 5`, `x <= 5 ⟂ x > 5`, `¬(x <= 5) ≡ x > 5`.
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
class LessThanOrEqualSpecification extends AbstractComparableValueBoundSpecification
{
    /**
     * @param int|float|string|DateTimeInterface $value Inclusive upper bound threshold
     */
    public function __construct(int|float|string|DateTimeInterface $value)
    {
        parent::__construct($value);
    }

    /**
     * Returns the configured inclusive upper bound.
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
        return RelationalOperator::LESS_THAN_OR_EQUAL;
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }
        TypeCompatibility::assertOrderable($candidate, $this->value, 'LessThanOrEqualSpecification');
        return $candidate <= $this->value;
    }
}
