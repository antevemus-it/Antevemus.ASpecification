<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;

/**
 * AndSpecification - Composite specification representing logical conjunction (AND).
 *
 * Satisifed if and only if BOTH operand specifications (left AND right)
 * are satisfied by the candidate.
 *
 * Features:
 * - Short-circuit candidate evaluation
 * - Aggregated failure diagnostics via Notification Pattern
 * - Complete subsumption and set algebra
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ICompositeSpecification<T>
 * @version    1.1.0
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

        if (!$combined->isSatisfied && ($this->customReason !== null || $this->customCode !== null)) {
            $topFailure = new SpecificationFailure(
                message: $this->customReason ?? "Conjunction (AND) violated.",
                code: $this->customCode,
                ruleName: 'AndSpecification'
            );
            return $combined->withLeadingFailures([$topFailure]);
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
     * Conjunction (A AND B) is a generalization of X if both A and B generalize X.
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
        }

        if ($this->checkBaseGeneralization($otherSpecification)) {
            return true;
        }

        return $this->left->isGeneralizationOf($otherSpecification)
            && $this->right->isGeneralizationOf($otherSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
        }

        return $this->left->isDisjointWith($otherSpecification)
            || $this->right->isDisjointWith($otherSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function isIntersectionOf(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
        }

        if ($otherSpecification instanceof AndSpecification) {
            return ($this->left === $otherSpecification->getLeftSide() && $this->right === $otherSpecification->getRightSide())
                || ($this->left === $otherSpecification->getRightSide() && $this->right === $otherSpecification->getLeftSide());
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function intersectsWith(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
        }

        return $this->left->intersectsWith($otherSpecification)
            && $this->right->intersectsWith($otherSpecification);
    }
}
