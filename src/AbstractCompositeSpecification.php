<?php

declare(strict_types=1);

/**
 * AbstractCompositeSpecification - Abstract composite specification implementation
 *
 * Base abstract class defining the composite specification structure, consisting of:
 * - Candidate type definition (parameterized as T)
 * - Encapsulated specification graph (SplObjectStorage preserving uniqueness)
 * - Logical conjunction/disjunction algebra across child specifications
 *
 * Composite specifications can hold both leaf and nested composite specifications,
 * forming an evaluable specification tree with subsumption and remainder tracking.
 *
 * Features:
 * - O(1) duplicate prevention via SplObjectStorage
 * - Reflection and getter based property access
 * - Remainder calculation and disjunction checking
 * - Fluent composition operators
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ICompositeSpecification<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Core
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */

namespace Antevemus\ASpecification;

use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;

abstract class AbstractCompositeSpecification extends AbstractSpecification implements ICompositeSpecification
{
    /**
     * Target type of this composite specification.
     *
     * @var string FQCN or interface name
     */
    protected string $type;

    /**
     * Set of encapsulated specifications.
     *
     * Uses SplObjectStorage internally to ensure uniqueness with O(1) lookup.
     *
     * @var \SplObjectStorage Set of child specifications
     */
    protected \SplObjectStorage $specifications;

    /**
     * Indicates whether this composite specification has been finalized as immutable.
     *
     * @var bool
     */
    protected bool $finalized = false;

    /**
     * Initializes the composite specification for candidate type T.
     *
     * @param string $type Target candidate type FQCN
     */
    public function __construct(string $type)
    {
        $this->type = $type;
        $this->specifications = new \SplObjectStorage();
    }

    /**
     * Finalizes construction of this composite specification, marking it immutable.
     *
     * @return static Current specification for fluent chaining
     */
    protected function finalizeCreation(): static
    {
        $this->finalized = true;
        return $this;
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
    public function where(string $accessibleObjectName, ISpecification $accessibleObjectSpecification): ICompositeSpecification
    {
        if ($this->specifications->count() > 0) {
            throw new \BadMethodCallException(
                'The "where" clause can only be invoked once in specification expressions'
            );
        }

        return $this->and(new PropertySpecification($this, $accessibleObjectName, $accessibleObjectSpecification));
    }

    /**
     * {@inheritdoc}
     */
    public function andWhere(string $accessibleObjectName, ISpecification $accessibleObjectSpecification): ICompositeSpecification
    {
        if ($this->specifications->count() === 0) {
            throw new \BadMethodCallException(
                'The "where" clause must be invoked before "andWhere"/"orWhere" in parameterized expressions'
            );
        }

        return $this->and(new PropertySpecification($this, $accessibleObjectName, $accessibleObjectSpecification));
    }

    /**
     * {@inheritdoc}
     */
    public function orWhere(string $accessibleObjectName, ISpecification $accessibleObjectSpecification): ICompositeSpecification
    {
        if ($this->specifications->count() === 0) {
            throw new \BadMethodCallException(
                'The "where" clause must be invoked before "andWhere"/"orWhere" in parameterized expressions'
            );
        }

        return $this->or(new PropertySpecification($this, $accessibleObjectName, $accessibleObjectSpecification));
    }

    /**
     * {@inheritdoc}
     */
    public function and(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification
    {
        if (is_string($otherSpecification)) {
            if ($propertySpecification === null) {
                throw new \InvalidArgumentException('Property specification cannot be null when property name is provided.');
            }
            return $this->and(new PropertySpecification($this->resolveRootTypeSpecification(), $otherSpecification, $propertySpecification));
        }

        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
        }

        if (!$this->canCastAtLeastOneWay($this->getType(), $otherSpecification->getType())) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Cannot create conjunction of Specification<%s> and Specification<%s>',
                    $this->getType(),
                    $otherSpecification->getType()
                )
            );
        }

        if ($otherSpecification === $this || $this->equals($otherSpecification)) {
            return $this;
        }

        if ($this->isDisjointWith($otherSpecification)) {
            throw new \InvalidArgumentException(
                'Cannot create conjunction of two disjoint specifications'
            );
        }

        return new AndSpecification($this, $otherSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function or(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification
    {
        if (is_string($otherSpecification)) {
            if ($propertySpecification === null) {
                throw new \InvalidArgumentException('Property specification cannot be null when property name is provided.');
            }
            return $this->or(new PropertySpecification($this->resolveRootTypeSpecification(), $otherSpecification, $propertySpecification));
        }

        if ($otherSpecification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
        }

        if ($otherSpecification === $this || $this->equals($otherSpecification)) {
            return $this;
        }

        return new OrSpecification($this, $otherSpecification);
    }

    /**
     * {@inheritdoc}
     */
    public function remainderUnsatisfiedBy(object $candidate): ?ICompositeSpecification
    {
        if ($candidate === null) {
            throw new \InvalidArgumentException('Candidate object cannot be null');
        }

        if ($this->hasDisjunction()) {
            throw new \InvalidArgumentException(
                'Partial satisfaction of disjunctive specifications is not supported'
            );
        }

        if (!$this->canCastFromTo(get_class($candidate), $this->getType())) {
            return $this;
        }

        if ($this->isSatisfiedBy($candidate)) {
            return null;
        }

        // Only the attached clauses the candidate does not satisfy, never the whole
        // specification again (BUG-20261007-7CUU). Nested disjunctions were excluded above.
        $remainderSpec = null;

        foreach ($this->specifications as $spec) {
            $clause = $spec instanceof ICompositeSpecification
                ? $spec->remainderUnsatisfiedBy($candidate)
                : ($spec->isSatisfiedBy($candidate) ? null : $spec);

            if ($clause === null) {
                continue;
            }

            $remainderSpec = $remainderSpec === null ? $clause : new AndSpecification($remainderSpec, $clause);
        }

        if ($remainderSpec === null) {
            return $this;
        }

        return $remainderSpec instanceof ICompositeSpecification
            ? $remainderSpec
            : new AndSpecification($this, $remainderSpec);
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(?object $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }

        if ($this->type !== null && !$this->canCastFromTo(get_class($candidate), $this->type)) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function isGeneralizationOf(ISpecification $specification): bool
    {
        if ($specification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
        }

        if ($this->equals($specification)) {
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isSpecialCaseOf(ISpecification $specification): bool
    {
        if ($specification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
        }

        return $this->canCastAtLeastOneWay($this->getType(), $specification->getType())
            && $specification->isGeneralizationOf($this);
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
    public function getLeftSide(): ?ISpecification
    {
        $specs = iterator_to_array($this->specifications);
        return count($specs) > 0 ? $specs[0] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getRightSide(): ?ISpecification
    {
        $specs = iterator_to_array($this->specifications);
        return count($specs) > 1 ? $specs[1] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getSpecifications(): array
    {
        return iterator_to_array($this->specifications);
    }

    /**
     * Checks if this specification is a conjunction (AND).
     *
     * @return bool
     */
    protected function hasConjunction(): bool
    {
        return $this instanceof AndSpecification;
    }

    /**
     * Checks if this specification contains a disjunction (OR) in its hierarchy.
     *
     * @return bool
     */
    protected function hasDisjunction(): bool
    {
        if ($this instanceof OrSpecification) {
            return true;
        }

        foreach ($this->specifications as $spec) {
            if ($spec instanceof AbstractCompositeSpecification && $spec->hasDisjunction()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if at least one parameterized specification exists in this composite.
     *
     * @return bool
     */
    protected function hasParameterization(): bool
    {
        foreach ($this->getAllSpecifications() as $spec) {
            if ($spec instanceof PropertySpecification) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if this is a simple composition without property parameterization.
     *
     * @return bool
     */
    protected function isSimpleComposition(): bool
    {
        return !$this->hasParameterization();
    }

    /**
     * Retrieves all specifications recursively.
     *
     * @return array<ISpecification>
     */
    protected function getAllSpecifications(): array
    {
        $allSpecs = [];

        foreach ($this->specifications as $spec) {
            $allSpecs[] = $spec;

            if ($spec instanceof AbstractCompositeSpecification) {
                $allSpecs = array_merge($allSpecs, $spec->getAllSpecifications());
            }
        }

        return $allSpecs;
    }

    /**
     * Retrieves all parameterized specifications in the hierarchy.
     *
     * @return array<PropertySpecification>
     */
    protected function getAllParameterizedSpecifications(): array
    {
        $paramSpecs = [];

        foreach ($this->getAllSpecifications() as $spec) {
            if ($spec instanceof PropertySpecification) {
                $paramSpecs[] = $spec;
            }
        }

        return $paramSpecs;
    }

    /**
     * Retrieves a map of leaf specifications indexed by property name.
     *
     * @return array<string, array<ILeafSpecification>>
     */
    protected function getLeafSpecificationMap(): array
    {
        $map = [];

        foreach ($this->getAllParameterizedSpecifications() as $paramSpec) {
            $propertyName = $paramSpec->getPropertyName();

            if (!isset($map[$propertyName])) {
                $map[$propertyName] = [];
            }

            foreach ($paramSpec->getSpecifications() as $spec) {
                if ($spec instanceof ILeafSpecification && !($spec instanceof PropertySpecification)) {
                    $map[$propertyName][] = $spec;
                }
            }
        }

        return $map;
    }

    /**
     * Retrieves list of accessible property names.
     *
     * @return array<string>
     */
    protected function getAccessibleObjectNameList(): array
    {
        return array_keys($this->getLeafSpecificationMap());
    }

    /**
     * Determines whether this specification specifies all instances of its target type.
     *
     * @return bool
     */
    abstract protected function isSpecifyingAllInstancesOfItsType(): bool;

    /**
     * Wraps this specification into a new composite specification.
     *
     * @param AbstractCompositeSpecification $newSpecification New composite specification container
     * @param ISpecification $specificationToBeWrapped Specification to wrap
     * @return ICompositeSpecification
     */
    protected function wrapWithNewSpecification(
        AbstractCompositeSpecification $newSpecification,
        ISpecification $specificationToBeWrapped
    ): ICompositeSpecification {
        $newSpecification->specifications->attach($specificationToBeWrapped);
        $newSpecification->specifications->attach($this);

        return $newSpecification;
    }

    /**
     * Verifies if two types can be cast in at least one direction.
     *
     * @param string $type1 First type
     * @param string $type2 Second type
     * @return bool
     */
    protected function canCastAtLeastOneWay(string $type1, string $type2): bool
    {
        return $this->canCastFromTo($type1, $type2) || $this->canCastFromTo($type2, $type1);
    }

    /**
     * Verifies if a source type can be cast to a destination type.
     *
     * @param string $fromType Source type
     * @param string $toType Destination type
     * @return bool
     */
    protected function canCastFromTo(string $fromType, string $toType): bool
    {
        if ($fromType === $toType) {
            return true;
        }

        if (!class_exists($fromType) && !interface_exists($fromType)) {
            return false;
        }

        if (!class_exists($toType) && !interface_exists($toType)) {
            return false;
        }

        return is_subclass_of($fromType, $toType);
    }

    /**
     * Extracts property value from an object via reflection or getters.
     *
     * Hybrid resolution order:
     * 1. Public getters (getX, isX, hasX)
     * 2. Public properties
     * 3. Private/protected reflection properties
     *
     * @param object $object Target instance
     * @param string $propertyName Property name
     * @return mixed Extracted value
     * @throws \RuntimeException If property cannot be found or accessed
     */
    protected function getPropertyValue(object $object, string $propertyName): mixed
    {
        $getter = 'get' . ucfirst($propertyName);
        if (method_exists($object, $getter) && is_callable([$object, $getter])) {
            return $object->$getter();
        }

        $isMethod = 'is' . ucfirst($propertyName);
        if (method_exists($object, $isMethod) && is_callable([$object, $isMethod])) {
            return $object->$isMethod();
        }

        $hasMethod = 'has' . ucfirst($propertyName);
        if (method_exists($object, $hasMethod) && is_callable([$object, $hasMethod])) {
            return $object->$hasMethod();
        }

        if (property_exists($object, $propertyName)) {
            $reflection = new \ReflectionProperty($object, $propertyName);
            if ($reflection->isPublic()) {
                return $object->$propertyName;
            }
        }

        try {
            $reflection = new \ReflectionProperty($object, $propertyName);
            $reflection->setAccessible(true);
            return $reflection->getValue($object);
        } catch (\ReflectionException $e) {
            throw new \RuntimeException(
                sprintf(
                    'Property "%s" not found on %s. Attempted: public getter, public property, Reflection.',
                    $propertyName,
                    get_class($object)
                ),
                0,
                $e
            );
        }
    }

    /**
     * Verifies structural equality with another composite specification.
     *
     * @param mixed $other Another object
     * @return bool
     */
    public function equals(mixed $other): bool
    {
        if (!($other instanceof AbstractCompositeSpecification)) {
            return false;
        }

        return $this->type === $other->type
            && $this->specifications === $other->specifications;
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        $specNames = array_map(
            fn($spec) => $spec instanceof ISpecification ?
                (method_exists($spec, '__toString') ? (string)$spec : get_class($spec)) :
                'unknown',
            iterator_to_array($this->specifications)
        );

        // The type specification created by Spec::specify(T) is an anonymous class: print it as
        // Spec<T> instead of the anonymous class name with its file path (BUG-20261007-7CUU).
        $reflection = new \ReflectionClass($this);
        if ($reflection->isAnonymous()) {
            $typeParts = explode('\\', $this->type);
            $name = sprintf('Spec<%s>', end($typeParts));
        } else {
            $name = $reflection->getShortName();
        }

        if ($specNames === []) {
            return $name;
        }

        return sprintf('%s(%s)', $name, implode(', ', $specNames));
    }
}
