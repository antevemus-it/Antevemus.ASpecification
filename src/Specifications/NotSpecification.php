<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use ReflectionClass;

/**
 * NotSpecification - Composite specification representing logical negation (NOT).
 *
 * Satisfied if and only if the inner wrapped specification is NOT satisfied by the candidate.
 * Null candidates never satisfy a specification, even when negated.
 *
 * Features:
 * - Unary negation composition
 * - Diagnostic message inversion via Notification Pattern
 * - Negation algebra (Domian JointDenialSpecification): ¬¬A ≡ A; ¬(x < v) ≡ x >= v (comparison
 *   leaves are inverted, De Morgan on their composites); ¬A ⊇ ¬B ⇔ B ⊇ A; ¬A ⊇ X ⇔ X ⟂ A;
 *   ¬A ⟂ X ⇔ A ⊇ X
 * - Structural equality: same class and equal negated specification
 * - Tautology/contradiction (1.6.0): ¬X is a tautology iff X is a contradiction, and vice versa
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
class NotSpecification extends AbstractSpecification implements ICompositeSpecification
{
    use SubsumptionAndEqualityTrait;

    /**
     * Initializes the negation with the target specification to invert.
     *
     * @param ISpecification<T> $specification Specification to invert
     */
    public function __construct(
        private readonly ISpecification $specification
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * Evaluates logical negation, inverting diagnostic satisfaction.
     *
     * @param mixed $candidate Object or value to evaluate
     * @return SpecificationResult Inverted evaluation result
     */
    public function evaluate(mixed $candidate): SpecificationResult
    {
        if ($candidate === null) {
            return SpecificationResult::failure(
                message: "Null candidate is not permitted.",
                code: $this->customCode,
                ruleName: 'NotSpecification'
            );
        }

        $innerResult = $this->specification->evaluate($candidate);
        if ($innerResult->isError) {
            // Evaluation error is not a failed condition: negation must not turn it into approval.
            return $innerResult;
        }
        if (!$innerResult->isSatisfied) {
            return SpecificationResult::satisfied();
        }

        $innerName = (new ReflectionClass($this->specification))->getShortName();
        $message = $this->customReason ?? sprintf("The negated condition '%s' was improperly satisfied.", $innerName);

        return SpecificationResult::failure(
            message: $message,
            code: $this->customCode,
            ruleName: 'NotSpecification'
        );
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed $candidate Object or value to evaluate
     * @return bool True if inner specification is NOT satisfied
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }

        return !$this->specification->isSatisfiedBy($candidate);
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->specification->getType();
    }

    /**
     * {@inheritdoc}
     */
    public function getLeftSide(): ?ISpecification
    {
        return $this->specification;
    }

    /**
     * {@inheritdoc}
     */
    public function getRightSide(): ?ISpecification
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getSpecifications(): array
    {
        return [$this->specification];
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
        return sprintf('NOT(%s)', $this->getSpecName($this->specification));
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
     * Returns the inner wrapped specification being negated.
     *
     * @return ISpecification<T>
     */
    public function getSpecification(): ISpecification
    {
        return $this->specification;
    }

    /**
     * {@inheritdoc}
     *
     * Two negations are equal when they negate equal specifications.
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
            && $this->specification->equals($other->specification);
    }

    /**
     * {@inheritdoc}
     *
     * ¬A ⊇ X: when the negation resolves to a positive form (¬(x < 10) is x >= 10) that form
     * decides; otherwise ¬A ⊇ ¬B ⇔ B ⊇ A, and ¬A ⊇ X ⇔ X ⟂ A.
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        $self = SpecificationAlgebra::resolve($this);
        $other = SpecificationAlgebra::resolve($otherSpecification);

        if (!$self instanceof self) {
            return $self->isGeneralizationOf($other);
        }
        if ($self === $other || $self->equals($other) || $other instanceof AlwaysFalseSpecification) {
            return true;
        }
        if ($other instanceof OrSpecification) {
            return $self->isGeneralizationOf($other->getLeftSide())
                && $self->isGeneralizationOf($other->getRightSide());
        }
        if ($other instanceof self) {
            return $other->getSpecification()->isGeneralizationOf($self->getSpecification());
        }
        if ($other instanceof AndSpecification) {
            if ($self->isGeneralizationOf($other->getLeftSide()) || $self->isGeneralizationOf($other->getRightSide())) {
                return true;
            }
        }

        return $self->getSpecification()->isDisjointWith($other);
    }

    /**
     * {@inheritdoc}
     *
     * ¬A ⟂ X: when the negation resolves to a positive form that form decides; otherwise
     * ¬A ⟂ X ⇔ A ⊇ X. Two irreducible negations are never proven disjoint.
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        $self = SpecificationAlgebra::resolve($this);
        $other = SpecificationAlgebra::resolve($otherSpecification);

        if (!$self instanceof self) {
            return $self->isDisjointWith($other);
        }
        if ($self === $other || $self->equals($other)) {
            return false;
        }
        if ($other instanceof AlwaysFalseSpecification) {
            return true;
        }
        if ($other instanceof self) {
            return false;
        }
        if ($other instanceof AndSpecification) {
            if ($self->isDisjointWith($other->getLeftSide()) || $self->isDisjointWith($other->getRightSide())) {
                return true;
            }
        }
        if ($other instanceof OrSpecification) {
            return $self->isDisjointWith($other->getLeftSide())
                && $self->isDisjointWith($other->getRightSide());
        }

        return $self->getSpecification()->isGeneralizationOf($other);
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
     * RN-04 (forward 019): ¬X is a tautology iff X is a contradiction.
     */
    public function isTautology(): bool
    {
        return $this->specification->isContradiction();
    }

    /**
     * {@inheritdoc}
     *
     * RN-04 (forward 019): ¬X is a contradiction iff X is a tautology.
     */
    public function isContradiction(): bool
    {
        return $this->specification->isTautology();
    }
}
