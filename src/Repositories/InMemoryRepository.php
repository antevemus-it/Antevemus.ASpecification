<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ALinq\Interfaces\IALinqCollection;
use Antevemus\ASpecification\Contracts\Concurrent\ISynchronizer;
use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IVolatileRepository;
use Generator;
use InvalidArgumentException;

/**
 * InMemoryRepository - In-Memory Volatile Repository Implementation
 *
 * Volatile repository based on in-memory storage (internal associative map).
 * Queries operate with O(N) linear scan cost; process-local concurrency.
 * Ideal for temporary storage, local transactional caching, or intensive unit test suites.
 *
 * Every operation runs under the repository's ISynchronizer (NullSynchronizer by default; pass one as the
 * trailing constructor parameter or with withSynchronizer()): reads in concurrent mode, writes
 * (put, putAll, update, updateWithDelta, remove, removeAll, clear) in exclusive mode. As in the Java
 * original, the lazy iterator of iterateAllEntitiesSpecifiedBy() is CREATED under the permit; the
 * iteration itself runs outside it, over a snapshot of the storage taken when the generator starts.
 * The snapshot is PHP's copy-on-write of the map: nothing is copied unless the repository is
 * written to while the generator is alive, and each entity is evaluated only when it is pulled
 * (getAll() is never involved, RN-04 of 1.5.0).
 *
 * Features:
 * - High-speed volatile memory storage indexed by hash/ID
 * - Synchronous Specification filtering with lazy iteration (yield) and O(N) counting
 * - Idempotent insertions and removals
 * - O(1) membership test via contains()
 * - Fast O(1) atomic repository clearance via clear()
 * - updateWithDelta() applying the delta specification (property clauses bound to values) before storing
 * - Optional repository identifier, preserved by partitions created with addPartitionWithId()
 * - Optional ISynchronizer (NullSynchronizer by default) wrapping every operation
 * - ALinq fluent collection integration
 *
 * @template T of IEntity
 * @extends AbstractRepository<T>
 * @implements IVolatileRepository<T>
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class InMemoryRepository extends AbstractRepository implements IVolatileRepository
{
    /** @var array<string, T> Primary internal storage map key->entity */
    protected array $db = [];

    /**
     * Constructs an in-memory repository.
     *
     * @param array<T> $initialEntities Initial entity collection to pre-populate repository
     * @param string|null $repositoryId Optional identifier (a volatile repository has none by default);
     *                                  PartitionRepository::addPartitionWithId() passes the partition id here
     * @param ISynchronizer|null $synchronizer Synchronizer wrapping every operation (NullSynchronizer when null)
     */
    public function __construct(
        array $initialEntities = [],
        protected readonly ?string $repositoryId = null,
        ?ISynchronizer $synchronizer = null
    ) {
        if ($synchronizer !== null) {
            $this->setSynchronizer($synchronizer);
        }
        if (!empty($initialEntities)) {
            $this->putAll($initialEntities);
        }
    }

    /**
     * Returns the optional repository identifier (null when none was given).
     *
     * @return string|null
     */
    public function getRepositoryId(): ?string
    {
        return $this->repositoryId;
    }

    /**
     * Reports whether the entity is stored: O(1) by the same key put()/remove() use
     * (scalar id or spl_object_hash), then IEntity::equals() as a fallback.
     *
     * @param IEntity $entity Entity to look for
     * @return bool
     */
    public function contains(IEntity $entity): bool
    {
        return $this->readConcurrently(function () use ($entity): bool {
            if (array_key_exists($this->keyOf($entity), $this->db)) {
                return true;
            }

            foreach ($this->db as $stored) {
                if ($stored->equals($entity)) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Counts how many entities in the repository satisfy the specification.
     *
     * @param ISpecification $specification Filter specification
     * @return int Count of matching entities
     */
    public function countAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        $this->validateSpecification($specification);
        return $this->readConcurrently(function () use ($specification): int {
            $count = 0;
            foreach ($this->db as $entity) {
                if ($specification->isSatisfiedBy($entity)) {
                    $count++;
                }
            }
            return $count;
        });
    }

    /**
     * Yields on-demand (lazy) all entities satisfying the specification.
     *
     * @param ISpecification $specification Filter specification
     * @return iterable<T> Lazy generator of matching entities
     */
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        $this->validateSpecification($specification);
        return $this->readConcurrently(fn(): Generator => $this->iterateMatching($specification));
    }

    /**
     * Finds and returns an array of all entities satisfying the specification.
     *
     * @param ISpecification $specification Filter specification
     * @return array<T> List of matching entities
     */
    public function findAllEntitiesSpecifiedBy(ISpecification $specification): array
    {
        $this->validateSpecification($specification);
        return $this->readConcurrently(function () use ($specification): array {
            $results = [];
            foreach ($this->db as $entity) {
                if ($specification->isSatisfiedBy($entity)) {
                    $results[] = $entity;
                }
            }
            return $results;
        });
    }

    /**
     * Inserts or replaces an entity in memory storage.
     *
     * @param IEntity $entity Entity to store
     * @return void
     */
    public function put(IEntity $entity): void
    {
        $this->writeExclusively(function () use ($entity): void {
            // Uses entity ID (if scalar/string) or spl_object_hash as storage key
            $this->db[$this->keyOf($entity)] = $entity;
        });
    }

    /**
     * Inserts a collection of entities into the repository.
     *
     * @param array<IEntity> $collectionOfEntities Collection of entities
     * @return void
     * @throws InvalidArgumentException If any item does not implement IEntity
     */
    public function putAll(array $collectionOfEntities): void
    {
        $this->writeExclusively(function () use ($collectionOfEntities): void {
            foreach ($collectionOfEntities as $entity) {
                if (!$entity instanceof IEntity) {
                    throw new InvalidArgumentException("All items must implement IEntity.");
                }
                $this->put($entity);
            }
        });
    }

    /**
     * Updates an entity state in memory repository.
     *
     * @param IEntity $entity Updated entity
     * @return void
     */
    public function update(IEntity $entity): void
    {
        // In process-local RAM references, application mutations already affect the entity.
        // We replace the instance map entry in case of clones or overrides.
        $this->put($entity);
    }

    /**
     * Removes all entities satisfying the given specification.
     *
     * @param ISpecification $specification Removal rule
     * @return int Total number of removed entities
     */
    public function removeAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        $this->validateSpecification($specification);
        return $this->writeExclusively(function () use ($specification): int {
            $removed = 0;
            foreach ($this->db as $key => $entity) {
                if ($specification->isSatisfiedBy($entity)) {
                    unset($this->db[$key]);
                    $removed++;
                }
            }
            return $removed;
        });
    }

    /**
     * Removes a specific entity from storage.
     *
     * @param IEntity $entity Entity to remove
     * @return bool True if found and removed, false otherwise
     */
    public function remove(IEntity $entity): bool
    {
        return $this->writeExclusively(function () use ($entity): bool {
            $key = $this->keyOf($entity);

            if (array_key_exists($key, $this->db)) {
                unset($this->db[$key]);
                return true;
            }

            // Proactive fallback comparing entity equality
            foreach ($this->db as $k => $e) {
                if ($e->equals($entity)) {
                    unset($this->db[$k]);
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Clears entire repository memory in O(1) time.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->writeExclusively(function (): void {
            $this->db = [];
        });
    }

    /**
     * Returns all stored entities as a pure array.
     *
     * @return array<T>
     */
    public function getAll(): array
    {
        return $this->readConcurrently(fn(): array => array_values($this->db));
    }

    /**
     * Returns all stored entities as a fluent ALinqCollection.
     *
     * asLazyCollection() and findAsLazyCollection() live in AbstractRepository since 1.5.0 (every
     * repository has them, over iterate()).
     *
     * @return IALinqCollection
     */
    public function asLinqCollection(): IALinqCollection
    {
        return \Antevemus\ASpecification\Linq\ALinqBridge::toCollection($this->db);
    }

    /**
     * Queries all entities satisfying specification and returns as an ALinqCollection.
     *
     * @param ISpecification $specification
     * @return IALinqCollection
     */
    public function findAsLinqCollection(ISpecification $specification): IALinqCollection
    {
        return \Antevemus\ASpecification\Linq\ALinqBridge::queryRepository($this, $specification);
    }

    /**
     * Storage key of an entity: its scalar identifier, or the object hash when the identifier is not scalar.
     *
     * @param IEntity $entity
     * @return string
     */
    private function keyOf(IEntity $entity): string
    {
        $id = $entity->getEntityId();
        return is_scalar($id) ? (string) $id : spl_object_hash($entity);
    }

    /**
     * Lazy generator over the storage; foreach iterates a snapshot of the map, so entities put or removed
     * while iterating do not disturb the iteration.
     *
     * @param ISpecification $specification
     * @return Generator<int, T>
     */
    private function iterateMatching(ISpecification $specification): Generator
    {
        foreach ($this->db as $entity) {
            if ($specification->isSatisfiedBy($entity)) {
                yield $entity;
            }
        }
    }
}
