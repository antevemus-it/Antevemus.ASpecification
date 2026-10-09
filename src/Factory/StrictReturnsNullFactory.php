<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory;

use Antevemus\ASpecification\Contracts\Factory\IObjectFactory;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\IValueBoundSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * StrictReturnsNullFactory - Object Factory Creating the Object a Specification Describes, or Null
 *
 * Implementation of IObjectFactory with the STRICT / RETURNS_NULL strategy of
 * net.sourceforge.domian.factory.StrictReturnsNullFactory (Domian, Copyright 2006-2010 the original author
 * or authors, Apache License 2.0; see THIRD_PARTY_NOTICES.md). The Java class is a skeleton whose
 * behaviour is fixed by the 18 cases of StrictReturnsNullFactoryTest; this class implements those cases:
 *
 * - null specification -> null;
 * - a value-bound leaf (equalTo(""), equalTo(18), equalTo($date)) -> the bound value itself;
 * - a type-only composite (Spec::specify(T::class)) -> a default instance of T when T can be built
 *   without arguments, otherwise null (Java: a(String.class) -> "", a(Double.class) -> null; a composite
 *   carrying a scalar type name, which Spec::specify() does not build, behaves the same way:
 *   'string' -> '', the others -> null);
 * - a composite with property clauses (Spec::specify(T::class)->where('f1', is('v'))->and('f2', is(42)))
 *   -> an instance of T built from the clause values, STRICTLY through the class's own creation means:
 *   the public constructor whose parameters are matched BY NAME to the clauses (every required
 *   parameter must be covered), else a public static factory method returning T (createInstance(...)
 *   and the like) matched the same way, else the no-argument constructor; the clauses not consumed by
 *   the creation are applied through public setters (setF1()) or public properties. A clause that
 *   cannot be honoured this way, a clause that is not bound to a value, a type that cannot be built,
 *   or a built object that does not satisfy the specification -> null (never a partial object);
 * - an interface type -> InvalidArgumentException "Specification type is an interface, unable to create object".
 *
 * Deviations from the Java test cases: PHP compares equalTo() by identity (===), so the bound value is
 * returned as is, not copied; constructor parameters are matched by name (PHP has them), not by type
 * order; there is no "stringValue" conversion case (Long from "18").
 *
 * @template T
 * @implements IObjectFactory<T>
 * @version    1.4.4
 * @package    Antevemus\ASpecification
 * @subpackage Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class StrictReturnsNullFactory implements IObjectFactory
{
    /** Scalar type names a type-only specification can name; only 'string' has a "default instance". */
    private const SCALAR_TYPES = ['string', 'int', 'integer', 'float', 'double', 'bool', 'boolean', 'array', 'mixed', 'object', 'iterable', 'callable', 'null'];

    /**
     * {@inheritdoc}
     *
     * @param ISpecification|null $specification Null is accepted and yields null (Java testShouldReturnNullForNullSpecifications)
     * @return T|null The object the specification describes, or null when it cannot be created strictly
     * @throws InvalidArgumentException When the specification is bound to an interface
     */
    public function createObjectSpecifiedBy(?ISpecification $specification): mixed
    {
        if ($specification === null) {
            return null;
        }

        // Value-bound leaf: the object IS the bound value (Java equalTo("") -> "", equalTo(18L) -> 18L)
        if ($specification instanceof IValueBoundSpecification && !$specification instanceof PropertySpecification) {
            $value = $specification->getValue();
            return $specification->isSatisfiedBy($value) ? $value : null;
        }

        $type = $this->resolveTargetType($specification);
        if ($type === null) {
            return null;
        }

        if (interface_exists($type)) {
            throw new InvalidArgumentException('Specification type is an interface, unable to create object');
        }

        $clauses = $this->collectClauseValues($specification);
        if ($clauses === null) {
            return null; // a clause not bound to a value cannot be honoured strictly
        }

        if (in_array(strtolower($type), self::SCALAR_TYPES, true)) {
            return $clauses === [] && strtolower($type) === 'string' ? '' : null;
        }

        if (!class_exists($type) && !enum_exists($type)) {
            return null;
        }

        $object = $this->instantiate(new ReflectionClass($type), $clauses);
        if ($object === null) {
            return null;
        }

        try {
            return $specification->isSatisfiedBy($object) ? $object : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function create(?ISpecification $specification): mixed
    {
        return $this->createObjectSpecifiedBy($specification);
    }

    ///////////////////////////////////////////////////////////////////////////
    // Specification analysis
    ///////////////////////////////////////////////////////////////////////////

    /**
     * The class (or scalar type name) the specification is bound to: the type of the first composite or
     * property clause carrying one; null when no node names a type.
     */
    private function resolveTargetType(ISpecification $specification): ?string
    {
        $type = ltrim($specification->getType(), '\\');
        if ($type !== '' && $type !== 'mixed' && $type !== 'object') {
            return $type;
        }

        if ($specification instanceof ICompositeSpecification) {
            foreach ($specification->getSpecifications() as $child) {
                if ($child instanceof ISpecification) {
                    $found = $this->resolveTargetType($child);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Property name => bound value for every property clause of the tree; null when a clause is not
     * bound to a value (nothing to build from) or two clauses bind the same property to different values.
     *
     * @return array<string, mixed>|null
     */
    private function collectClauseValues(ISpecification $specification): ?array
    {
        $values = [];
        foreach ($this->collectPropertyClauses($specification) as $clause) {
            $inner = $clause->getPropertySpecification();
            if (!$inner instanceof IValueBoundSpecification) {
                return null;
            }
            $name = $clause->getPropertyName();
            $value = $inner->getValue();
            if (array_key_exists($name, $values) && $values[$name] !== $value) {
                return null;
            }
            $values[$name] = $value;
        }
        return $values;
    }

    /**
     * @return array<int, PropertySpecification>
     */
    private function collectPropertyClauses(ISpecification $specification): array
    {
        if ($specification instanceof PropertySpecification) {
            return [$specification];
        }

        $clauses = [];
        if ($specification instanceof ICompositeSpecification) {
            foreach ($specification->getSpecifications() as $child) {
                if ($child instanceof ISpecification) {
                    foreach ($this->collectPropertyClauses($child) as $clause) {
                        $clauses[] = $clause;
                    }
                }
            }
        }
        return $clauses;
    }

    ///////////////////////////////////////////////////////////////////////////
    // Creation
    ///////////////////////////////////////////////////////////////////////////

    /**
     * @param ReflectionClass<object> $class
     * @param array<string, mixed> $clauses
     */
    private function instantiate(ReflectionClass $class, array $clauses): ?object
    {
        if ($class->isAbstract() || $class->isInterface() || $class->isEnum()) {
            return null;
        }

        $remaining = $clauses;
        $object = $this->createThroughConstructor($class, $remaining);

        if ($object === null) {
            $remaining = $clauses;
            $object = $this->createThroughStaticFactory($class, $remaining);
        }

        if ($object === null) {
            return null;
        }

        return $this->applyRemainingClauses($object, $remaining) ? $object : null;
    }

    /**
     * Public constructor whose required parameters are all covered by the clauses (matched by name).
     *
     * @param ReflectionClass<object> $class
     * @param array<string, mixed> $remaining Clauses, minus the ones consumed on success
     */
    private function createThroughConstructor(ReflectionClass $class, array &$remaining): ?object
    {
        $constructor = $class->getConstructor();

        if ($constructor === null) {
            return $class->isInstantiable() ? $class->newInstance() : null;
        }

        if (!$constructor->isPublic()) {
            return null;
        }

        $arguments = $this->matchParameters($constructor, $remaining);
        if ($arguments === null) {
            return null;
        }

        try {
            $object = $class->newInstanceArgs($arguments);
        } catch (Throwable) {
            return null;
        }

        foreach (array_keys($arguments) as $name) {
            unset($remaining[$name]);
        }

        return $object;
    }

    /**
     * Public static method returning the class (self/static/its name) whose required parameters are all
     * covered by the clauses (Java createInstance(...)); the one consuming the most clauses wins.
     *
     * @param ReflectionClass<object> $class
     * @param array<string, mixed> $remaining Clauses, minus the ones consumed on success
     */
    private function createThroughStaticFactory(ReflectionClass $class, array &$remaining): ?object
    {
        $best = null;
        $bestArguments = null;

        foreach ($class->getMethods(ReflectionMethod::IS_STATIC | ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isStatic() || !$method->isPublic() || !$this->returnsClass($method, $class)) {
                continue;
            }
            $arguments = $this->matchParameters($method, $remaining);
            if ($arguments === null) {
                continue;
            }
            if ($bestArguments === null || count($arguments) > count($bestArguments)) {
                $best = $method;
                $bestArguments = $arguments;
            }
        }

        if ($best === null || $bestArguments === null) {
            return null;
        }

        try {
            $object = $best->invokeArgs(null, $bestArguments);
        } catch (Throwable) {
            return null;
        }

        if (!is_object($object) || !$class->isInstance($object)) {
            return null;
        }

        foreach (array_keys($bestArguments) as $name) {
            unset($remaining[$name]);
        }

        return $object;
    }

    /**
     * Named arguments for the method: every required parameter must have a clause of the same name;
     * optional parameters take the clause when present. Null when a required parameter is uncovered.
     *
     * @param array<string, mixed> $clauses
     * @return array<string, mixed>|null
     */
    private function matchParameters(ReflectionMethod $method, array $clauses): ?array
    {
        $arguments = [];
        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $clauses)) {
                $arguments[$name] = $clauses[$name];
            } elseif (!$parameter->isOptional()) {
                return null;
            }
        }
        return $arguments;
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private function returnsClass(ReflectionMethod $method, ReflectionClass $class): bool
    {
        $returnType = $method->getReturnType();
        if (!$returnType instanceof ReflectionNamedType) {
            return false;
        }
        $name = ltrim($returnType->getName(), '\\');
        if ($name === 'self' || $name === 'static') {
            return true;
        }
        return (class_exists($name) || interface_exists($name)) && is_a($class->getName(), $name, true);
    }

    /**
     * Applies the clauses the creation did not consume: public setter, else public property.
     * False when a clause has no public way in (strict: no reflection into private state).
     *
     * @param array<string, mixed> $remaining
     */
    private function applyRemainingClauses(object $object, array $remaining): bool
    {
        foreach ($remaining as $name => $value) {
            $setter = 'set' . ucfirst($name);
            if (method_exists($object, $setter) && (new ReflectionMethod($object, $setter))->isPublic()) {
                try {
                    $object->$setter($value);
                } catch (Throwable) {
                    return false;
                }
                continue;
            }

            if (property_exists($object, $name)) {
                $property = new \ReflectionProperty($object, $name);
                if ($property->isPublic() && !$property->isStatic() && !$property->isReadOnly()) {
                    try {
                        $property->setValue($object, $value);
                    } catch (Throwable) {
                        return false;
                    }
                    continue;
                }
            }

            return false;
        }

        return true;
    }
}
