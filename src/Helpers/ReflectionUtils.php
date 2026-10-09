<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Helpers;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;
use UnitEnum;

/**
 * ReflectionUtils - Reflection Helpers Shared by Specifications, Repositories and Factories
 *
 * PHP counterpart of net.sourceforge.domian.util.ReflectionUtils (Domian, Copyright 2006-2010 the
 * original author or authors, Apache License 2.0; see THIRD_PARTY_NOTICES.md). Only the members that
 * make sense without JVM generics and primitive boxing are ported:
 *
 * - getFieldByName() / getMethodByName() walk the class hierarchy and find PRIVATE and protected
 *   members declared in superclasses, exactly as the Java original does; this is what lets a
 *   specification be bound to a private field without a getter (PropertyAccessor, last resolution step).
 * - getMethodByNameWithPossiblePrefix() tries the bare name and then each prefix ("get", "is" by default).
 * - invokeMethod() resolves by name and parameter count and returns null instead of throwing when the
 *   method is missing or fails (the Java original swallows IllegalArgument/IllegalAccess/InvocationTarget).
 * - getAllAccessibleObjectsFrom() maps the DECLARED fields and methods of an object by name.
 * - canCastFromTo() / canCastAtLeastOneWay() decide assignability between type names (class, interface,
 *   scalar aliases); the Java primitive-boxing table is replaced by the PHP scalar families.
 * - cloneOrDeepCopyIfNotImmutable() / replicate() copy a value unless it is immutable (scalars, enums,
 *   DateTimeImmutable, closures) or an entity (returned as is unless $doCopyEntities), recursing into
 *   arrays and object properties with cycle detection and the same depth threshold as the Java original
 *   (deeper than the threshold yields null).
 * - isEntity() reports whether the object is a domain entity (IEntity).
 *
 * Not ported (JVM-only): getClass(Type), getTypeArguments(), isDate(), isDecimalNumber(), and the three
 * mutable static flags DO_COPY_OBJECTS / DO_COPY_ENTITIES / RECURSIVE_COPYING_DEPTH_TRESHOLD, which are
 * parameters of the copy methods here instead of process-wide state.
 *
 * @version    1.4.4
 * @package    Antevemus\ASpecification
 * @subpackage Helpers
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class ReflectionUtils
{
    /** Default depth threshold of the recursive copy (Java RECURSIVE_COPYING_DEPTH_TRESHOLD). */
    public const DEFAULT_RECURSIVE_COPYING_DEPTH_THRESHOLD = 5;

    /** Prefixes tried by getMethodByNameWithPossiblePrefix() when none are given (Java ParameterizedSpecification). */
    public const DEFAULT_METHOD_PREFIXES = ['get', 'is'];

    /** @var array<string, array<string, true>> Scalar families: a value of the key type is accepted where the listed types are expected. */
    private const SCALAR_WIDENING = [
        'int' => ['int' => true, 'float' => true, 'mixed' => true],
        'float' => ['float' => true, 'mixed' => true],
        'string' => ['string' => true, 'mixed' => true],
        'bool' => ['bool' => true, 'mixed' => true],
        'array' => ['array' => true, 'iterable' => true, 'mixed' => true],
        'null' => ['null' => true, 'mixed' => true],
        'object' => ['object' => true, 'mixed' => true],
        'iterable' => ['iterable' => true, 'mixed' => true],
        'callable' => ['callable' => true, 'mixed' => true],
        'mixed' => ['mixed' => true],
    ];

    /**
     * Static utility class.
     */
    private function __construct()
    {
    }

    ///////////////////////////////////////////////////////////////////////////
    // Fields
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Finds a property by name, walking up the class hierarchy so that private properties declared in
     * superclasses are found too (Java getFieldByName()).
     *
     * @param string $fieldName Property name
     * @param object|string $declaringType Object or class name to start from
     * @return ReflectionProperty|null The property, or null when no class in the hierarchy declares it
     * @throws InvalidArgumentException When the name is empty or the type is not a class
     */
    public static function getFieldByName(string $fieldName, object|string $declaringType): ?ReflectionProperty
    {
        if ($fieldName === '') {
            throw new InvalidArgumentException('Field name parameter cannot be null');
        }

        $class = self::reflectionClassOf($declaringType, 'Type parameter cannot be null');

        while ($class !== false) {
            if ($class->hasProperty($fieldName)) {
                $property = $class->getProperty($fieldName);
                // ReflectionClass::hasProperty() also answers for inherited non-private properties;
                // the declaring class is what the Java original returns.
                if ($property->getDeclaringClass()->getName() === $class->getName()) {
                    return $property;
                }
            }
            $class = $class->getParentClass();
        }

        return null;
    }

    /**
     * Tells whether the object (or class) declares the property anywhere in its hierarchy, private included.
     *
     * @param object|string $declaringType
     * @param string $fieldName
     * @return bool
     */
    public static function hasField(object|string $declaringType, string $fieldName): bool
    {
        try {
            return self::getFieldByName($fieldName, $declaringType) !== null;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Reads a property, private or protected included, walking up the hierarchy.
     *
     * @param object $object
     * @param string $fieldName
     * @return mixed The value, or null when the property does not exist or is not initialized
     */
    public static function getFieldValue(object $object, string $fieldName): mixed
    {
        $property = self::getFieldByName($fieldName, $object);
        if ($property === null) {
            return null;
        }

        if ($property->isStatic()) {
            return $property->getValue();
        }

        return $property->isInitialized($object) ? $property->getValue($object) : null;
    }

    /**
     * Writes a property, private or protected included, walking up the hierarchy
     * (Java SpecificationUtils.updateEntityState() sets the Field directly).
     *
     * @param object $object
     * @param string $fieldName
     * @param mixed $value
     * @return void
     * @throws InvalidArgumentException When no class in the hierarchy declares the property, or it cannot be written (readonly)
     */
    public static function setFieldValue(object $object, string $fieldName, mixed $value): void
    {
        $property = self::getFieldByName($fieldName, $object);
        if ($property === null) {
            throw new InvalidArgumentException(sprintf(
                'Property "%s" not found on "%s" or any of its superclasses',
                $fieldName,
                get_class($object)
            ));
        }

        try {
            if ($property->isStatic()) {
                $property->setValue(null, $value);
            } else {
                $property->setValue($object, $value);
            }
        } catch (Throwable $e) {
            throw new InvalidArgumentException(sprintf(
                'Property "%s" of "%s" cannot be updated: %s',
                $fieldName,
                get_class($object),
                $e->getMessage()
            ), 0, $e);
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // Methods
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Finds a method by name (and, optionally, number of parameters), walking up the class hierarchy
     * so that private methods declared in superclasses are found too (Java getMethodByName()).
     * PHP has no overloading, so the Java parameter-type array becomes a parameter count.
     *
     * @param string $methodName
     * @param object|string $declaringType
     * @param int|null $parameterCount Exact number of declared parameters required, or null for any
     * @return ReflectionMethod|null
     * @throws InvalidArgumentException When the name is empty or the type is not a class
     */
    public static function getMethodByName(string $methodName, object|string $declaringType, ?int $parameterCount = null): ?ReflectionMethod
    {
        if ($methodName === '') {
            throw new InvalidArgumentException('Method name parameter cannot be null');
        }

        $class = self::reflectionClassOf($declaringType, 'Type parameter cannot be null');

        while ($class !== false) {
            if ($class->hasMethod($methodName)) {
                $method = $class->getMethod($methodName);
                if ($parameterCount === null || $method->getNumberOfParameters() === $parameterCount) {
                    return $method;
                }
            }
            $class = $class->getParentClass();
        }

        return null;
    }

    /**
     * Tries the bare method name first, then prefix + Capitalized name for each prefix
     * (Java getMethodByNameWithPossiblePrefix(); ParameterizedSpecification uses ["get", "is"]).
     *
     * @param string $methodName
     * @param object|string $declaringType
     * @param array<int, string> $possibleMethodPrefixes
     * @return ReflectionMethod|null
     */
    public static function getMethodByNameWithPossiblePrefix(
        string $methodName,
        object|string $declaringType,
        array $possibleMethodPrefixes = self::DEFAULT_METHOD_PREFIXES
    ): ?ReflectionMethod {
        $method = self::getMethodByName($methodName, $declaringType);
        foreach ($possibleMethodPrefixes as $prefix) {
            if ($method !== null) {
                break;
            }
            $method = self::getMethodByName($prefix . ucfirst($methodName), $declaringType);
        }
        return $method;
    }

    /**
     * Invokes a method by name with the given parameters, returning null when the method does not exist,
     * cannot take that many parameters, is not accessible, or throws (Java invokeMethod() swallows
     * IllegalArgument/IllegalAccess/InvocationTarget exceptions).
     *
     * @param object $object
     * @param string $methodName
     * @param array<int, mixed> $parameters
     * @return mixed
     */
    public static function invokeMethod(object $object, string $methodName, array $parameters = []): mixed
    {
        try {
            $method = self::getMethodByName($methodName, $object);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($method === null) {
            return null;
        }

        $given = count($parameters);
        if ($given < $method->getNumberOfRequiredParameters()) {
            return null;
        }
        if ($given > $method->getNumberOfParameters() && !$method->isVariadic()) {
            return null;
        }

        try {
            return $method->isStatic()
                ? $method->invokeArgs(null, $parameters)
                : $method->invokeArgs($object, $parameters);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Invokes a method and reports whether it returned boolean true (Java invokeBooleanMethod()).
     *
     * @param object $object
     * @param string $methodName
     * @param array<int, mixed> $parameters
     * @return bool
     */
    public static function invokeBooleanMethod(object $object, string $methodName, array $parameters = []): bool
    {
        return self::invokeMethod($object, $methodName, $parameters) === true;
    }

    /**
     * Maps the fields and methods DECLARED by the object's class (not inherited ones, as in Java
     * getAllAccessibleObjectsFrom()), indexed by name; constructors are left out.
     *
     * @param object $object
     * @return array<string, ReflectionProperty|ReflectionMethod>
     */
    public static function getAllAccessibleObjectsFrom(object $object): array
    {
        $class = new ReflectionClass($object);
        $map = [];

        foreach ($class->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $class->getName()) {
                $map[$property->getName()] = $property;
            }
        }

        foreach ($class->getMethods() as $method) {
            if ($method->isConstructor() || $method->isDestructor()) {
                continue;
            }
            if ($method->getDeclaringClass()->getName() === $class->getName()) {
                $map[$method->getName()] = $method;
            }
        }

        return $map;
    }

    ///////////////////////////////////////////////////////////////////////////
    // Types
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Tells whether a value of type $fromType is accepted where $toType is expected
     * (Java canCastFrom_To(), with the PHP scalar families in place of primitive boxing).
     *
     * Class and interface names follow is_a(); "mixed" accepts everything; int widens to float;
     * null is accepted only by null and mixed; an unknown name only matches itself.
     *
     * @param string $fromType
     * @param string $toType
     * @return bool
     */
    public static function canCastFromTo(string $fromType, string $toType): bool
    {
        $from = self::normalizeTypeName($fromType);
        $to = self::normalizeTypeName($toType);

        if ($from === $to || $to === 'mixed') {
            return true;
        }

        if (isset(self::SCALAR_WIDENING[$from])) {
            return isset(self::SCALAR_WIDENING[$from][$to]);
        }

        if ($to === 'object') {
            return class_exists($from) || interface_exists($from) || enum_exists($from);
        }

        if ((class_exists($from) || interface_exists($from) || enum_exists($from))
            && (class_exists($to) || interface_exists($to) || enum_exists($to))) {
            return is_a($from, $to, true);
        }

        return false;
    }

    /**
     * Tells whether the two types are assignable in at least one direction (Java canCastAtLeastOneWay()).
     *
     * @param string $type1
     * @param string $type2
     * @return bool
     */
    public static function canCastAtLeastOneWay(string $type1, string $type2): bool
    {
        return self::canCastFromTo($type1, $type2) || self::canCastFromTo($type2, $type1);
    }

    /**
     * Tells whether the value is a domain entity. The Java original tests AbstractEntity, the only class
     * guaranteeing consistent equals()/hashCode(); in PHP the IEntity contract itself declares equals().
     *
     * @param mixed $object
     * @return bool
     */
    public static function isEntity(mixed $object): bool
    {
        return $object instanceof IEntity;
    }

    ///////////////////////////////////////////////////////////////////////////
    // Copying
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Alias of cloneOrDeepCopyIfNotImmutable() with the defaults (Java replicate()).
     *
     * @template T
     * @param T $object
     * @return T|null
     */
    public static function replicate(mixed $object): mixed
    {
        return self::cloneOrDeepCopyIfNotImmutable($object);
    }

    /**
     * Returns the value itself when it is immutable (null, scalars, enums, DateTimeImmutable, closures),
     * or an entity (unless $doCopyEntities), and otherwise a deep copy: arrays element by element,
     * objects cloned and their initialized non-static properties copied recursively up the hierarchy.
     * Cycles are resolved to the copy already made; a value nested deeper than $depthThreshold is
     * replaced by null, as in the Java original. An object that cannot be cloned (private __clone,
     * internal classes refusing clone) is returned as is.
     *
     * @template T
     * @param T $object
     * @param bool $doCopyEntities Copy entities too (Java DO_COPY_ENTITIES, default false)
     * @param int $depthThreshold Java RECURSIVE_COPYING_DEPTH_TRESHOLD
     * @return T|null
     */
    public static function cloneOrDeepCopyIfNotImmutable(
        mixed $object,
        bool $doCopyEntities = false,
        int $depthThreshold = self::DEFAULT_RECURSIVE_COPYING_DEPTH_THRESHOLD
    ): mixed {
        $processed = [];
        return self::copyValue($object, $doCopyEntities, $depthThreshold, 0, $processed);
    }

    /**
     * Tells whether the value is treated as immutable by the copy methods.
     *
     * @param mixed $value
     * @return bool
     */
    public static function isImmutableObject(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }
        if ($value instanceof UnitEnum || $value instanceof Closure || $value instanceof DateTimeImmutable) {
            return true;
        }
        if (is_object($value)) {
            $class = new ReflectionClass($value);
            if ($class->isReadOnly() || $class->isEnum()) {
                return true;
            }
            if (method_exists($value, 'isImmutable') && $value->isImmutable() === true) {
                return true;
            }
            if (method_exists($value, 'isValueObject') && $value->isValueObject() === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array{0: object, 1: object}> $processed Pairs (original, copy) for cycle resolution
     */
    private static function copyValue(mixed $value, bool $doCopyEntities, int $depthThreshold, int $depth, array &$processed): mixed
    {
        if (self::isImmutableObject($value)) {
            return $value; // shared at any depth: nothing to copy
        }

        if ($depth > $depthThreshold) {
            return null;
        }

        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $element) {
                $copy[$key] = self::copyValue($element, $doCopyEntities, $depthThreshold, $depth + 1, $processed);
            }
            return $copy;
        }

        if (!is_object($value)) {
            return $value; // resources and the like
        }

        if (!$doCopyEntities && self::isEntity($value)) {
            return $value;
        }

        foreach ($processed as [$original, $copy]) {
            if ($original === $value) {
                return $copy;
            }
        }

        try {
            $copy = clone $value;
        } catch (Throwable) {
            return $value; // Java: WARN and return the same object
        }

        $processed[] = [$value, $copy];

        $class = new ReflectionClass($copy);
        while ($class !== false) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                if (!$property->isInitialized($copy) || $property->isReadOnly()) {
                    continue;
                }
                $copied = self::copyValue($property->getValue($copy), $doCopyEntities, $depthThreshold, $depth + 1, $processed);
                try {
                    $property->setValue($copy, $copied);
                } catch (Throwable) {
                    // The depth threshold yields null, which a non-nullable typed property refuses:
                    // the shallow-cloned value stays (Java fields are nullable, PHP typed ones are not).
                }
            }
            $class = $class->getParentClass();
        }

        return $copy;
    }

    ///////////////////////////////////////////////////////////////////////////
    // Internals
    ///////////////////////////////////////////////////////////////////////////

    /**
     * @param object|string $declaringType
     * @param string $nullMessage
     * @return ReflectionClass<object>
     */
    private static function reflectionClassOf(object|string $declaringType, string $nullMessage): ReflectionClass
    {
        if (is_object($declaringType)) {
            return new ReflectionClass($declaringType);
        }

        if ($declaringType === '') {
            throw new InvalidArgumentException($nullMessage);
        }

        try {
            return new ReflectionClass($declaringType);
        } catch (ReflectionException $e) {
            throw new InvalidArgumentException(sprintf('Type "%s" is not a class', $declaringType), 0, $e);
        }
    }

    private static function normalizeTypeName(string $type): string
    {
        $type = ltrim(trim($type), '\\');
        $lower = strtolower($type);
        return match ($lower) {
            'integer' => 'int',
            'double' => 'float',
            'boolean' => 'bool',
            '' => 'mixed',
            default => isset(self::SCALAR_WIDENING[$lower]) ? $lower : $type,
        };
    }
}
