<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Results\SpecificationResult;

/**
 * OrSpecification - Composite specification representing logical disjunction (OR).
 *
 * Satisfied if AT LEAST ONE of the operand specifications (left OR right)
 * is satisfied by the candidate.
 *
 * Features:
 * - Short-circuit candidate evaluation
 * - Notification pattern diagnostics when both branches fail
 * - Complete subsumption and set algebra (Domian lemma 1): (A ∨ B) ⊇ X if A ⊇ X ∨ B ⊇ X;
 *   X ⊇ (A ∨ B) ⇔ X ⊇ A ∧ X ⊇ B; (A ∨ B) ⟂ X ⇔ A ⟂ X ∧ B ⟂ X
 * - Structural equality: same class and the same unordered pair of operands
 * - Structural tautology/contradiction detection over the flattened chain (1.6.0): A ∨ ¬A and
 *   absorbing tautologies
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
class OrSpecification extends AbstractSpecification implements ICompositeSpecification
{
    use SubsumptionAndEqualityTrait;

    /**
     * Initializes the disjunction with left and right specifications.
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
     * Evaluates the disjunction returning satisfied if either alternative is met.
     *
     * @param mixed $candidate Object or value to evaluate
     * @return SpecificationResult Consolidated evaluation result
     */
    public function evaluate(mixed $candidate): SpecificationResult
    {
        $leftResult = $this->left->evaluate($candidate);
        if ($leftResult->isSatisfied) {
            return $leftResult;
        }
        if ($leftResult->isError) {
            // Mirrors isSatisfiedBy(): an exception on the left branch aborts the whole disjunction.
            return $leftResult;
        }

        $rightResult = $this->right->evaluate($candidate);
        if ($rightResult->isSatisfied) {
            return $rightResult;
        }
        if ($rightResult->isError) {
            return $rightResult;
        }

        $combined = SpecificationResult::combine($leftResult, $rightResult);

        if ($this->customReason !== null || $this->customCode !== null) {
            // Annotated disjunction reports exactly ONE failure: its own, carrying message and code.
            // The alternatives' failures are kept as diagnostic causes, never exposed as siblings.
            return SpecificationResult::failure(
                message: $this->customReason ?? "None of the alternatives in the disjunction (OR) were satisfied.",
                code: $this->customCode,
                ruleName: 'OrSpecification',
                metadata: ['causes' => $combined->failures]
            );
        }

        return $combined;
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed $candidate Object or value to evaluate
     * @return bool True if at least one branch is satisfied
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }

        return $this->left->isSatisfiedBy($candidate) || $this->right->isSatisfiedBy($candidate);
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
        return sprintf('(%s OR %s)', $this->getSpecName($this->left), $this->getSpecName($this->right));
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
     * Two disjunctions are equal when they hold the same pair of operands, in any order.
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
     * A disjunction (A OR B) generalizes X when either alternative generalizes X (X ⊆ A ⇒ X ⊆ A ∪ B).
     * When X is itself a disjunction, X ⊆ (A ∨ B) iff both alternatives of X are generalized.
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        $other = SpecificationAlgebra::resolve($otherSpecification);

        if ($this === $other || $this->equals($other)) {
            return true;
        }
        if ($other instanceof OrSpecification) {
            return $this->isGeneralizationOf($other->getLeftSide())
                && $this->isGeneralizationOf($other->getRightSide());
        }
        if ($this->checkBaseGeneralization($other)) {
            return true;
        }

        return $this->left->isGeneralizationOf($other)
            || $this->right->isGeneralizationOf($other);
    }

    /**
     * {@inheritdoc}
     *
     * A disjunction is disjoint with X if and only if both alternatives are.
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        $other = SpecificationAlgebra::resolve($otherSpecification);

        if ($this === $other || $this->equals($other)) {
            return false;
        }

        return $this->left->isDisjointWith($other)
            && $this->right->isDisjointWith($other);
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
     * Partial satisfaction of disjunctive (OR) specifications is not supported.
     *
     * @param object $candidate Target candidate object
     * @return ICompositeSpecification|null
     * @throws \InvalidArgumentException Always thrown for disjunctive specifications
     */
    public function remainderUnsatisfiedBy(object $candidate): ?ICompositeSpecification
    {
        throw new \InvalidArgumentException('Partial satisfaction of disjunctive specifications is not supported');
    }

    /**
     * {@inheritdoc}
     *
     * RN-06 (forward 019): the associative chain is flattened; it is a tautology when an operand is
     * a tautology or two operands are complementary (A ∨ ¬A). O(n²) in the flattened operands.
     */
    public function isTautology(): bool
    {
        return SpecificationAlgebra::isTautologicalDisjunction(SpecificationAlgebra::flattenDisjunction($this));
    }

    /**
     * {@inheritdoc}
     *
     * RN-06 (forward 019): a disjunction is a contradiction iff every flattened operand is.
     */
    public function isContradiction(): bool
    {
        return SpecificationAlgebra::isContradictoryDisjunction(SpecificationAlgebra::flattenDisjunction($this));
    }
}
