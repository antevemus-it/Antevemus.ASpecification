<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\IValueBoundSpecification;
use Antevemus\ASpecification\Specifications\SubsumptionAndEqualityTrait;

/**
 * EqualSpecification - Leaf specification for strict equality (`===`).
 *
 * Validates whether the candidate value and type are strictly identical to the expected value,
 * with full support for algebraic subsumption and disjointness (RF-10).
 *
 * Features:
 * - Strict equality validation (`===`)
 * - Disjointness detection against other constants and inequalities (`>`, `<`, `!==`)
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements IValueBoundSpecification<T>
 * @version    1.2.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class EqualSpecification extends AbstractSpecification implements IValueBoundSpecification
{
    use SubsumptionAndEqualityTrait;

    /**
     * @param mixed $value Expected reference value
     */
    public function __construct(
        private readonly mixed $value
    ) {
    }

    /**
     * Returns the reference value bound to this specification.
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
            return $this->value === null;
        }
        TypeCompatibility::assertEquatable($candidate, $this->value, 'EqualSpecification');
        return $candidate === $this->value;
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
        return $this->checkBaseGeneralization($otherSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($this->checkBaseDisjointness($otherSpecification)) {
            return true;
        }
        if ($otherSpecification instanceof self) {
            return $this->value !== $otherSpecification->getValue();
        }
        if ($otherSpecification instanceof NotEqualSpecification) {
            return $this->value === $otherSpecification->getValue();
        }
        if ($otherSpecification instanceof GreaterThanSpecification) {
            $other = $otherSpecification->getValue();
            return TypeCompatibility::isOrderable($this->value, $other) && $this->value <= $other;
        }
        if ($otherSpecification instanceof LessThanSpecification) {
            $other = $otherSpecification->getValue();
            return TypeCompatibility::isOrderable($this->value, $other) && $this->value >= $other;
        }
        return false;
    }
}
