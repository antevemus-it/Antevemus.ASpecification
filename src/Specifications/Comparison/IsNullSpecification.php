<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\IValueBoundSpecification;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;

/**
 * IsNullSpecification - Leaf specification validating that the candidate is null.
 *
 * Named leaf behind `isNull()`. It is the logical inverse of NotNullSpecification, so the
 * algebra knows that `isNull ⟂ isNotNull`, `not(isNull) ≡ isNotNull` and that `isNull` is
 * disjoint with every value-bound leaf whose reference value is not null.
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ILeafSpecification<T>
 * @version    1.4.4
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
        $other = SpecificationAlgebra::resolve($otherSpecification);
        if ($other instanceof NotNullSpecification) {
            return true;
        }
        if ($other instanceof IValueBoundSpecification) {
            return $other->getValue() !== null;
        }

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
