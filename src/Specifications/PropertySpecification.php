<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Helpers\PropertyAccessor;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
use Throwable;

/**
 * PropertySpecification - Composite specification targeting a specific object property or getter.
 *
 * Encapsulates property-level validation on candidates using polymorphic resolution:
 * - Public properties
 * - Getter methods (getPropertyName, propertyName)
 * - Boolean checks (isPropertyName, hasPropertyName)
 * - Nested arrays and dot-notation expressions
 *
 * Example:
 * <code>
 * $spec = $userSpec->where('address', new CitySpecification('São Paulo'));
 * </code>
 *
 * Features:
 * - Attribute-targeted diagnostic failures via Notification Pattern
 * - Polymorphic resolution via PropertyAccessor
 * - Composition AST traversal
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
class PropertySpecification extends AbstractSpecification implements ICompositeSpecification
{
    use SubsumptionAndEqualityTrait;

    /**
     * Initializes the property specification.
     *
     * @param ISpecification<T> $baseSpecification Base specification of the main candidate
     * @param string $propertyName Target property or getter name
     * @param ISpecification<mixed> $propertySpecification Specification applied to the extracted property
     * @throws \InvalidArgumentException If property name is empty
     */
    public function __construct(
        private readonly ISpecification $baseSpecification,
        private readonly string $propertyName,
        private readonly ISpecification $propertySpecification
    ) {
        if (empty($propertyName)) {
            throw new \InvalidArgumentException('Property name cannot be empty');
        }
    }

    /**
     * {@inheritdoc}
     *
     * Evaluates candidate property, tagging failures with property name.
     *
     * @param mixed $candidate Object or value to evaluate
     * @return SpecificationResult Diagnostic result enriched with property name
     */
    public function evaluate(mixed $candidate): SpecificationResult
    {
        if ($candidate === null || (!is_object($candidate) && !is_array($candidate))) {
            return SpecificationResult::failure(
                message: sprintf("Invalid candidate for property inspection of '%s'.", $this->propertyName),
                code: $this->customCode,
                ruleName: 'PropertySpecification',
                property: $this->propertyName
            );
        }

        $baseResult = $this->baseSpecification->evaluate($candidate);
        if (!$baseResult->isSatisfied) {
            return $baseResult;
        }

        try {
            $propertyValue = $this->getPropertyValue($candidate);
        } catch (Throwable $e) {
            // Missing property, throwing accessor or type error: the rule could not be evaluated.
            // This is an evaluation error, never a rule failure (NOT must not approve it).
            return SpecificationResult::error(
                exception: $e,
                ruleName: 'PropertySpecification',
                property: $this->propertyName,
                code: $this->customCode
            );
        }

        if ($propertyValue === null) {
            return SpecificationResult::failure(
                message: $this->customReason ?? sprintf("Property '%s' is null on candidate object.", $this->propertyName),
                code: $this->customCode,
                ruleName: 'PropertySpecification',
                property: $this->propertyName
            );
        }

        $propResult = $this->propertySpecification->evaluate($propertyValue);
        if ($propResult->isSatisfied) {
            return SpecificationResult::satisfied();
        }

        $annotatedFailures = array_map(
            fn(SpecificationFailure $f): SpecificationFailure => $f->property !== null ? $f : $f->withProperty($this->propertyName),
            $propResult->failures
        );

        // Error state of the inner evaluation (e.g. incompatible candidate type) is preserved and never
        // absorbed by because()/withCode(): an error is not a rule failure.
        if ($propResult->isError) {
            return new SpecificationResult(false, $annotatedFailures, true, $propResult->exception);
        }

        if ($this->customReason !== null || $this->customCode !== null) {
            // Annotated property reports exactly ONE failure: its own, carrying message, code and property.
            // The inner leaf failures are kept as diagnostic causes, never exposed as code-less siblings.
            return SpecificationResult::failure(
                message: $this->customReason ?? sprintf("Violation on property '%s'.", $this->propertyName),
                code: $this->customCode,
                ruleName: 'PropertySpecification',
                property: $this->propertyName,
                metadata: ['causes' => $annotatedFailures]
            );
        }

        return new SpecificationResult(false, $annotatedFailures);
    }

    /**
     * {@inheritdoc}
     *
     * @param object|null $candidate Candidate object whose property will be evaluated
     * @return bool True if both candidate and property satisfy specifications
     */
    public function isSatisfiedBy(?object $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }

        if (!$this->baseSpecification->isSatisfiedBy($candidate)) {
            return false;
        }

        $propertyValue = $this->getPropertyValue($candidate);

        if ($propertyValue === null) {
            return false;
        }

        return $this->propertySpecification->isSatisfiedBy($propertyValue);
    }

    /**
     * Resolves the property value from candidate object or array.
     *
     * Delegates to PropertyAccessor for polymorphic resolution.
     *
     * @param mixed $candidate
     * @return mixed
     */
    private function getPropertyValue(mixed $candidate): mixed
    {
        if (!PropertyAccessor::hasProperty($candidate, $this->propertyName)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Property "%s" not found or not accessible on candidate of type "%s"',
                    $this->propertyName,
                    is_object($candidate) ? get_class($candidate) : gettype($candidate)
                )
            );
        }

        return PropertyAccessor::getValue($candidate, $this->propertyName);
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->baseSpecification->getType();
    }

    /**
     * {@inheritdoc}
     */
    public function getLeftSide(): ?ISpecification
    {
        return $this->baseSpecification;
    }

    /**
     * {@inheritdoc}
     */
    public function getRightSide(): ?ISpecification
    {
        return $this->propertySpecification;
    }

    /**
     * {@inheritdoc}
     */
    public function getSpecifications(): array
    {
        return [$this->baseSpecification, $this->propertySpecification];
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        return sprintf(
            '(%s WHERE %s: %s)',
            $this->getSpecName($this->baseSpecification),
            $this->propertyName,
            $this->getSpecName($this->propertySpecification)
        );
    }

    /**
     * Resolves human-readable representation of a specification.
     *
     * @param ISpecification<mixed> $spec
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
     * Returns the target property name being validated.
     *
     * @return string
     */
    public function getPropertyName(): string
    {
        return $this->propertyName;
    }

    /**
     * Returns the property specification.
     *
     * @return ISpecification<mixed>
     */
    public function getPropertySpecification(): ISpecification
    {
        return $this->propertySpecification;
    }

    /**
     * Concise alias for getPropertySpecification().
     *
     * @return ISpecification<mixed>
     */
    public function getInnerSpecification(): ISpecification
    {
        return $this->propertySpecification;
    }

    /**
     * Returns the base specification.
     *
     * @return ISpecification<T>
     */
    public function getBaseSpecification(): ISpecification
    {
        return $this->baseSpecification;
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
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        if ($this->checkBaseGeneralization($otherSpecification)) {
            return true;
        }

        if ($otherSpecification instanceof self) {
            if ($this->propertyName !== $otherSpecification->getPropertyName()) {
                return false;
            }
            $baseGeneralizes = $this->baseSpecification->isGeneralizationOf($otherSpecification->getBaseSpecification())
                || $this->baseSpecification->getType() === $otherSpecification->getBaseSpecification()->getType();
            return $baseGeneralizes
                && $this->propertySpecification->isGeneralizationOf($otherSpecification->getPropertySpecification());
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($this->checkBaseDisjointness($otherSpecification)) {
            return true;
        }

        if ($otherSpecification instanceof self && $this->propertyName === $otherSpecification->getPropertyName()) {
            return $this->propertySpecification->isDisjointWith($otherSpecification->getPropertySpecification());
        }

        return false;
    }
}
