<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\File;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Contracts\Repositories\Serialization\IEntitySerializer;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Helpers\SpecificationHelper;

/**
 * FilePerEntityRepository - Entity repository persisting one file per entity
 *
 * Organizes entities into a directory of individual files keyed by unique entity ID
 * (<storageDir>/<sanitizedId>.<ext>), providing direct O(1) lookups for UniqueEntitySpecification.
 *
 * Features:
 * - Partitioned persistence via individual files
 * - O(1) optimized direct access by unique key
 * - On-demand filesystem iteration
 * - Session metadata per entity (writes on put, reads on every served entity)
 *
 * @template T of IEntity
 * @extends AbstractFileRepository<T>
 * @version    1.4.4
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\File
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class FilePerEntityRepository extends AbstractFileRepository
{
    /**
     * @param string $storagePath Storage directory path
     * @param class-string<T>|string|IEntitySerializer $entityClassOrSerializer Entity class or serializer
     * @param PersistenceDefinition|IEntitySerializer $persistenceDefinitionOrSerializer Persistence mode or serializer
     * @param IEntitySerializer|null $serializer Serializer
     * @param string|null $repositoryId Repository ID
     */
    public function __construct(
        string $storagePath,
        string|IEntitySerializer $entityClassOrSerializer = IEntity::class,
        PersistenceDefinition|IEntitySerializer $persistenceDefinitionOrSerializer = PersistenceDefinition::ReadWrite,
        ?IEntitySerializer $serializer = null,
        ?string $repositoryId = null
    ) {
        parent::__construct($storagePath, $entityClassOrSerializer, $persistenceDefinitionOrSerializer, $serializer, $repositoryId);

        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0777, true);
        }
    }

    /**
     * Returns the file path for an entity with the given ID.
     *
     * @param string|int $id
     * @return string
     */
    public function getFilePath(string|int $id): string
    {
        $sanitized = FileNameSanitizer::sanitize($id);
        $ext = $this->serializer->getFileExtension();
        return rtrim($this->storagePath, "/\\") . DIRECTORY_SEPARATOR . "{$sanitized}.{$ext}";
    }

    /**
     * {@inheritdoc}
     */
    public function load(): void
    {
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0777, true);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function store(): void
    {
        // In the file-per-entity model, files are already persisted individually on disk
    }

    /**
     * {@inheritdoc}
     */
    public function put(IEntity $entity): void
    {
        $this->assertWritable();

        if ($this->persistenceDefinition === PersistenceDefinition::Transient) {
            return;
        }

        $target = $this->getFilePath($entity->getEntityId());
        $content = $this->serializer->serialize($entity);
        $this->writeAtomic($target, $content);
        $this->recordWriteMetadata($entity);
    }

    /**
     * {@inheritdoc}
     */
    public function putAll(array $collectionOfEntities): void
    {
        $this->assertWritable();

        foreach ($collectionOfEntities as $entity) {
            if ($entity instanceof IEntity) {
                $this->put($entity);
            }
        }
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

        $target = $this->getFilePath($entity->getEntityId());
        if (file_exists($target)) {
            $this->withExclusiveLock($target, function () use ($target): void {
                if (file_exists($target)) {
                    @unlink($target);
                }
            });
            $this->forgetMetadata($entity);
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function removeAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        $this->assertWritable();

        $matching = $this->findAllEntitiesSpecifiedBy($specification);
        $count = 0;
        foreach ($matching as $entity) {
            if ($this->remove($entity)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        $this->assertWritable();

        $files = $this->scanEntityFiles();
        foreach ($files as $filePath) {
            $this->withExclusiveLock($filePath, function () use ($filePath): void {
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            });
        }
        $this->clearMetadata();
    }

    /**
     * {@inheritdoc}
     */
    public function findSingleEntitySpecifiedBy(ISpecification $specification): ?IEntity
    {
        if (!$this->isReadable()) {
            return null;
        }

        // O(1) optimization for UniqueEntitySpecification
        if ($specification instanceof UniqueEntitySpecification) {
            $expectedId = $specification->getExpectedId();
            $target = $this->getFilePath($expectedId);
            if (!file_exists($target)) {
                return null;
            }

            /** @var T|null $entity */
            $entity = $this->readEntityFromFile($target);
            if ($entity !== null && $specification->isSatisfiedBy($entity)) {
                $this->recordReadMetadata($entity);
                return $entity;
            }
            return null;
        }

        foreach ($this->scanEntityFiles() as $filePath) {
            /** @var T|null $entity */
            $entity = $this->readEntityFromFile($filePath);
            if ($entity !== null && $specification->isSatisfiedBy($entity)) {
                $this->recordReadMetadata($entity);
                return $entity;
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function findAllEntitiesSpecifiedBy(ISpecification $specification): array
    {
        if (!$this->isReadable()) {
            return [];
        }

        // O(1) optimization if single unique lookup
        if ($specification instanceof UniqueEntitySpecification) {
            $single = $this->findSingleEntitySpecifiedBy($specification);
            return $single !== null ? [$single] : [];
        }

        $result = [];
        foreach ($this->scanEntityFiles() as $filePath) {
            /** @var T|null $entity */
            $entity = $this->readEntityFromFile($filePath);
            if ($entity !== null && $specification->isSatisfiedBy($entity)) {
                $this->recordReadMetadata($entity);
                $result[] = $entity;
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        if (!$this->isReadable()) {
            return;
        }

        foreach ($this->scanEntityFiles() as $filePath) {
            /** @var T|null $entity */
            $entity = $this->readEntityFromFile($filePath);
            if ($entity !== null && $specification->isSatisfiedBy($entity)) {
                $this->recordReadMetadata($entity);
                yield $entity;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function countAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        return count($this->findAllEntitiesSpecifiedBy($specification));
    }

    /**
     * {@inheritdoc}
     */
    public function contains(IEntity $entity): bool
    {
        if (!$this->isReadable()) {
            return false;
        }

        $target = $this->getFilePath($entity->getEntityId());
        return file_exists($target);
    }

    /**
     * Returns the total count of files in the directory.
     *
     * @return int
     */
    public function countTotal(): int
    {
        return count($this->scanEntityFiles());
    }

    /**
     * Scans files in the storage directory matching the configured extension.
     *
     * @return array<string>
     */
    protected function scanEntityFiles(): array
    {
        if (!is_dir($this->storagePath)) {
            return [];
        }

        $ext = $this->serializer->getFileExtension();
        $pattern = rtrim($this->storagePath, "/\\") . DIRECTORY_SEPARATOR . "*.{$ext}";
        $files = glob($pattern);

        return $files !== false ? $files : [];
    }

    /**
     * Reads and deserializes an entity from file under shared lock.
     *
     * @param string $filePath
     * @return IEntity|null
     */
    protected function readEntityFromFile(string $filePath): ?IEntity
    {
        return $this->withSharedLock($filePath, function () use ($filePath): ?IEntity {
            if (!file_exists($filePath)) {
                return null;
            }
            $content = file_get_contents($filePath);
            if ($content === false || trim($content) === "") {
                return null;
            }
            /** @var T $entity */
            $entity = $this->serializer->deserialize($content, $this->entityClass);
            return $entity;
        });
    }
}
