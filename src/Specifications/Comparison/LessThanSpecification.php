<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\IValueBoundSpecification;
use Antevemus\ASpecification\Specifications\SubsumptionAndEqualityTrait;

/**
 * LessThanSpecification - Leaf specification for strictly less than comparison (`<`).
 *
 * Validates whether the candidate is strictly less than the configured threshold,
 * providing interval subsumption ($x < 100 \supseteq x < 50$) and disjointness.
 *
 * Features:
 * - Strict magnitude comparison (`<`)
 * - Subsumption of narrower intervals and inferior equalities (RF-10)
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
class LessThanSpecification extends AbstractSpecification implements IValueBoundSpecification
{
    use SubsumptionAndEqualityTrait;

    /**
     * @param int|float|string $value Strict upper bound threshold
     */
    public function __construct(
        private readonly int|float|string $value
    ) {
    }

    /**
     * Returns the configured upper bound threshold.
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
        return $candidate < $this->value;
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
            return $otherSpecification->getValue() <= $this->value;
        }
        if ($otherSpecification instanceof EqualSpecification) {
            return $otherSpecification->getValue() < $this->value;
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
        if ($otherSpecification instanceof GreaterThanSpecification) {
            return $otherSpecification->getValue() >= $this->value;
        }
        if ($otherSpecification instanceof EqualSpecification) {
            return $otherSpecification->getValue() >= $this->value;
        }
        return false;
    }
}
