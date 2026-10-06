<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Logical;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\SubsumptionAndEqualityTrait;

/**
 * JointDenialSpecification - Composite specification representing logical NOR (Joint Denial).
 *
 * Satisifed if and only if BOTH operand specifications evaluate to false:
 * candidate satisfies NEITHER left NOR right specification.
 *
 * Features:
 * - Binary joint denial logic: NOT (left OR right)
 * - Composite operand inspection (left, right, specifications list)
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ICompositeSpecification<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Logical
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class JointDenialSpecification extends AbstractSpecification implements ICompositeSpecification
{
    use SubsumptionAndEqualityTrait;

    /**
     * @param ISpecification<T> $left Left-hand side specification
     * @param ISpecification<T> $right Right-hand side specification
     */
    public function __construct(
        private readonly ISpecification $left,
        private readonly ISpecification $right
    ) {
    }

    /**
     * Verifies whether the candidate fails both rules (NOR logic).
     *
     * @param mixed $candidate Object or value to evaluate
     * @return bool True if candidate satisfies neither specification
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return !$this->left->isSatisfiedBy($candidate) && !$this->right->isSatisfiedBy($candidate);
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->left->getType();
    }

    /**
     * {@inheritdoc}
     */
    public function getLeftSide(): ?ISpecification
    {
        return $this->left;
    }

    /**
     * {@inheritdoc}
     */
    public function getRightSide(): ?ISpecification
    {
        return $this->right;
    }

    /**
     * {@inheritdoc}
     */
    public function getSpecifications(): array
    {
        return [$this->left, $this->right];
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        return sprintf('(%s NOR %s)', $this->getSpecName($this->left), $this->getSpecName($this->right));
    }

    private function getSpecName(ISpecification $spec): string
    {
        if ($spec instanceof ICompositeSpecification) {
            return (string) $spec;
        }

        $className = get_class($spec);
        $parts = explode('\\', $className);
        return end($parts);
    }

    /**
     * {@inheritdoc}
     *
     * @param ISpecification $otherSpecification
     * @return bool
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * @param ISpecification $otherSpecification
     * @return bool
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * @param ISpecification $otherSpecification
     * @return bool
     */
    public function isIntersectionOf(ISpecification $otherSpecification): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     *
     * @param ISpecification $otherSpecification
     * @return bool
     */
    public function intersectsWith(ISpecification $otherSpecification): bool
    {
        return false;
    }
}
