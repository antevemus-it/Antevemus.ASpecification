<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Helpers;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
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
 * @version    1.1.0
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
