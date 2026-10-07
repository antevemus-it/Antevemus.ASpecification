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
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Contracts\Repositories\Serialization\IEntitySerializer;
use Antevemus\ASpecification\Repositories\AbstractRepository;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Repositories\PersistentPartitionRepository;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;

/**
 * InMemoryAndFileRepository - Hybrid decorator combining in-memory caching and persistent file storage
 *
 * Provides ultra-fast queries in RAM without disk I/O latency, while delegating durability
 * to the underlying file repository according to the configured PersistenceDefinition.
 *
 * Features:
 * - Instant read resolution against volatile memory cache
 * - Write-through or snapshot synchronization with physical file storage
 * - Automatic cache warmup during load() and safe flush during close()
 *
 * @template T of IEntity
 * @extends AbstractRepository<T>
 * @implements IPersistentRepository<T>
 * @version    1.3.0
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
            throw new RepositoryException("InMemoryAndFileRepository requer um IPersistentRepository e opcionalmente um IVolatileRepository.");
        }
        $this->persistenceDefinition = $persistenceDefinition;
        $this->repositoryId = $repositoryId ?? ("hybrid_" . $this->fileBackend->getRepositoryId());
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
     * @param IEntitySerializer $serializer Serializer for the L2 file (JsonEntitySerializer with the entity class, or PhpNativeEntitySerializer)
     * @param IVolatileRepository<T>|null $cache Optional L1 repository (default: new InMemoryRepository)
     * @param string|null $repositoryId Optional repository identifier (default: "hybrid_" + file name)
     * @param PersistenceDefinition $persistenceDefinition Persistence mode of both tiers (default: ReadWrite, write-through)
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
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->fileBackend->getType();
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
     * {@inheritdoc}
     */
    public function getEntityMetaData(IEntity $entity): ?IEntityPersistenceMetaData
    {
        return $this->fileBackend->getEntityMetaData($entity);
    }

    /**
     * Loads entities from the file backend and warms up volatile in-memory cache.
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
    }

    /**
     * {@inheritdoc}
     */
    public function store(): void
    {
        $this->fileBackend->store();
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        $this->fileBackend->close();
    }

    /**
     * {@inheritdoc}
     */
    public function put(IEntity $entity): void
    {
        $this->assertWritable();

        $this->memoryCache->put($entity);
        $this->fileBackend->put($entity);
    }

    /**
     * {@inheritdoc}
     */
    public function putAll(array $collectionOfEntities): void
    {
        $this->assertWritable();

        $this->memoryCache->putAll($collectionOfEntities);
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
        $this->put($entity);
    }

    /**
     * {@inheritdoc}
     */
    public function remove(IEntity $entity): bool
    {
        $this->assertWritable();

        $memRemoved = $this->memoryCache->remove($entity);
        $fileRemoved = $this->fileBackend->remove($entity);

        return $memRemoved || $fileRemoved;
    }

    /**
     * {@inheritdoc}
     */
    public function removeAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        $this->assertWritable();

        $this->memoryCache->removeAll($specification);
        return $this->fileBackend->removeAll($specification);
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        $this->assertWritable();

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
     */
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        return $this->memoryCache->iterate($specification);
    }

    /**
     * {@inheritdoc}
     */
    public function contains(IEntity $entity): bool
    {
        return $this->memoryCache->contains($entity);
    }

    /**
     * {@inheritdoc}
     */

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
     * @param ISpecification|null $specification Specification scoping the partition
     * @return IPartitionRepository Created persistent partition
     */
    public function makePartition(?ISpecification $specification = null): IPartitionRepository
    {
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
            throw new RepositoryException("Operação de escrita rejeitada: o repositório híbrido '{$this->repositoryId}' está em modo ReadOnly.");
        }
    }
}
