<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\File;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\IHumanReadableFormatRepository;
use Antevemus\ASpecification\Contracts\Repositories\IPartitionRepository;
use Antevemus\ASpecification\Contracts\Repositories\IPersistentRepository;
use Antevemus\ASpecification\Contracts\Repositories\ITextualFormatRepository;
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Contracts\Repositories\Serialization\IEntitySerializer;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Repositories\AbstractRepository;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use Antevemus\ASpecification\Repositories\PersistentPartitionRepository;
use Antevemus\ASpecification\Repositories\Serialization\JsonEntitySerializer;
use Antevemus\ASpecification\Contracts\Repositories\IEntityPersistenceMetaData;
use Antevemus\ASpecification\Repositories\EntityPersistenceMetaData;

/**
 * AbstractFileRepository - Base abstract class for persistent file-based entity repositories
 *
 * Centralizes disk file management, format-agnostic serialization via
 * IEntitySerializer, cooperative file locks (flock), and strict enforcement
 * of lifecycle rules and PersistenceDefinition access permissions (RN-01).
 *
 * Features:
 * - Strict validation of ReadOnly, WriteOnly, ReadWrite, and Snapshot modes
 * - Atomic writes via temporary files and atomic rename operations
 * - Native integration with fluent partitioning promotion (Module 4)
 *
 * @template T of IEntity
 * @extends AbstractRepository<T>
 * @implements IPersistentRepository<T>
 * @version    1.2.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\File
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractFileRepository extends AbstractRepository implements
    IPersistentRepository,
    ITextualFormatRepository,
    IHumanReadableFormatRepository
{
    use FileLockTrait;

    protected readonly IEntitySerializer $serializer;
    protected readonly string $entityClass;
    protected readonly PersistenceDefinition $persistenceDefinition;
    protected readonly string $repositoryId;

    /**
     * @param string $storagePath Storage file or directory path
     * @param class-string<T>|string|IEntitySerializer $entityClassOrSerializer Entity class or pluggable serializer
     * @param PersistenceDefinition|IEntitySerializer $persistenceDefinitionOrSerializer Persistence mode or serializer
     * @param IEntitySerializer|null $serializer Pluggable serializer (default: JsonEntitySerializer)
     * @param string|null $repositoryId Unique repository identifier
     */
    public function __construct(
        protected readonly string $storagePath,
        string|IEntitySerializer $entityClassOrSerializer = IEntity::class,
        PersistenceDefinition|IEntitySerializer $persistenceDefinitionOrSerializer = PersistenceDefinition::ReadWrite,
        ?IEntitySerializer $serializer = null,
        ?string $repositoryId = null
    ) {
        if ($entityClassOrSerializer instanceof IEntitySerializer) {
            $this->serializer = $entityClassOrSerializer;
            $this->entityClass = ($entityClassOrSerializer instanceof JsonEntitySerializer && $entityClassOrSerializer->getDefaultEntityClass() !== null)
                ? $entityClassOrSerializer->getDefaultEntityClass()
                : IEntity::class;
            $this->persistenceDefinition = $persistenceDefinitionOrSerializer instanceof PersistenceDefinition
                ? $persistenceDefinitionOrSerializer
                : PersistenceDefinition::ReadWrite;
        } else {
            $this->entityClass = $entityClassOrSerializer;
            if ($persistenceDefinitionOrSerializer instanceof IEntitySerializer) {
                $this->serializer = $persistenceDefinitionOrSerializer;
                $this->persistenceDefinition = PersistenceDefinition::ReadWrite;
            } else {
                $this->persistenceDefinition = $persistenceDefinitionOrSerializer;
                $this->serializer = $serializer ?? new JsonEntitySerializer($this->entityClass);
            }
        }
        $this->repositoryId = $repositoryId ?? basename($storagePath);
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
     * Returns the configured entity serializer.
     *
     * @return IEntitySerializer
     */
    public function getSerializer(): IEntitySerializer
    {
        return $this->serializer;
    }

    /**
     * Returns the configured storage path.
     *
     * @return string
     */
    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->entityClass;
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
     * Counts all entities present in the repository.
     *
     * @return int
     */
    public function countAllEntities(): int
    {
        return $this->countAllEntitiesSpecifiedBy(new AllEntitiesSpecification($this->entityClass));
    }

    /**
     * Creates a virtual persistent partition bound to this file-based repository.
     *
     * @param ISpecification|null $specification Specification scoping the partition
     * @return IPartitionRepository Created persistent partition
     */
    public function makePartition(?ISpecification $specification = null): IPartitionRepository
    {
        return new PersistentPartitionRepository($this, $specification, null, $this->entityClass);
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        if ($this->persistenceDefinition === PersistenceDefinition::Snapshot ||
            $this->persistenceDefinition === PersistenceDefinition::ReadWrite) {
            $this->store();
        }
    }

    /**
     * Validates whether write operations are permitted in the current persistence mode.
     *
     * @throws RepositoryException If the repository is in ReadOnly mode
     */
    protected function assertWritable(): void
    {
        if ($this->persistenceDefinition === PersistenceDefinition::ReadOnly) {
            throw new RepositoryException("Operação de escrita rejeitada: o repositório '{$this->repositoryId}' está em modo ReadOnly.");
        }
    }

    /**
     * Checks whether read operations are permitted in the current persistence mode.
     *
     * @return bool
     */
    protected function isReadable(): bool
    {
        return $this->persistenceDefinition !== PersistenceDefinition::WriteOnly;
    }

    /**
     * @var array<string, IEntityPersistenceMetaData>
     */
    protected array $metadataMap = [];

    /**
     * {@inheritdoc}
     */
    public function getDataDirectory(): ?string
    {
        return is_dir($this->storagePath) ? $this->storagePath : dirname($this->storagePath);
    }

    /**
     * {@inheritdoc}
     */
    public function getFormatDescription(): string
    {
        return 'File Storage (' . $this->serializer->getContentType() . ')';
    }

    /**
     * {@inheritdoc}
     */
    public function getEntityMetaData(IEntity $entity): ?IEntityPersistenceMetaData
    {
        $id = (string) $entity->getEntityId();
        return $this->metadataMap[$id] ?? null;
    }

    /**
     * Records write metadata for an entity.
     *
     * @param IEntity $entity
     * @return void
     */
    protected function recordWriteMetadata(IEntity $entity): void
    {
        $id = (string) $entity->getEntityId();
        if (!isset($this->metadataMap[$id])) {
            $this->metadataMap[$id] = new EntityPersistenceMetaData();
        } else {
            $this->metadataMap[$id]->registerWrite();
        }
    }

    /**
     * Records read metadata for an entity.
     *
     * @param IEntity $entity
     * @return void
     */
    protected function recordReadMetadata(IEntity $entity): void
    {
        $id = (string) $entity->getEntityId();
        if (!isset($this->metadataMap[$id])) {
            $this->metadataMap[$id] = new EntityPersistenceMetaData();
        }
        $this->metadataMap[$id]->registerRead();
    }

    /**
     * Atomically writes content to the target file via temp file and rename.
     *
     * The exclusive lock is taken on $lockPath when given, otherwise on the target
     * itself. Repositories that read-modify-write a whole document MUST pass a
     * separate, stable lock file: after rename() the target is a new inode, so a lock
     * on the target does not serialize successive writers (BUG-20261007-7RZJ).
     *
     * @param string $targetFile Absolute path of destination file
     * @param string $content Content to write
     * @param string|null $lockPath Stable lock file covering the write (default: the target)
     * @return void
     * @throws RepositoryException
     */
    protected function writeAtomic(string $targetFile, string $content, ?string $lockPath = null): void
    {
        $dir = dirname($targetFile);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new RepositoryException("Não foi possível criar o diretório de destino: {$dir}");
            }
        }

        $tmpFile = $targetFile . ".tmp." . bin2hex(random_bytes(4));

        $this->withExclusiveLock($lockPath ?? $targetFile, function () use ($tmpFile, $targetFile, $content): void {
            $handle = @fopen($tmpFile, "wb");
            if ($handle === false) {
                throw new RepositoryException("Falha ao abrir descritor de arquivo temporário: {$tmpFile}");
            }

            $bytesWritten = fwrite($handle, $content);
            fflush($handle);
            fclose($handle);

            if ($bytesWritten === false || $bytesWritten !== strlen($content)) {
                @unlink($tmpFile);
                throw new RepositoryException("Falha ao escrever bytes no arquivo temporário: {$tmpFile}");
            }

            if (!@rename($tmpFile, $targetFile)) {
                @unlink($tmpFile);
                throw new RepositoryException("Falha ao mover arquivo temporário para o destino final: {$targetFile}");
            }
        });
    }
}
