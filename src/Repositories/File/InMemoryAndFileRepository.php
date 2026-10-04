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
use Antevemus\ASpecification\Repositories\AbstractRepository;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Repositories\PersistentPartitionRepository;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;

/**
 * InMemoryAndFileRepository - Decorator híbrido que combina cache em memória e persistência em arquivo
 *
 * Fornece consultas ultra-rápidas na RAM sem latência de I/O em disco, delegando a
 * durabilidade ao repositório de arquivo subjacente de acordo com o PersistenceDefinition.
 *
 * Funcionalidades:
 * - Resolução instantânea de leituras na memória volátil
 * - Sincronização write-through ou snapshot com o storage físico
 * - Warmup automático de cache no load() e flush seguro no close()
 *
 * @template T of IEntity
 * @extends AbstractRepository<T>
 * @implements IPersistentRepository<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\File
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class InMemoryAndFileRepository extends AbstractRepository implements
    IPersistentRepository,
    ITextualFormatRepository,
    IHumanReadableFormatRepository
{
    protected readonly string $repositoryId;

    /**
     * @param IVolatileRepository<T> $memoryCache Repositório em memória usado como cache
     * @param IPersistentRepository<T> $fileBackend Repositório em arquivo físico de retaguarda
     * @param PersistenceDefinition $persistenceDefinition Modo de persistência
     * @param string|null $repositoryId Identificador único do repositório
     */
    protected readonly IVolatileRepository $memoryCache;
    protected readonly IPersistentRepository $fileBackend;
    protected readonly PersistenceDefinition $persistenceDefinition;

    /**
     * @param IPersistentRepository<T>|IVolatileRepository<T> $backendOrCache Repositório em arquivo ou cache em memória
     * @param IPersistentRepository<T>|IVolatileRepository<T>|null $secondArg Cache opcional ou repositório persistente
     * @param PersistenceDefinition $persistenceDefinition Modo de persistência
     * @param string|null $repositoryId Identificador único do repositório
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
     * Retorna o repositório de cache volátil em memória.
     *
     * @return IVolatileRepository<T>
     */
    public function getMemoryCache(): IVolatileRepository
    {
        return $this->memoryCache;
    }

    /**
     * Retorna o repositório de arquivo físico de retaguarda.
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

    /**
     * {@inheritdoc}
     */

    protected bool $isWarmedUp = false;

    /**
     * Realiza o warmup do cache em memória a partir do backend persistente.
     */
    public function warmup(): void
    {
        $this->load();
        $this->isWarmedUp = true;
    }

    /**
     * Indica se o cache em memória já foi aquecido (warmup).
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
     * Carrega as entidades do backend de arquivo e aquece o cache volátil em memória.
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
     * Alias de conveniência para findSingleEntitySpecifiedBy.
     */
    public function getEntitySpecifiedBy(ISpecification $specification): ?IEntity
    {
        return $this->findSingleEntitySpecifiedBy($specification);
    }

    /**
     * Conta todas as entidades presentes no repositório híbrido.
     */
    public function countAllEntities(): int
    {
        return $this->countAllEntitiesSpecifiedBy(new AllEntitiesSpecification($this->getType()));
    }

    /**
     * Cria uma partição virtual persistente vinculada a este repositório híbrido.
     *
     * @param ISpecification|null $specification Especificação delimitadora da partição
     * @return IPartitionRepository Partição persistente criada
     */
    public function makePartition(?ISpecification $specification = null): IPartitionRepository
    {
        return new PersistentPartitionRepository($this, $specification, null, $this->getType());
    }

    /**
     * Valida permissão de escrita.
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
