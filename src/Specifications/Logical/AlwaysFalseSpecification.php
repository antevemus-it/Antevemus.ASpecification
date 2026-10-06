<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Logical;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
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
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Logical
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class AlwaysFalseSpecification extends AbstractSpecification
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
        return $otherSpecification instanceof self;
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
}
