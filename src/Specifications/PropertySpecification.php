<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ADeepPropertyAccess\PropertyAccess;
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
 * - Polymorphic resolution via the shared property accessor (`PropertyAccess`, antevemus/adeep-property-access)
 * - Composition AST traversal
 * - Contradiction inherited from the inner specification, never the tautology (1.6.0)
 * - A null property value is handed to the inner specification (1.11.0): `property('note', isNull())`
 *   is satisfied by a null note, a comparison is not, and an AND/OR/NOT/NOR node rejects it (as it
 *   rejects any null candidate), exactly what the SQL and TCriteria translations answer for a NULL
 *   column. Before 1.11.0 a null value never satisfied, whatever the inner specification. A property
 *   that cannot be resolved is still an evaluation error
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ICompositeSpecification<T>
 * @version    2.0.0
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
            return $this->evaluateNullValue();
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

        // A missing property throws (getPropertyValue()); a null value goes to the inner specification
        return $this->propertySpecification->isSatisfiedBy($this->getPropertyValue($candidate));
    }

    /**
     * Evaluates a null property value (1.11.0): the inner specification decides through
     * isSatisfiedBy(null), the same answer isSatisfiedBy() gives, so evaluate() and isSatisfiedBy()
     * never disagree (AND/OR evaluate() hand a null candidate to their operands, while their
     * isSatisfiedBy() rejects it). A null value that does not satisfy is reported as the property's
     * own failure, with its because()/withCode(), as before 1.11.0: the report does not depend on
     * the shape of the inner tree. An exception is an evaluation error, never a rule failure.
     *
     * @return SpecificationResult
     */
    private function evaluateNullValue(): SpecificationResult
    {
        try {
            $satisfied = $this->propertySpecification->isSatisfiedBy(null);
        } catch (Throwable $e) {
            return SpecificationResult::error(
                exception: $e,
                ruleName: 'PropertySpecification',
                property: $this->propertyName,
                code: $this->customCode
            );
        }

        if ($satisfied) {
            return SpecificationResult::satisfied();
        }

        return SpecificationResult::failure(
            message: $this->customReason ?? sprintf("Property '%s' is null on candidate object.", $this->propertyName),
            code: $this->customCode,
            ruleName: 'PropertySpecification',
            property: $this->propertyName
        );
    }

    /**
     * What the inner specification answers for a null property value: true, false, or null when it
     * throws (1.11.0). The algebra compares these answers, since a null value is no longer refused
     * before the inner specification is asked.
     *
     * @internal Used by the algebra of this class and by SpecificationSimplifier.
     * @param ISpecification<mixed> $specification
     * @return bool|null
     */
    public static function nullValueAnswer(ISpecification $specification): ?bool
    {
        try {
            return $specification->isSatisfiedBy(null);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolves the property value from candidate object or array.
     *
     * Delegates to the shared property accessor (`PropertyAccess`) for polymorphic resolution.
     *
     * @param mixed $candidate
     * @return mixed
     */
    private function getPropertyValue(mixed $candidate): mixed
    {
        if (!PropertyAccess::hasProperty($candidate, $this->propertyName)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Property "%s" not found or not accessible on candidate of type "%s"',
                    $this->propertyName,
                    is_object($candidate) ? get_class($candidate) : gettype($candidate)
                )
            );
        }

        return PropertyAccess::getValue($candidate, $this->propertyName);
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
     *
     * A property restriction generalizes another restriction on the same property when its
     * base (candidate type) and its inner specification both generalize the other's.
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        if ($this->checkBaseGeneralization($otherSpecification)) {
            return true;
        }

        $other = SpecificationAlgebra::resolve($otherSpecification);
        if ($other instanceof self) {
            if ($this->propertyName !== $other->getPropertyName()) {
                return false;
            }
            $baseGeneralizes = $this->baseSpecification->isGeneralizationOf($other->getBaseSpecification())
                || $this->baseSpecification->getType() === $other->getBaseSpecification()->getType();
            // Since 1.11.0 a null value reaches the inner specifications: the other one must not accept
            // a null value this one refuses (the leaf algebra only speaks of non-null values)
            return $baseGeneralizes
                && $this->propertySpecification->isGeneralizationOf($other->getPropertySpecification())
                && $this->acceptsNullWhenever($other->getPropertySpecification());
        }

        return false;
    }

    /**
     * {@inheritdoc}
     *
     * Two restrictions on the same property are disjoint when their inner specifications are;
     * restrictions on unrelated candidate types are disjoint whatever the property.
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($this->checkBaseDisjointness($otherSpecification)) {
            return true;
        }

        $other = SpecificationAlgebra::resolve($otherSpecification);
        if ($other instanceof self) {
            // Types decide only when both sides are bounded by them (2.1.0: a base such as ¬all(Order) has
            // the type Order and accepts customers)
            $thisType = $this->getType();
            if (SpecificationAlgebra::isBoundedByType($this, $thisType) && SpecificationAlgebra::provenTypesDisjoint($thisType, $other)) {
                return true;
            }
            if ($this->propertyName === $other->getPropertyName()) {
                // Since 1.11.0 both may accept a null value, whatever the leaf algebra says of the others
                return $this->propertySpecification->isDisjointWith($other->getPropertySpecification())
                    && !(self::nullValueAnswer($this->propertySpecification) !== false
                        && self::nullValueAnswer($other->getPropertySpecification()) !== false);
            }
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
     * RN-08 (forward 019): a property restriction is a contradiction when its inner specification (or
     * its base) is one and, since 1.11.0, the inner one also refuses a null value. It never inherits a tautology: a candidate that is not an object or an array does
     * not satisfy it, and a missing property is an evaluation error.
     */
    public function isContradiction(): bool
    {
        // A null value reaches the inner specification since 1.11.0: a contradiction for every other
        // value that accepts null (or may throw on it) is not one here
        return ($this->propertySpecification->isContradiction() && self::nullValueAnswer($this->propertySpecification) === false)
            || $this->baseSpecification->isContradiction();
    }

    /**
     * Whether this inner specification accepts a null value whenever the other one does.
     *
     * @param ISpecification<mixed> $other
     */
    private function acceptsNullWhenever(ISpecification $other): bool
    {
        $mine = self::nullValueAnswer($this->propertySpecification);
        $theirs = self::nullValueAnswer($other);

        return $theirs === false || ($mine === true && $theirs === true);
    }
}
