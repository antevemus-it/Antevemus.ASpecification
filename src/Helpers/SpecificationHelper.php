<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Helpers;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\IValueBoundSpecification;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Specifications\SpecificationPredicate;
use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use TypeError;

/**
 * SpecificationHelper - Type-Safety, Identity Extraction, and Introspection Utility
 *
 * Implements auxiliary operations for runtime type-safe specification execution,
 * automatic extraction of DDD identity specifications for domain entities, and
 * high-performance collection filtering utilities.
 *
 * Features:
 * - Graceful type-safe validation (typeSafeIsSatisfiedBy) avoiding fatal TypeErrors
 * - 3-tier decoupled identity extraction (IEntity -> public getters -> Reflection)
 * - Optimized iterable filtering with type-safety checks and key preservation
 * - Idiomatic Closure conversion and structural specification introspection
 *
 * @version    1.4.4
 * @package    Antevemus\ASpecification
 * @subpackage Helpers
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class SpecificationHelper extends AbstractSpecificationHelper
{
    /**
     * {@inheritdoc}
     */
    public function typeSafeIsSatisfiedBy(ISpecification $specification, ?object $candidate): bool
    {
        if ($specification === null) {
            throw new InvalidArgumentException('The provided specification cannot be null.');
        }

        if ($candidate === null) {
            return false;
        }

        $expectedType = $specification->getType();

        // If specified type is empty, mixed, or generic object, validate directly
        if ($expectedType !== '' && $expectedType !== 'mixed' && $expectedType !== 'object') {
            if (!is_a($candidate, $expectedType)) {
                return false;
            }
        }

        try {
            return $specification->isSatisfiedBy($candidate);
        } catch (TypeError) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createUniqueSpecificationFor(object $entity): ISpecification
    {
        if ($entity === null) {
            throw new InvalidArgumentException('Entity for unique specification creation cannot be null.');
        }

        $entityClass = get_class($entity);

        // Tier 1: DDD Contract IEntity::getEntityId()
        if ($entity instanceof IEntity) {
            $id = $entity->getEntityId();
            if ($id !== null && $id !== '') {
                return new UniqueEntitySpecification($entity, $entityClass);
            }
        }

        // Tier 2: Standardized public getters (getEntityId() or getId())
        if (method_exists($entity, 'getEntityId')) {
            $id = $entity->getEntityId();
            if ($id !== null && $id !== '') {
                return new UniqueEntitySpecification(is_object($id) ? $id : (string)$id, $entityClass);
            }
        }

        if (method_exists($entity, 'getId')) {
            $id = $entity->getId();
            if ($id !== null && $id !== '') {
                return new UniqueEntitySpecification(is_object($id) ? $id : (string)$id, $entityClass);
            }
        }

        // Tier 3: Reflection fallback inspecting standard identity properties
        $ref = new ReflectionClass($entity);
        $candidateProps = ['entityId', 'id', 'uuid', 'identifier'];

        while ($ref !== false) {
            foreach ($candidateProps as $propName) {
                if ($ref->hasProperty($propName)) {
                    $prop = $ref->getProperty($propName);
                    if ($prop->isInitialized($entity)) {
                        $val = $prop->getValue($entity);
                        if ($val !== null && $val !== '') {
                            return new UniqueEntitySpecification(is_object($val) ? $val : (string)$val, $entityClass);
                        }
                    }
                }
            }
            $ref = $ref->getParentClass();
        }

        throw new RuntimeException(
            sprintf(
                "Entity of class '%s' does not possess identifiable identity properties " .
                "(IEntity::getEntityId, getId(), or properties \$id, \$entityId, \$uuid).",
                $entityClass
            )
        );
    }

    /**
     * Applies a delta specification to an entity: port of Domian SpecificationUtils.updateEntityState()
     * (domian-core; Copyright 2006-2010 the original author or authors, Apache License 2.0; see
     * THIRD_PARTY_NOTICES.md), the routine behind Repository.update(entity, deltaSpecification).
     *
     * The delta is walked whole (conjunctions, nested composites); every property clause
     * (PropertySpecification) whose inner specification is bound to a value (IValueBoundSpecification:
     * equalTo/is, looselyEqualTo, ...) sets that property on the entity to the bound value, by public
     * setter when one exists, otherwise directly on the property, private or inherited included, as the
     * Java original sets the Field. A property clause whose inner specification is a joint denial
     * (Java "extra support for 'notNull'") sets the property to null. Any other clause carries no value
     * and is ignored, as in Java. A null delta does nothing.
     *
     * @param IEntity $entity The entity to update in place
     * @param ISpecification|null $deltaSpecification Conjunction of property clauses bound to the new values, or null
     * @return void
     * @throws InvalidArgumentException When a property clause is bound to a class the entity is not an
     *                                  instance of, or names a property the entity does not have
     */
    public static function updateEntityState(IEntity $entity, ?ISpecification $deltaSpecification): void
    {
        if ($deltaSpecification === null) {
            return;
        }

        foreach (self::collectPropertyClauses($deltaSpecification) as $clause) {
            $declaringType = $clause->getType();
            if (self::isConcreteTypeName($declaringType) && !$entity instanceof $declaringType) {
                throw new InvalidArgumentException(sprintf(
                    'entity type (%s) must be of same type as parameterized type declaring class (%s)',
                    get_class($entity),
                    $declaringType
                ));
            }

            $inner = $clause->getPropertySpecification();
            if ($inner instanceof IValueBoundSpecification) {
                self::writeProperty($entity, $clause->getPropertyName(), $inner->getValue());
            } elseif ($inner instanceof JointDenialSpecification) {
                self::writeProperty($entity, $clause->getPropertyName(), null);
            }
        }
    }

    /**
     * Every PropertySpecification reachable in the tree, outermost first; a property clause's own
     * children (its base and its inner specification) are not descended into.
     *
     * @param ISpecification $specification
     * @return array<int, PropertySpecification>
     */
    private static function collectPropertyClauses(ISpecification $specification): array
    {
        if ($specification instanceof PropertySpecification) {
            return [$specification];
        }

        $clauses = [];
        if ($specification instanceof ICompositeSpecification) {
            foreach ($specification->getSpecifications() as $child) {
                if ($child instanceof ISpecification) {
                    foreach (self::collectPropertyClauses($child) as $clause) {
                        $clauses[] = $clause;
                    }
                }
            }
        }
        return $clauses;
    }

    private static function isConcreteTypeName(string $type): bool
    {
        return $type !== '' && $type !== 'mixed' && $type !== 'object'
            && (class_exists($type) || interface_exists($type));
    }

    private static function writeProperty(IEntity $entity, string $property, mixed $value): void
    {
        $setter = 'set' . ucfirst($property);
        if (method_exists($entity, $setter) && is_callable([$entity, $setter])) {
            $entity->$setter($value);
            return;
        }

        ReflectionUtils::setFieldValue($entity, $property, $value);
    }

    /**
     * Filter an iterable dataset of elements using a specification.
     *
     * @template T
     * @param iterable<T> $candidates Dataset or array to filter
     * @param ISpecification $specification Filter rule
     * @param bool $preserveKeys If true, preserves original array/iterable keys
     * @param bool $typeSafe If true, performs type-safe verification ignoring incompatible candidate types
     * @return array<T> Elements satisfying the specification
     */
    public function filter(
        iterable $candidates,
        ISpecification $specification,
        bool $preserveKeys = false,
        bool $typeSafe = false
    ): array {
        $result = [];

        foreach ($candidates as $key => $candidate) {
            $satisfied = $typeSafe && is_object($candidate)
                ? $this->typeSafeIsSatisfiedBy($specification, $candidate)
                : $specification->isSatisfiedBy($candidate);

            if ($satisfied) {
                if ($preserveKeys) {
                    $result[$key] = $candidate;
                } else {
                    $result[] = $candidate;
                }
            }
        }

        return $result;
    }

    /**
     * Return a native Closure ready for use in array_filter() derived from a specification.
     *
     * @param ISpecification $specification Target specification
     * @param bool $typeSafe If true, enables type-safety check before evaluation
     * @return Closure(mixed): bool
     */
    public function toPredicate(ISpecification $specification, bool $typeSafe = false): Closure
    {
        if ($typeSafe) {
            return fn(mixed $candidate): bool => is_object($candidate)
                ? $this->typeSafeIsSatisfiedBy($specification, $candidate)
                : false;
        }

        return SpecificationPredicate::from($specification);
    }

    /**
     * Perform structural introspection of a specification, returning a metadata map
     * describing its type, composition status, and properties.
     *
     * @param ISpecification $specification Specification to inspect
     * @return array<string, mixed> Map containing structural metadata
     */
    public function inspect(ISpecification $specification): array
    {
        return [
            'class' => get_class($specification),
            'type' => $specification->getType(),
            'isComposite' => $specification instanceof ICompositeSpecification,
        ];
    }
}
