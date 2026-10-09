<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Logical;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;
use Antevemus\ASpecification\Specifications\SubsumptionAndEqualityTrait;

/**
 * AlwaysFalseSpecification - Contradictory leaf specification (never satisfied).
 *
 * Represents the empty set of candidate satisfaction. Acts as a special case
 * of all specifications and is universally disjoint with all specifications.
 *
 * Features:
 * - Constant `false` evaluation for any candidate
 * - Universal disjointness in relation to all specifications (RF-10)
 * - Generalizes only the empty set itself (and whatever resolves to it, e.g. not(alwaysTrue()))
 * - Structurally a contradiction (isContradiction() is true, 1.6.0)
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
class AlwaysFalseSpecification extends AbstractSpecification implements ILeafSpecification
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
        return false;
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
        return SpecificationAlgebra::resolve($otherSpecification) instanceof self;
    }

    /**
     * {@inheritdoc}
     */
    public function isSpecialCaseOf(ISpecification $otherSpecification): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * RN-03 (forward 019): the empty specification is the contradiction.
     */
    public function isContradiction(): bool
    {
        return true;
    }
}
