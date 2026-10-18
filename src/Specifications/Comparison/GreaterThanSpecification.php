<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use DateTimeInterface;

/**
 * GreaterThanSpecification - Leaf specification for strictly greater than comparison (`>`).
 *
 * Validates whether the candidate is strictly greater than the configured threshold (number,
 * string or date-time instant), providing interval subsumption ($x > 10 \supseteq x > 50$)
 * and disjointness ($x > 10 \perp x <= 10$; integer bounds are not closed since 2.1.0).
 *
 * Features:
 * - Strict magnitude comparison (`>`) on numbers, strings and DateTimeInterface
 * - Subsumption of narrower intervals and superior equalities (RF-10)
 * - Negation resolved to `<=` by the algebra
 *
 * @template T
 * @extends AbstractComparableValueBoundSpecification<T>
 * @version    2.2.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class GreaterThanSpecification extends AbstractComparableValueBoundSpecification
{
    /**
     * @param int|float|string|DateTimeInterface $value Strict lower bound threshold
     */
    public function __construct(int|float|string|DateTimeInterface $value)
    {
        TypeCompatibility::assertOrderableBound($value, 'GreaterThanSpecification');
        parent::__construct($value);
    }

    /**
     * Returns the configured lower bound threshold.
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
        return RelationalOperator::GREATER_THAN;
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }
        TypeCompatibility::assertOrderable($candidate, $this->value, 'GreaterThanSpecification');
        return $candidate > $this->value;
    }
}
