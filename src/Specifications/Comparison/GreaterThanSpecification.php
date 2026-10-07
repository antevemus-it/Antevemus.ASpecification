<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\IValueBoundSpecification;
use Antevemus\ASpecification\Specifications\SubsumptionAndEqualityTrait;

/**
 * GreaterThanSpecification - Leaf specification for strictly greater than comparison (`>`).
 *
 * Validates whether the candidate is strictly greater than the configured threshold,
 * providing interval subsumption ($x > 10 \supseteq x > 50$) and disjointness.
 *
 * Features:
 * - Strict magnitude comparison (`>`)
 * - Subsumption of narrower intervals and superior equalities (RF-10)
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements IValueBoundSpecification<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class GreaterThanSpecification extends AbstractSpecification implements IValueBoundSpecification
{
    use SubsumptionAndEqualityTrait;

    /**
     * @param int|float|string $value Strict lower bound threshold
     */
    public function __construct(
        private readonly int|float|string $value
    ) {
    }

    /**
     * Returns the configured lower bound threshold.
     */
    public function getValue(): int|float|string
    {
        return $this->value;
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

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return "mixed";
    }

    /**
     * {@inheritdoc}
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        if ($this->checkBaseGeneralization($otherSpecification)) {
            return true;
        }
        if ($otherSpecification instanceof self) {
            $other = $otherSpecification->getValue();
            return TypeCompatibility::isOrderable($other, $this->value) && $other >= $this->value;
        }
        if ($otherSpecification instanceof EqualSpecification) {
            $other = $otherSpecification->getValue();
            return TypeCompatibility::isOrderable($other, $this->value) && $other > $this->value;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($this->checkBaseDisjointness($otherSpecification)) {
            return true;
        }
        if ($otherSpecification instanceof LessThanSpecification || $otherSpecification instanceof EqualSpecification) {
            $other = $otherSpecification->getValue();
            return TypeCompatibility::isOrderable($other, $this->value) && $other <= $this->value;
        }
        return false;
    }
}
