<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use DateTimeInterface;

/**
 * LessThanSpecification - Leaf specification for strictly less than comparison (`<`).
 *
 * Validates whether the candidate is strictly less than the configured threshold (number,
 * string or date-time instant), providing interval subsumption ($x < 100 \supseteq x < 50$)
 * and disjointness ($x < 10 \perp x >= 10$; for integers $x < 11 \perp x > 10$).
 *
 * Features:
 * - Strict magnitude comparison (`<`) on numbers, strings and DateTimeInterface
 * - Subsumption of narrower intervals and inferior equalities (RF-10)
 * - Negation resolved to `>=` by the algebra
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
class LessThanSpecification extends AbstractComparableValueBoundSpecification
{
    /**
     * @param int|float|string|DateTimeInterface $value Strict upper bound threshold
     */
    public function __construct(int|float|string|DateTimeInterface $value)
    {
        parent::__construct($value);
    }

    /**
     * Returns the configured upper bound threshold.
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
        return RelationalOperator::LESS_THAN;
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }
        TypeCompatibility::assertOrderable($candidate, $this->value, 'LessThanSpecification');
        return $candidate < $this->value;
    }
}
