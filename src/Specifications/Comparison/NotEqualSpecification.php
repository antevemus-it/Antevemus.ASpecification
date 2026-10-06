<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\IValueBoundSpecification;
use Antevemus\ASpecification\Specifications\SubsumptionAndEqualityTrait;

/**
 * NotEqualSpecification - Leaf specification for strict inequality (`!==`).
 *
 * Validates whether the candidate is strictly different from the parameterized value.
 *
 * Features:
 * - Strict inequality validation (`!==`)
 * - Generalization of equality specifications with distinct values (RF-10)
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
class NotEqualSpecification extends AbstractSpecification implements IValueBoundSpecification
{
    use SubsumptionAndEqualityTrait;

    /**
     * @param mixed $value Forbidden reference value
     */
    public function __construct(
        private readonly mixed $value
    ) {
    }

    /**
     * Returns the bound reference value.
     */
    public function getValue(): mixed
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
        return $candidate !== $this->value;
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
        if ($otherSpecification instanceof EqualSpecification) {
            return $this->value !== $otherSpecification->getValue();
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
        if ($otherSpecification instanceof EqualSpecification) {
            return $this->value === $otherSpecification->getValue();
        }
        return false;
    }
}
