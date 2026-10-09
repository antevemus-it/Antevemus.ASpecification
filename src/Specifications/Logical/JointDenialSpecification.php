<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Logical;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;
use Antevemus\ASpecification\Specifications\SubsumptionAndEqualityTrait;

/**
 * JointDenialSpecification - Composite specification representing logical NOR (Joint Denial).
 *
 * Satisifed if and only if BOTH operand specifications evaluate to false:
 * candidate satisfies NEITHER left NOR right specification. A null candidate never
 * satisfies it (Domian: a composite of any kind rejects null).
 *
 * Features:
 * - Binary joint denial logic: NOT (left OR right)
 * - Composite operand inspection (left, right, specifications list)
 * - Set algebra by equivalence with ¬(A ∨ B): subsumption, disjointness and structural
 *   equality (same unordered pair of operands) delegate to that form
 * - Tautology/contradiction (1.6.0) derived from ¬(A ∨ B)
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ICompositeSpecification<T>
 * @version    1.6.0
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
        if ($candidate === null) {
            return false;
        }

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
     * The equivalent negated disjunction ¬(A ∨ B) that carries the algebra.
     *
     * @return NotSpecification<T>
     */
    private function asNegation(): NotSpecification
    {
        return new NotSpecification(new OrSpecification($this->left, $this->right));
    }

    /**
     * {@inheritdoc}
     *
     * Two joint denials are equal when they deny the same pair of operands, in any order.
     */
    public function equals(mixed $other): bool
    {
        if ($this === $other) {
            return true;
        }
        if (!$other instanceof self || $other::class !== static::class) {
            return false;
        }

        return $this->customReason === $other->customReason
            && $this->customCode === $other->customCode
            && SpecificationAlgebra::unorderedPairsEqual($this->left, $this->right, $other->left, $other->right);
    }

    /**
     * {@inheritdoc}
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        if ($this === $otherSpecification || $this->equals($otherSpecification)) {
            return true;
        }

        return $this->asNegation()->isGeneralizationOf($otherSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($this === $otherSpecification || $this->equals($otherSpecification)) {
            return false;
        }

        return $this->asNegation()->isDisjointWith($otherSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function isIntersectionOf(ISpecification $otherSpecification): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function intersectsWith(ISpecification $otherSpecification): bool
    {
        return !$this->isDisjointWith($otherSpecification);
    }

    /**
     * {@inheritdoc}
     *
     * RN-07 (forward 019): NOR(a, b) ≡ ¬(a ∨ b), so it is a tautology iff a ∨ b is a contradiction
     * (RN-04 over RN-06).
     */
    public function isTautology(): bool
    {
        return SpecificationAlgebra::isContradictoryDisjunction($this->disjunctionOperands());
    }

    /**
     * {@inheritdoc}
     *
     * RN-07 (forward 019): NOR(a, b) is a contradiction iff a ∨ b is a tautology.
     */
    public function isContradiction(): bool
    {
        return SpecificationAlgebra::isTautologicalDisjunction($this->disjunctionOperands());
    }

    /**
     * The flattened operands of the denied disjunction a ∨ b.
     *
     * @return list<ISpecification<mixed>>
     */
    private function disjunctionOperands(): array
    {
        return array_merge(
            SpecificationAlgebra::flattenDisjunction($this->left),
            SpecificationAlgebra::flattenDisjunction($this->right)
        );
    }
}
