<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;

/**
 * AndSpecification - Composite specification representing logical conjunction (AND).
 *
 * Satisifed if and only if BOTH operand specifications (left AND right)
 * are satisfied by the candidate.
 *
 * Features:
 * - Short-circuit candidate evaluation
 * - Aggregated failure diagnostics via Notification Pattern
 * - Complete subsumption and set algebra (Domian lemma 1): (A ∧ B) ⊇ X ⇔ A ⊇ X ∧ B ⊇ X;
 *   X ⊇ (A ∧ B) if X ⊇ A ∨ X ⊇ B; (A ∧ B) ⟂ X if A ⟂ X ∨ B ⟂ X
 * - Structural equality: same class and the same unordered pair of operands
 * - Structural tautology/contradiction detection over the flattened chain (1.6.0): A ∧ ¬A,
 *   disjoint operands and absorbing contradictions
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ICompositeSpecification<T>
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class AndSpecification extends AbstractSpecification implements ICompositeSpecification
{
    use SubsumptionAndEqualityTrait;

    /**
     * Initializes the conjunction with left and right specifications.
     *
     * @param ISpecification<T> $left Left-hand side specification
     * @param ISpecification<T> $right Right-hand side specification
     */
    public function __construct(
        private readonly ISpecification $left,
        private readonly ISpecification $right
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * Evaluates rule conjunction aggregating diagnostics from both branches.
     *
     * @param mixed $candidate Object or value to evaluate
     * @return SpecificationResult Consolidated evaluation result
     */
    public function evaluate(mixed $candidate): SpecificationResult
    {
        $leftResult = $this->left->evaluate($candidate);
        $rightResult = $this->right->evaluate($candidate);

        $combined = SpecificationResult::combine($leftResult, $rightResult);

        if (!$combined->isSatisfied && !$combined->isError && ($this->customReason !== null || $this->customCode !== null)) {
            // Annotated conjunction reports exactly ONE failure: its own, carrying message and code.
            // The branch failures are kept as diagnostic causes, never exposed as siblings.
            // An error result is never absorbed by the annotation.
            return SpecificationResult::failure(
                message: $this->customReason ?? "Conjunction (AND) violated.",
                code: $this->customCode,
                ruleName: 'AndSpecification',
                metadata: ['causes' => $combined->failures]
            );
        }

        return $combined;
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed $candidate Object or value to validate
     * @return bool True if both branches are satisfied
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }

        return $this->left->isSatisfiedBy($candidate) && $this->right->isSatisfiedBy($candidate);
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
    public function accept(\Antevemus\ASpecification\Contracts\ISpecificationVisitor $visitor): mixed
    {
        return $visitor->visitComposite($this);
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        return sprintf('(%s AND %s)', $this->getSpecName($this->left), $this->getSpecName($this->right));
    }

    /**
     * Resolves human-readable representation of a specification.
     *
     * @param ISpecification<T> $spec
     * @return string
     */
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
     * Two conjunctions are equal when they hold the same pair of operands, in any order.
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
     *
     * Conjunction (A AND B) is a generalization of X if and only if both A and B generalize X.
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        $other = SpecificationAlgebra::resolve($otherSpecification);

        if ($this === $other || $this->equals($other) || $other instanceof AlwaysFalseSpecification) {
            return true;
        }

        return $this->left->isGeneralizationOf($other)
            && $this->right->isGeneralizationOf($other);
    }

    /**
     * {@inheritdoc}
     *
     * Conjunction (A AND B) is disjoint with X if either operand is disjoint with X.
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($this->checkBaseDisjointness($otherSpecification)) {
            return true;
        }
        $other = SpecificationAlgebra::resolve($otherSpecification);

        return $this->left->isDisjointWith($other)
            || $this->right->isDisjointWith($other);
    }

    /**
     * {@inheritdoc}
     */
    public function isIntersectionOf(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification instanceof AndSpecification) {
            return SpecificationAlgebra::unorderedPairsEqual(
                $this->left,
                $this->right,
                $otherSpecification->getLeftSide(),
                $otherSpecification->getRightSide()
            );
        }

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
     * RN-05 (forward 019): the associative chain is flattened ((a ∧ b) ∧ ¬a → [a, b, ¬a]); it is a
     * contradiction when an operand is a contradiction, two operands are complementary (A ∧ ¬A) or
     * two operands are disjoint (isDisjointWith). O(n²) in the flattened operands.
     */
    public function isContradiction(): bool
    {
        return SpecificationAlgebra::isContradictoryConjunction(SpecificationAlgebra::flattenConjunction($this));
    }

    /**
     * {@inheritdoc}
     *
     * RN-05 (forward 019): a conjunction is a tautology iff every flattened operand is.
     */
    public function isTautology(): bool
    {
        return SpecificationAlgebra::isTautologicalConjunction(SpecificationAlgebra::flattenConjunction($this));
    }
}
