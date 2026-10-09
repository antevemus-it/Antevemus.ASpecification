<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use InvalidArgumentException;
use RuntimeException;

/**
 * IRepository - Core Contract for Entity Repositories
 *
 * Core contract defining a repository for storing and querying
 * entity objects (IEntity). All searches and batch deletions
 * are driven by Specification objects.
 *
 * @template T of IEntity
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IRepository
{
    ///////////////////////////////////////////////////////////////////////////
    // Repository Operations
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Counts the number of entities satisfying the given specification.
     *
     * @param ISpecification<T> $specification
     * @return int
     * @throws InvalidArgumentException
     */
    public function countAllEntitiesSpecifiedBy(ISpecification $specification): int;

    /** Alias of countAllEntitiesSpecifiedBy */
    public function countAll(ISpecification $specification): int;

    /** Alias of countAllEntitiesSpecifiedBy */
    public function count(ISpecification $specification): int;

    /**
     * Finds and yields all entities satisfying the specification via
     * lazy iteration (Generator), conserving RAM.
     *
     * Contract (1.5.0, RN-04): every repository of the library returns a real generator that
     * evaluates each entity only when the consumer pulls it, without materializing the result or
     * the storage first (in memory over the map, single file over the loaded document, file per
     * entity over the directory entry by entry, partitions over each node's own generator).
     * ALinqBridge and AbstractRepository::findAsLazyCollection() build lazy collections on it.
     *
     * @param ISpecification<T> $specification
     * @return iterable<T>
     * @throws InvalidArgumentException
     */
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable;

    /** Alias of iterateAllEntitiesSpecifiedBy */
    public function iterateAll(ISpecification $specification): iterable;

    /** Alias of iterateAllEntitiesSpecifiedBy */
    public function iterate(ISpecification $specification): iterable;

    /**
     * Finds and returns all entities satisfying the specification in an array.
     *
     * @param ISpecification<T> $specification
     * @return array<T>
     * @throws InvalidArgumentException
     */
    public function findAllEntitiesSpecifiedBy(ISpecification $specification): array;

    /** Alias of findAllEntitiesSpecifiedBy */
    public function findAll(ISpecification $specification): array;

    /** Alias of findAllEntitiesSpecifiedBy */
    public function find(ISpecification $specification): array;

    /**
     * Finds and returns a single entity satisfying the specification.
     *
     * @param ISpecification<T> $specification
     * @return T|null
     * @throws RuntimeException If more than one entity matches the specification
     * @throws InvalidArgumentException
     */
    public function findSingleEntitySpecifiedBy(ISpecification $specification): ?IEntity;

    /** Alias of findSingleEntitySpecifiedBy */
    public function findSingle(ISpecification $specification): ?IEntity;

    /**
     * Reports whether the given entity is stored in this repository.
     *
     * Membership is decided by identity: an entity whose getEntityId() matches a stored
     * entity is contained; implementations may fall back to IEntity::equals(). A partitioned
     * repository answers for its own node and every descendant partition (BUG-20261007-ORNH).
     *
     * @param T $entity Entity to look for
     * @return bool True when the entity is stored, false otherwise (always false for a NullRepository)
     */
    public function contains(IEntity $entity): bool;

    /**
     * Inserts the given entity into this repository.
     *
     * @param T $entity Entity to store
     */
    public function put(IEntity $entity): void;

    /**
     * Inserts multiple entities into this repository.
     *
     * @param array<T> $collectionOfEntities
     * @throws InvalidArgumentException
     */
    public function putAll(array $collectionOfEntities): void;

    /**
     * Updates an existing entity.
     *
     * @param T $entity Entity to update
     */
    public function update(IEntity $entity): void;

    /**
     * Updates an existing entity, providing a delta specification
     * for optimistic concurrency locking.
     *
     * @param T $entity Entity to update
     * @param ISpecification|null $deltaSpecification Difference specification
     */
    public function updateWithDelta(IEntity $entity, ?ISpecification $deltaSpecification = null): void;

    /**
     * Removes all entities satisfying the given specification.
     *
     * @param ISpecification<T> $specification
     * @return int Number of removed entities
     * @throws InvalidArgumentException
     */
    public function removeAllEntitiesSpecifiedBy(ISpecification $specification): int;

    /** Alias of removeAllEntitiesSpecifiedBy */
    public function removeAll(ISpecification $specification): int;

    /** Alias of removeAllEntitiesSpecifiedBy */
    public function removeBy(ISpecification $specification): int;

    /**
     * Removes the specific entity provided.
     *
     * @param T $entity
     * @return bool True if found and removed, false if not present
     */
    public function remove(IEntity $entity): bool;

    /**
     * Reports whether this repository has native partitioning capability (instance reuse).
     *
     * @return bool
     */
    public function isNativelyPartitioned(): bool;

    /**
     * Reports whether the repository recursively indexes member entities.
     *
     * @return bool
     */
    public function isRecursivelyIndexing(): bool;

    /**
     * Promotes this repository into a directed acyclic graph (DAG) partitioned repository,
     * preserving all semantic classifications (Volatile, Persistent, Format, Fake).
     *
     * @param ISpecification|null $specification Root bounding specification (optional)
     * @return IPartitionRepository
     */
    public function makePartition(?ISpecification $specification = null): IPartitionRepository;
}
