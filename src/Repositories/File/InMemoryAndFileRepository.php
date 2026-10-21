<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\File;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\IHumanReadableFormatRepository;
use Antevemus\ASpecification\Contracts\Repositories\IPartitionRepository;
use Antevemus\ASpecification\Contracts\Repositories\IPersistentRepository;
use Antevemus\ASpecification\Contracts\Repositories\IEntityPersistenceMetaData;
use Antevemus\ASpecification\Contracts\Repositories\ITextualFormatRepository;
use Antevemus\ASpecification\Contracts\Repositories\IVolatileRepository;
use Antevemus\ASpecification\Contracts\Repositories\IWriteBehindBackend;
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Contracts\Repositories\Serialization\IEntitySerializer;
use Antevemus\ASpecification\Repositories\AbstractRepository;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Repositories\PersistentPartitionRepository;
use Antevemus\ASpecification\Repositories\RepositoryPersistenceMetaData;
use Antevemus\ASpecification\Repositories\VolatilePartitionRepository;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Helpers\SpecificationHelper;

/**
 * InMemoryAndFileRepository - Hybrid decorator combining in-memory caching and persistent file storage
 *
 * Provides ultra-fast queries in RAM without disk I/O latency, while delegating durability
 * to the underlying file repository according to the configured PersistenceDefinition.
 *
 * Two ways of writing, chosen by the PersistenceDefinition (2.8.0):
 * - write-through (ReadWrite, the default, and every other mode but the two below, as before 2.8.0):
 *   put()/putAll()/update()/remove()/removeAll()/clear() write to the cache and to the backend in the same
 *   call;
 * - write-behind (MemoryAsyncFile, the Domian INMEMORY_AND_FILE; MemoryAsyncDelegated, the same over any
 *   other persistent backend): the writes touch only the cache and count as pending; persist() (also
 *   store()) writes the whole cache to the backend at once and clears the pending count; load() reloads
 *   the cache from the backend and DISCARDS what was not persisted, as in Domian; close() persists when
 *   something is pending. Queries answer from the cache, pending writes included. A failed persist() leaves
 *   the cache and the pending count as they were (the exception reaches the caller) and, over a
 *   single-file backend, the previous document intact (temporary file renamed over it). persistEvery(N)
 *   persists every N pending writes; persistOnShutdown() persists at the end of the script, which does not
 *   run when the process is killed (SIGKILL) or dies of a fatal error before it. The repository record
 *   (getRepositoryMetaData(): loads and persists of the whole repository) is stored with the content.
 *
 * Features:
 * - Instant read resolution against volatile memory cache
 * - Write-through, snapshot or write-behind synchronization with physical file storage
 * - Automatic cache warmup during load() and safe flush during close()
 *
 * @template T of IEntity
 * @extends AbstractRepository<T>
 * @implements IPersistentRepository<T>
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\File
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class InMemoryAndFileRepository extends AbstractRepository implements
    IPersistentRepository,
    ITextualFormatRepository,
    IHumanReadableFormatRepository
{
    protected readonly string $repositoryId;

    /**
     * @param IVolatileRepository<T> $memoryCache In-memory repository used as cache
     * @param IPersistentRepository<T> $fileBackend Underlying physical file repository
     * @param PersistenceDefinition $persistenceDefinition Persistence mode
     * @param string|null $repositoryId Unique repository identifier
     */
    protected readonly IVolatileRepository $memoryCache;
    protected readonly IPersistentRepository $fileBackend;
    protected readonly PersistenceDefinition $persistenceDefinition;

    /** Entity writes made in the cache and not persisted yet (write-behind). */
    private int $pendingWrites = 0;

    /** @var array<string, mixed> Ids put or updated since the last persist() or load(), keyed as the cache keys them (write-behind). */
    private array $writtenIds = [];

    /** Persist every this many pending writes; 0 = never automatically (write-behind). */
    private int $persistEvery = 0;

    /** Whether persistOnShutdown() already registered its shutdown function. */
    private bool $shutdownRegistered = false;

    /** The repository record of the write-behind modes (loads and persists). */
    private RepositoryPersistenceMetaData $repositoryMetaData;

    /**
     * @param IPersistentRepository<T>|IVolatileRepository<T> $backendOrCache File repository or in-memory cache
     * @param IPersistentRepository<T>|IVolatileRepository<T>|null $secondArg Optional cache or persistent repository
     * @param PersistenceDefinition $persistenceDefinition Persistence mode
     * @param string|null $repositoryId Unique repository identifier
     */
    public function __construct(
        IPersistentRepository|IVolatileRepository $backendOrCache,
        IPersistentRepository|IVolatileRepository|null $secondArg = null,
        PersistenceDefinition $persistenceDefinition = PersistenceDefinition::ReadWrite,
        ?string $repositoryId = null
    ) {
        if ($backendOrCache instanceof IPersistentRepository && ($secondArg === null || $secondArg instanceof IVolatileRepository)) {
            $this->fileBackend = $backendOrCache;
            $this->memoryCache = $secondArg ?? new InMemoryRepository();
        } elseif ($backendOrCache instanceof IVolatileRepository && $secondArg instanceof IPersistentRepository) {
            $this->memoryCache = $backendOrCache;
            $this->fileBackend = $secondArg;
        } else {
            throw new RepositoryException("InMemoryAndFileRepository requires an IPersistentRepository and optionally an IVolatileRepository.");
        }
        $this->persistenceDefinition = $persistenceDefinition;
        $this->repositoryId = $repositoryId ?? ("hybrid_" . $this->fileBackend->getRepositoryId());
        $this->repositoryMetaData = new RepositoryPersistenceMetaData();
    }

    /**
     * Named factory for the common L1 (RAM) + L2 (single file) tiering.
     *
     * Builds the hybrid repository from a storage path and a serializer, so that the
     * README example reads as written:
     * <code>
     * $repo = InMemoryAndFileRepository::create(
     *     storagePath: '/var/data/customers.json',
     *     serializer: new JsonEntitySerializer(Customer::class)
     * );
     * $repo->put($newCustomer); // stored in RAM and synchronized to disk atomically
     * </code>
     *
     * The L2 tier is a SingleFileRepository over $storagePath; the L1 tier is $cache or a fresh
     * InMemoryRepository. The parent directory of $storagePath does not need to exist: the
     * factory creates it (the file lock lives next to the document and needs the directory before
     * the first write). When the file already exists, the cache is warmed up from it before the
     * repository is returned, so queries and a second instance over the same file see the
     * persisted entities without an explicit warmup() call.
     *
     * @param string $storagePath Single file path (e.g. storage/customers.json)
     * @param IEntitySerializer $serializer Serializer for the L2 file (JsonEntitySerializer with the entity class; the L2 document is JSON whatever the serializer, since 2.0.0)
     * @param IVolatileRepository<T>|null $cache Optional L1 repository (default: new InMemoryRepository)
     * @param string|null $repositoryId Optional repository identifier (default: "hybrid_" + file name)
     * @param PersistenceDefinition $persistenceDefinition Persistence mode of both tiers (default: ReadWrite, write-through;
     *                                                    MemoryAsyncFile: write-behind, see the class docblock)
     * @return self<T>
     * @throws RepositoryException If the parent directory cannot be created
     */
    public static function create(
        string $storagePath,
        IEntitySerializer $serializer,
        ?IVolatileRepository $cache = null,
        ?string $repositoryId = null,
        PersistenceDefinition $persistenceDefinition = PersistenceDefinition::ReadWrite
    ): self {
        $directory = dirname($storagePath);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RepositoryException(sprintf('Unable to create the storage directory "%s".', $directory));
        }

        $repository = new self(
            $cache ?? new InMemoryRepository(),
            new SingleFileRepository($storagePath, $serializer, $persistenceDefinition),
            $persistenceDefinition,
            $repositoryId
        );
        $repository->warmup();

        return $repository;
    }

    /**
     * Returns the volatile in-memory cache repository.
     *
     * @return IVolatileRepository<T>
     */
    public function getMemoryCache(): IVolatileRepository
    {
        return $this->memoryCache;
    }

    /**
     * Returns the underlying physical file repository backend.
     *
     * @return IPersistentRepository<T>
     */
    public function getFileBackend(): IPersistentRepository
    {
        return $this->fileBackend;
    }

    /**
     * {@inheritdoc}
     */
    public function getRepositoryId(): string
    {
        return $this->repositoryId;
    }

    /**
     * {@inheritdoc}
     */
    public function getPersistenceDefinition(): PersistenceDefinition
    {
        return $this->persistenceDefinition;
    }

    /**
     * The entity type of the backend; IEntity for a backend that does not declare one (a delegated store,
     * 2.8.0: until then the backend had to be a file repository).
     *
     * @return string
     */
    public function getType(): string
    {
        return method_exists($this->fileBackend, 'getType') ? $this->fileBackend->getType() : IEntity::class;
    }

    protected bool $isWarmedUp = false;

    /**
     * Performs warmup of the in-memory cache from the persistent backend.
     *
     * @return void
     */
    public function warmup(): void
    {
        $this->load();
        $this->isWarmedUp = true;
    }

    /**
     * Indicates whether the in-memory cache has been warmed up.
     *
     * @return bool
     */
    public function isWarmedUp(): bool
    {
        return $this->isWarmedUp;
    }

    /**
     * {@inheritdoc}
     */
    public function getDataDirectory(): ?string
    {
        return $this->fileBackend->getDataDirectory();
    }

    /**
     * {@inheritdoc}
     */
    public function getFormatDescription(): string
    {
        return 'Hybrid InMemory / ' . $this->fileBackend->getFormatDescription();
    }

    /**
     * Delegates to the L2 file backend: the record reflects the writes this hybrid forwarded to
     * the backend; reads served from the L1 cache are not backend reads and are not counted.
     *
     * {@inheritdoc}
     */
    public function getEntityMetaData(IEntity $entity): ?IEntityPersistenceMetaData
    {
        return $this->fileBackend->getEntityMetaData($entity);
    }

    /**
     * Tells whether the working area is memory (Domian isMemoryBased()): answered by the persistence
     * definition, so true in the write-behind modes and false in ReadWrite (2.8.0).
     *
     * @return bool
     */
    public function isMemoryBased(): bool
    {
        return $this->persistenceDefinition->isMemoryBased();
    }

    /**
     * Tells whether the cache holds writes not persisted yet (write-behind; always false in the other modes).
     *
     * @return bool
     */
    public function isDirty(): bool
    {
        return $this->pendingWrites > 0;
    }

    /**
     * Number of entity writes made in the cache since the last persist() or load(): one per entity put,
     * updated or removed (putAll() of 10 entities counts 10, a remove() that finds nothing counts 0, clear()
     * counts the entities it removed). Always 0 outside the write-behind modes.
     *
     * @return int
     */
    public function pendingWrites(): int
    {
        return $this->pendingWrites;
    }

    /**
     * Persists automatically whenever the pending writes reach $writes (write-behind); 0 turns it off.
     *
     * @param int $writes Number of pending writes that triggers persist() (>= 0)
     * @return static
     * @throws \InvalidArgumentException When $writes is negative
     * @throws RepositoryException Outside the write-behind modes
     */
    public function persistEvery(int $writes): static
    {
        $this->assertWriteBehind('persistEvery');
        if ($writes < 0) {
            throw new \InvalidArgumentException("persistEvery() takes a number of writes >= 0 (0 turns it off), {$writes} given.");
        }
        $this->persistEvery = $writes;

        return $this;
    }

    /**
     * Persists the cache at the end of the script when it holds pending writes (write-behind), through
     * register_shutdown_function(), registered once per repository. The repository is held by a weak
     * reference: one already destroyed is not persisted (close() it). The shutdown functions do not run
     * when the process is killed (SIGKILL) and an exception thrown there is reported as uncaught.
     *
     * @return static
     * @throws RepositoryException Outside the write-behind modes
     */
    public function persistOnShutdown(): static
    {
        $this->assertWriteBehind('persistOnShutdown');
        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            $reference = \WeakReference::create($this);
            register_shutdown_function(static function () use ($reference): void {
                $repository = $reference->get();
                if ($repository !== null && $repository->isDirty()) {
                    $repository->persist();
                }
            });
        }

        return $this;
    }

    /**
     * The repository record (2.8.0). In the write-behind modes every load() counts a read and every
     * persist() a write (put(), find() and the other operations do not touch it, as in Domian), and the
     * record is stored with the content (single file: `__repository_metadata`; one file per entity:
     * `_repository.json`) and read back by load(). In the other modes it is the record the backend read
     * from its storage, unchanged, or an empty record.
     *
     * @return RepositoryPersistenceMetaData
     */
    public function getRepositoryMetaData(): RepositoryPersistenceMetaData
    {
        if (!$this->isWriteBehind() && $this->fileBackend instanceof IWriteBehindBackend) {
            return $this->fileBackend->getRepositoryMetaData() ?? $this->repositoryMetaData;
        }

        return $this->repositoryMetaData;
    }

    /**
     * Writes the cache to the backend (write-behind): the whole working set and the repository record, the
     * record counting one write. An IWriteBehindBackend receives it in one persistSnapshot() (one atomic
     * write for SingleFileRepository); any other backend gets removeAll() + putAll() + store(). On failure
     * the exception reaches the caller and nothing changes here: the cache, the pending writes and the
     * record stay as they were. In the other modes persist() is store().
     *
     * @return void
     * @throws RepositoryException When the backend cannot write
     */
    public function persist(): void
    {
        if (!$this->isWriteBehind()) {
            $this->fileBackend->store();
            return;
        }

        $entities = $this->memoryCache->findAll(new AllEntitiesSpecification($this->getType()));
        $record = clone $this->repositoryMetaData;
        $record->registerWrite();

        if ($this->fileBackend instanceof IWriteBehindBackend) {
            $this->fileBackend->persistSnapshot($entities, array_values($this->writtenIds), $record);
        } else {
            $this->fileBackend->removeAll(new AllEntitiesSpecification($this->getType()));
            $this->fileBackend->putAll($entities);
            $this->fileBackend->store();
        }

        $this->repositoryMetaData = $record;
        $this->pendingWrites = 0;
        $this->writtenIds = [];
    }

    /**
     * Loads entities from the file backend and warms up volatile in-memory cache.
     *
     * Write-behind (2.8.0): the cache is replaced by the stored content, so writes not persisted are
     * discarded (as in Domian); the pending count is cleared and the repository record, read from the
     * storage, counts one read.
     *
     * @return void
     */
    public function load(): void
    {
        $this->isWarmedUp = true;

        $this->fileBackend->load();

        if (method_exists($this->memoryCache, "clear")) {
            $this->memoryCache->clear();
        }

        $allSpec = new AllEntitiesSpecification($this->getType());
        $entities = $this->fileBackend->findAll($allSpec);
        foreach ($entities as $entity) {
            $this->memoryCache->put($entity);
        }

        if ($this->isWriteBehind()) {
            $stored = $this->fileBackend instanceof IWriteBehindBackend ? $this->fileBackend->getRepositoryMetaData() : null;
            $record = $stored !== null ? clone $stored : clone $this->repositoryMetaData;
            $record->registerRead();
            $this->repositoryMetaData = $record;
            $this->pendingWrites = 0;
            $this->writtenIds = [];
        }
    }

    /**
     * {@inheritdoc}
     *
     * Write-behind: persist().
     */
    public function store(): void
    {
        if ($this->isWriteBehind()) {
            $this->persist();
            return;
        }

        $this->fileBackend->store();
    }

    /**
     * {@inheritdoc}
     *
     * Write-behind: persists first when writes are pending.
     */
    public function close(): void
    {
        if ($this->isWriteBehind() && $this->isDirty()) {
            $this->persist();
        }

        $this->fileBackend->close();
    }

    /**
     * {@inheritdoc}
     */
    public function put(IEntity $entity): void
    {
        $this->assertWritable();

        $this->memoryCache->put($entity);
        if ($this->isWriteBehind()) {
            $this->writtenIds[self::writtenKey($entity)] = $entity->getEntityId();
            $this->countWrites(1);
            return;
        }
        $this->fileBackend->put($entity);
    }

    /**
     * {@inheritdoc}
     */
    public function putAll(array $collectionOfEntities): void
    {
        $this->assertWritable();

        $this->memoryCache->putAll($collectionOfEntities);
        if ($this->isWriteBehind()) {
            $count = 0;
            foreach ($collectionOfEntities as $entity) {
                if ($entity instanceof IEntity) {
                    $this->writtenIds[self::writtenKey($entity)] = $entity->getEntityId();
                    $count++;
                }
            }
            $this->countWrites($count);
            return;
        }
        $this->fileBackend->putAll($collectionOfEntities);
    }

    /**
     * {@inheritdoc}
     */
    public function update(IEntity $entity): void
    {
        $this->put($entity);
    }

    /**
     * {@inheritdoc}
     */
    public function updateWithDelta(IEntity $entity, ?ISpecification $deltaSpecification = null): void
    {
        // Applies the delta (value-bound clauses become property writes) before persisting,
        // like the in-memory repositories (Domian SpecificationUtils.updateEntityState).
        SpecificationHelper::updateEntityState($entity, $deltaSpecification);
        $this->put($entity);
    }

    /**
     * {@inheritdoc}
     */
    public function remove(IEntity $entity): bool
    {
        $this->assertWritable();

        $memRemoved = $this->memoryCache->remove($entity);
        if ($this->isWriteBehind()) {
            if ($memRemoved) {
                unset($this->writtenIds[self::writtenKey($entity)]);
                $this->countWrites(1);
            }
            return $memRemoved;
        }
        $fileRemoved = $this->fileBackend->remove($entity);

        return $memRemoved || $fileRemoved;
    }

    /**
     * {@inheritdoc}
     */
    public function removeAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        $this->assertWritable();

        $removed = $this->memoryCache->removeAll($specification);
        if ($this->isWriteBehind()) {
            $this->countWrites($removed);
            return $removed;
        }
        return $this->fileBackend->removeAll($specification);
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        $this->assertWritable();

        if ($this->isWriteBehind()) {
            $this->countWrites($this->memoryCache->removeAll(new AllEntitiesSpecification($this->getType())));
            return;
        }
        if (method_exists($this->memoryCache, "clear")) {
            $this->memoryCache->clear();
        }
        if (method_exists($this->fileBackend, "clear")) {
            $this->fileBackend->clear();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findAllEntitiesSpecifiedBy(ISpecification $specification): array
    {
        return $this->memoryCache->findAll($specification);
    }

    /**
     * {@inheritdoc}
     */
    public function countAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        return $this->memoryCache->count($specification);
    }

    /**
     * {@inheritdoc}
     *
     * Lazy (1.5.0, RN-04): served by the L1 cache's own generator (InMemoryRepository yields each
     * entity when pulled, without copying the map); the L2 file is not read during the iteration.
     */
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        return $this->memoryCache->iterate($specification);
    }

    /**
     * Answers from the L1 cache (contains() is part of IRepository since 1.4.0, BUG-20261007-ORNH).
     *
     * {@inheritdoc}
     */
    public function contains(IEntity $entity): bool
    {
        return $this->memoryCache->contains($entity);
    }

    /**
     * Convenience alias for findSingleEntitySpecifiedBy.
     *
     * @param ISpecification $specification
     * @return IEntity|null
     */
    public function getEntitySpecifiedBy(ISpecification $specification): ?IEntity
    {
        return $this->findSingleEntitySpecifiedBy($specification);
    }

    /**
     * Counts all entities present in the hybrid repository.
     *
     * @return int
     */
    public function countAllEntities(): int
    {
        return $this->countAllEntitiesSpecifiedBy(new AllEntitiesSpecification($this->getType()));
    }

    /**
     * Creates a virtual persistent partition bound to this hybrid repository.
     *
     * Write-behind (2.8.0): a volatile partition (VolatilePartitionRepository) over this repository, served
     * by the cache: it has no load()/store()/close() of its own, and its writes go through this repository,
     * so they count as pending and reach the storage with its next persist().
     *
     * @param ISpecification|null $specification Specification scoping the partition
     * @return IPartitionRepository Created persistent partition
     */
    public function makePartition(?ISpecification $specification = null): IPartitionRepository
    {
        if ($this->isWriteBehind()) {
            return new VolatilePartitionRepository($this, $specification, null, $this->getType());
        }

        return new PersistentPartitionRepository($this, $specification, null, $this->getType());
    }

    /**
     * Validates write permission.
     *
     * @throws RepositoryException
     */
    protected function assertWritable(): void
    {
        if ($this->persistenceDefinition === PersistenceDefinition::ReadOnly) {
            throw new RepositoryException("Write operation rejected: hybrid repository '{$this->repositoryId}' is in ReadOnly mode.");
        }
    }

    /**
     * Tells whether the writes are deferred to persist() (MemoryAsyncFile, MemoryAsyncDelegated).
     *
     * @return bool
     */
    private function isWriteBehind(): bool
    {
        return $this->persistenceDefinition === PersistenceDefinition::MemoryAsyncFile
            || $this->persistenceDefinition === PersistenceDefinition::MemoryAsyncDelegated;
    }

    /**
     * Refuses an operation that exists only in the write-behind modes.
     *
     * @param string $operation
     * @throws RepositoryException
     */
    private function assertWriteBehind(string $operation): void
    {
        if (!$this->isWriteBehind()) {
            throw new RepositoryException(sprintf(
                '%s() needs a write-behind hybrid repository (MemoryAsyncFile or MemoryAsyncDelegated); \'%s\' is %s.',
                $operation,
                $this->repositoryId,
                $this->persistenceDefinition->name
            ));
        }
    }

    /**
     * Key of an entity in the written ids: its scalar id as text, the object hash for any other id (the
     * keying of InMemoryRepository).
     *
     * @param IEntity $entity
     * @return string
     */
    private static function writtenKey(IEntity $entity): string
    {
        $id = $entity->getEntityId();

        return is_scalar($id) ? (string) $id : spl_object_hash($entity);
    }

    /**
     * Counts entity writes made in the cache (write-behind) and persists when persistEvery() is reached.
     *
     * @param int $writes
     * @return void
     */
    private function countWrites(int $writes): void
    {
        if ($writes <= 0) {
            return;
        }
        $this->pendingWrites += $writes;
        if ($this->persistEvery > 0 && $this->pendingWrites >= $this->persistEvery) {
            $this->persist();
        }
    }
}
