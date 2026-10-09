<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ALinq\Interfaces\IALinqCollection;
use Antevemus\ASpecification\Contracts\Concurrent\ISynchronizer;
use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IVolatileRepository;
use Closure;
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
 * - Optional time-to-live (1.6.0, RN-05): withTtl($seconds) makes every entry expire $seconds after
 *   its last write (put/update); an expired entry is never returned by find*()/iterate()/count()/
 *   contains()/findSingle()/getAll(), is evicted lazily when a scan or a membership test meets it,
 *   and prune() evicts every expired entry at once. The clock is injectable (withClock()) so that
 *   tests drive the expiry deterministically. Without a TTL nothing is timestamped and no read checks
 *   expiry (reads go straight to the map, as before).
 *
 * @template T of IEntity
 * @extends AbstractRepository<T>
 * @implements IVolatileRepository<T>
 * @version    1.6.0
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

    /** Time-to-live of an entry in seconds, counted from its last write; null = entries never expire (RN-05). */
    private ?int $ttlSeconds = null;

    /** @var array<string, int|float> Time of the last write of each entry (only kept while a TTL is set) */
    private array $writtenAt = [];

    /** @var (Closure(): (int|float))|null Clock returning the current Unix time in seconds; null = system clock */
    private ?Closure $clock = null;

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
            $storage = $this->liveStorage();
            if (array_key_exists($this->keyOf($entity), $storage)) {
                return true;
            }

            foreach ($storage as $stored) {
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
            foreach ($this->liveStorage() as $entity) {
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
            foreach ($this->liveStorage() as $entity) {
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
            $key = $this->keyOf($entity);
            $this->db[$key] = $entity;
            if ($this->ttlSeconds !== null) {
                $this->writtenAt[$key] = $this->now();
            }
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
            foreach ($this->liveStorage() as $key => $entity) {
                if ($specification->isSatisfiedBy($entity)) {
                    unset($this->db[$key], $this->writtenAt[$key]);
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
            $storage = $this->liveStorage();

            if (array_key_exists($key, $storage)) {
                unset($this->db[$key], $this->writtenAt[$key]);
                return true;
            }

            // Proactive fallback comparing entity equality
            foreach ($storage as $k => $e) {
                if ($e->equals($entity)) {
                    unset($this->db[$k], $this->writtenAt[$k]);
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
            $this->writtenAt = [];
        });
    }

    /**
     * Returns all stored entities as a pure array.
     *
     * @return array<T>
     */
    public function getAll(): array
    {
        return $this->readConcurrently(fn(): array => array_values($this->liveStorage()));
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
        return \Antevemus\ASpecification\Linq\ALinqBridge::toCollection($this->readConcurrently(fn(): array => $this->liveStorage()));
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
        if ($this->ttlSeconds === null) {
            foreach ($this->db as $entity) {
                if ($specification->isSatisfiedBy($entity)) {
                    yield $entity;
                }
            }
            return;
        }

        // TTL: the iteration runs outside the synchronizer's permit, so it only skips expired
        // entries (evicting them is left to the next scan, membership test or prune()).
        $now = $this->now();
        foreach ($this->db as $key => $entity) {
            if ($this->isExpired($key, $now)) {
                continue;
            }
            if ($specification->isSatisfiedBy($entity)) {
                yield $entity;
            }
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // Time-to-live (1.6.0, RN-05)
    ///////////////////////////////////////////////////////////////////////////

    /**
     * {@inheritdoc}
     *
     * Entries already stored when the TTL is turned on start counting from that moment; changing the
     * TTL later applies the new duration to every entry from its last write. withTtl(0) turns the
     * expiry off and drops the timestamps.
     *
     * @throws InvalidArgumentException When $seconds is negative
     */
    public function withTtl(int $seconds): static
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException("TTL must be zero (no expiry) or a positive number of seconds, {$seconds} given.");
        }

        $this->writeExclusively(function () use ($seconds): void {
            if ($seconds === 0) {
                $this->ttlSeconds = null;
                $this->writtenAt = [];
                return;
            }

            $this->ttlSeconds = $seconds;
            $now = $this->now();
            foreach ($this->db as $key => $_) {
                $this->writtenAt[$key] ??= $now;
            }
        });

        return $this;
    }

    /**
     * Returns the time-to-live in seconds, or null when entries never expire.
     *
     * @return int|null
     */
    public function getTtl(): ?int
    {
        return $this->ttlSeconds;
    }

    /**
     * Replaces the clock used by the time-to-live, fluently (deterministic tests, simulated time).
     *
     * @param (Closure(): (int|float))|null $clock Returns the current Unix time in seconds; null = system clock
     * @return static
     */
    public function withClock(?Closure $clock): static
    {
        $this->clock = $clock;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function prune(): int
    {
        return $this->writeExclusively(function (): int {
            if ($this->ttlSeconds === null) {
                return 0;
            }

            $now = $this->now();
            $pruned = 0;
            foreach ($this->writtenAt as $key => $_) {
                if ($this->isExpired($key, $now)) {
                    unset($this->db[$key], $this->writtenAt[$key]);
                    $pruned++;
                }
            }

            return $pruned;
        });
    }

    /**
     * Copies the expiry policy (TTL and clock) of another in-memory repository. Used by
     * PartitionRepository when it builds the repository of a new partition, so that partitions of a
     * repository with a TTL expire the same way.
     *
     * @internal
     * @param InMemoryRepository $source
     * @return static
     */
    public function adoptExpiryPolicyOf(InMemoryRepository $source): static
    {
        $this->clock = $source->clock;
        if ($source->ttlSeconds !== null) {
            $this->withTtl($source->ttlSeconds);
        }
        return $this;
    }

    /**
     * The storage without its expired entries. Without a TTL it is the map itself (no copy); with a
     * TTL the expired entries are evicted on the way (lazy removal on access).
     *
     * @return array<string, T>
     */
    private function liveStorage(): array
    {
        if ($this->ttlSeconds === null) {
            return $this->db;
        }

        $now = $this->now();
        foreach ($this->writtenAt as $key => $_) {
            if ($this->isExpired($key, $now)) {
                unset($this->db[$key], $this->writtenAt[$key]);
            }
        }

        return $this->db;
    }

    /**
     * Whether the entry stored under the key has outlived the TTL at the given time.
     *
     * @param int|string $key Storage key (PHP turns numeric string keys into integers)
     * @param int|float $now
     * @return bool
     */
    private function isExpired(int|string $key, int|float $now): bool
    {
        return $this->ttlSeconds !== null
            && isset($this->writtenAt[$key])
            && $this->writtenAt[$key] + $this->ttlSeconds <= $now;
    }

    /**
     * Current Unix time in seconds, from the injected clock or the system clock.
     *
     * @return int|float
     */
    private function now(): int|float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }
}
