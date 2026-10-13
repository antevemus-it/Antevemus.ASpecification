<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;

/**
 * IsNullSpecification - Leaf specification validating that the candidate is null.
 *
 * Named leaf behind `isNull()`. It is the logical inverse of NotNullSpecification, so the
 * algebra knows that `isNull ⟂ isNotNull`, `not(isNull) ≡ isNotNull` and that `isNull` is
 * disjoint with every specification that never accepts null (SpecificationAlgebra::refusesNull():
 * a value-bound leaf whose reference value is not null, AND/OR/NOT, a property restriction, ...).
 * A subclass that redefines isSatisfiedBy() gets the shared axioms only (2.1.0).
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ILeafSpecification<T>
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class IsNullSpecification extends AbstractSpecification implements ILeafSpecification
{
    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $candidate === null;
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return 'mixed';
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if (SpecificationAlgebra::baseDisjoint($this, $otherSpecification)) {
            return true;
        }
        if (!SpecificationAlgebra::evaluatesAs($this, self::class)) {
            // A subclass that redefines isSatisfiedBy() may accept more than null
            return false;
        }
        // isNull() accepts null only: it is disjoint with every specification that refuses null
        // (2.1.0: up to 2.0.0 the value of any IValueBoundSpecification decided, a user leaf included)
        return SpecificationAlgebra::refusesNull(SpecificationAlgebra::resolve($otherSpecification));

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        return 'IsNullSpecification';
    }
}
