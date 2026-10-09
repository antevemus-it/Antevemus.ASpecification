<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Logical;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;
use Antevemus\ASpecification\Specifications\SubsumptionAndEqualityTrait;

/**
 * AlwaysTrueSpecification - Tautological leaf specification (always satisfied).
 *
 * Represents the universal specification for the configured type, serving as the
 * identity element in conjunctions (AND) and the root of universal subsumption.
 *
 * Features:
 * - Constant `true` evaluation for any candidate
 * - Universal generalization over all type-compatible specifications (RF-10)
 * - Disjoint only with the contradiction (and with whatever resolves to it, e.g. not(alwaysTrue()))
 * - Structurally a tautology (isTautology() is true, 1.6.0)
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ILeafSpecification<T>
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Logical
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class AlwaysTrueSpecification extends AbstractSpecification implements ILeafSpecification
{
    use SubsumptionAndEqualityTrait;

    /**
     * @param class-string<T>|string $type Candidate type designation
     */
    public function __construct(
        private readonly string $type = "mixed"
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->type;
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
        return SpecificationAlgebra::resolve($otherSpecification) instanceof AlwaysFalseSpecification;
    }

    /**
     * {@inheritdoc}
     *
     * RN-03 (forward 019): the universal specification is the tautology.
     */
    public function isTautology(): bool
    {
        return true;
    }
}
