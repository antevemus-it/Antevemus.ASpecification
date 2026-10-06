<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\File;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Contracts\Repositories\Serialization\IEntitySerializer;

/**
 * SingleFileRepository - Entity repository persisting all entities in a single central document
 *
 * Maintains the entity collection indexed by unique identity and persists all records
 * into a single document (JSON, XML, or binary) with atomic write semantics.
 *
 * Features:
 * - Centralized single-file persistence
 * - Support for immediate write-through (ReadWrite) or on-demand snapshot (Snapshot)
 * - Safe reloading and shared locking for concurrent reads
 *
 * @template T of IEntity
 * @extends AbstractFileRepository<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\File
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class SingleFileRepository extends AbstractFileRepository
{
    /**
     * @var array<string, T> In-memory map of [id => entity]
     */
    protected array $entities = [];

    /**
     * Indicates whether the file has already been loaded into memory.
     */
    protected bool $isLoaded = false;

    /**
     * @param string $storagePath Single file path (e.g. storage/orders.json)
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

        if (file_exists($this->storagePath) && $this->isReadable()) {
            $this->load();
        } else {
            $this->isLoaded = true;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function load(): void
    {
        if (!$this->isReadable()) {
            return;
        }

        if (!file_exists($this->storagePath)) {
            $this->entities = [];
            $this->isLoaded = true;
            return;
        }

        $this->withSharedLock($this->storagePath, function (): void {
            $content = file_get_contents($this->storagePath);
            if ($content === false || trim($content) === "") {
                $this->entities = [];
                $this->isLoaded = true;
                return;
            }

            $this->entities = [];

            if ($this->serializer->getFileExtension() === 'json') {
                $decoded = json_decode($content, true);
                if (is_array($decoded) && isset($decoded["entities"]) && is_array($decoded["entities"])) {
                    foreach ($decoded["entities"] as $rawEntityData) {
                        $entityJson = is_string($rawEntityData) ? $rawEntityData : json_encode($rawEntityData);
                        if ($entityJson !== false) {
                            /** @var T $entity */
                            $entity = $this->serializer->deserialize($entityJson, $this->entityClass);
                            $this->entities[(string)$entity->getEntityId()] = $entity;
                        }
                    }
                }
            } else {
                $data = @unserialize($content, ["allowed_classes" => true]);
                if (is_array($data) && isset($data["entities"]) && is_array($data["entities"])) {
                    foreach ($data["entities"] as $serializedEntity) {
                        if (is_string($serializedEntity)) {
                            /** @var T $entity */
                            $entity = $this->serializer->deserialize($serializedEntity, $this->entityClass);
                            $this->entities[(string)$entity->getEntityId()] = $entity;
                        }
                    }
                }
            }
            $this->isLoaded = true;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function store(): void
    {
        $this->assertWritable();

        if ($this->persistenceDefinition === PersistenceDefinition::Transient ||
            $this->persistenceDefinition === PersistenceDefinition::MemoryOnly) {
            return;
        }

        if ($this->serializer->getFileExtension() === 'json') {
            $list = [];
            foreach ($this->entities as $entity) {
                $json = $this->serializer->serialize($entity);
                $decodedEntity = json_decode($json, true);
                $list[] = $decodedEntity !== null ? $decodedEntity : $json;
            }

            $payload = [
                "__meta" => [
                    "repository_id" => $this->repositoryId,
                    "entity_type" => $this->entityClass,
                    "updated_at" => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    "count" => count($list),
                ],
                "entities" => $list,
            ];

            $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                throw new RepositoryException("Falha ao codificar documento único de entidades: " . json_last_error_msg());
            }

            $this->writeAtomic($this->storagePath, $encoded);
        } else {
            $serializedList = [];
            foreach ($this->entities as $entity) {
                $serializedList[] = $this->serializer->serialize($entity);
            }

            $payload = [
                "__meta" => [
                    "repository_id" => $this->repositoryId,
                    "entity_type" => $this->entityClass,
                    "updated_at" => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    "count" => count($serializedList),
                ],
                "entities" => $serializedList,
            ];

            $this->writeAtomic($this->storagePath, serialize($payload));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function put(IEntity $entity): void
    {
        $this->assertWritable();
        $this->ensureLoaded();

        $this->entities[(string)$entity->getEntityId()] = $entity;

        if ($this->persistenceDefinition === PersistenceDefinition::ReadWrite) {
            $this->store();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function putAll(array $collectionOfEntities): void
    {
        $this->assertWritable();
        $this->ensureLoaded();

        foreach ($collectionOfEntities as $entity) {
            if ($entity instanceof IEntity) {
                $this->entities[(string)$entity->getEntityId()] = $entity;
            }
        }

        if ($this->persistenceDefinition === PersistenceDefinition::ReadWrite) {
            $this->store();
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
        $this->put($entity);
    }

    /**
     * {@inheritdoc}
     */
    public function remove(IEntity $entity): bool
    {
        $this->assertWritable();
        $this->ensureLoaded();

        $id = (string)$entity->getEntityId();
        if (isset($this->entities[$id])) {
            unset($this->entities[$id]);
            if ($this->persistenceDefinition === PersistenceDefinition::ReadWrite) {
                $this->store();
            }
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
        $this->ensureLoaded();

        $count = 0;
        foreach ($this->entities as $id => $entity) {
            if ($specification->isSatisfiedBy($entity)) {
                unset($this->entities[$id]);
                $count++;
            }
        }

        if ($count > 0 && $this->persistenceDefinition === PersistenceDefinition::ReadWrite) {
            $this->store();
        }

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        $this->assertWritable();
        $this->entities = [];

        if ($this->persistenceDefinition === PersistenceDefinition::ReadWrite) {
            $this->store();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findAllEntitiesSpecifiedBy(ISpecification $specification): array
    {
        if (!$this->isReadable()) {
            return [];
        }
        $this->ensureLoaded();

        $result = [];
        foreach ($this->entities as $entity) {
            if ($specification->isSatisfiedBy($entity)) {
                $result[] = $entity;
            }
        }

        return $result;
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
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        foreach ($this->findAllEntitiesSpecifiedBy($specification) as $entity) {
            yield $entity;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function contains(IEntity $entity): bool
    {
        if (!$this->isReadable()) {
            return false;
        }
        $this->ensureLoaded();

        return isset($this->entities[(string)$entity->getEntityId()]);
    }

    /**
     * Returns the total count of entities in memory.
     *
     * @return int
     */
    public function countTotal(): int
    {
        $this->ensureLoaded();
        return count($this->entities);
    }

    /**
     * Ensures the file is loaded into memory prior to any operation.
     */
    private function ensureLoaded(): void
    {
        if (!$this->isLoaded) {
            $this->load();
        }
    }
}
