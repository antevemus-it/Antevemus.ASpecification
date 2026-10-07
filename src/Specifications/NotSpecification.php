<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
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
 * - Disjoint verification with the inner specification
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
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
        }

        // A negated specification is disjoint with its own original specification
        if ($this->specification === $otherSpecification) {
            return true;
        }

        return parent::isDisjointWith($otherSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function isIntersectionOf(ISpecification $otherSpecification): bool
    {
        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
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

        return parent::intersectsWith($otherSpecification);
    }
}
