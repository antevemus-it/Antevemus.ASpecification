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
 * - Session metadata per entity (writes on put/putAll, reads on every served entity)
 *
 * @template T of IEntity
 * @extends AbstractFileRepository<T>
 * @version    1.4.0
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
     * Stable lock file that serializes every read-modify-write section on the
     * document. It is deliberately NOT the document itself: writeAtomic() replaces
     * the document by rename(), so a lock taken on the document guards an inode
     * that the next writer no longer opens (BUG-20261007-7RZJ, lost update).
     *
     * @return string
     */
    protected function lockFilePath(): string
    {
        return $this->storagePath . '.lock';
    }

    /**
     * Runs a mutation of the in-memory map as one read-modify-write section.
     *
     * In ReadWrite mode the section holds the exclusive lock, re-reads the document
     * from disk (another process may have changed it since this instance loaded),
     * applies the mutation and stores the merged map while still holding the lock.
     * In the other modes (Snapshot, WriteOnly, MemoryOnly, Transient) the document
     * is written only on store()/close(), so the mutation only touches memory.
     *
     * @template R
     * @param callable(): R $mutation Changes $this->entities; returns true when the
     *                                 document must be rewritten (or any value)
     * @return R
     */
    private function mutateUnderLock(callable $mutation): mixed
    {
        if ($this->persistenceDefinition !== PersistenceDefinition::ReadWrite) {
            $this->ensureLoaded();
            return $mutation();
        }

        return $this->withExclusiveLock($this->lockFilePath(), function () use ($mutation): mixed {
            $this->load();
            $result = $mutation();
            if ($result !== false) {
                $this->store();
            }
            return $result;
        });
    }

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

        $this->withSharedLock($this->lockFilePath(), function (): void {
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
                // The envelope only carries arrays, scalars and the serialized entity strings.
                // Entities are reconstructed below through the serializer whitelist; the envelope
                // itself must never instantiate a class (BUG-20261007-HIJG).
                $data = @unserialize($content, ["allowed_classes" => false]);
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
                throw new RepositoryException("Failed to encode the single entity document: " . json_last_error_msg());
            }

            $this->writeAtomic($this->storagePath, $encoded, $this->lockFilePath());
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

            $this->writeAtomic($this->storagePath, serialize($payload), $this->lockFilePath());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function put(IEntity $entity): void
    {
        $this->assertWritable();

        $this->mutateUnderLock(function () use ($entity): bool {
            $this->entities[(string)$entity->getEntityId()] = $entity;
            return true;
        });
        $this->recordWriteMetadata($entity);
    }

    /**
     * {@inheritdoc}
     */
    public function putAll(array $collectionOfEntities): void
    {
        $this->assertWritable();

        $this->mutateUnderLock(function () use ($collectionOfEntities): bool {
            foreach ($collectionOfEntities as $entity) {
                if ($entity instanceof IEntity) {
                    $this->entities[(string)$entity->getEntityId()] = $entity;
                }
            }
            return true;
        });
        foreach ($collectionOfEntities as $entity) {
            if ($entity instanceof IEntity) {
                $this->recordWriteMetadata($entity);
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
        $this->put($entity);
    }

    /**
     * {@inheritdoc}
     */
    public function remove(IEntity $entity): bool
    {
        $this->assertWritable();

        $removed = $this->mutateUnderLock(function () use ($entity): bool {
            $id = (string)$entity->getEntityId();
            if (!isset($this->entities[$id])) {
                return false;
            }
            unset($this->entities[$id]);
            return true;
        });
        $this->forgetMetadata($entity);

        return $removed;
    }

    /**
     * {@inheritdoc}
     */
    public function removeAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        $this->assertWritable();

        $removed = $this->mutateUnderLock(function () use ($specification): int|false {
            $count = 0;
            foreach ($this->entities as $id => $entity) {
                if ($specification->isSatisfiedBy($entity)) {
                    unset($this->entities[$id]);
                    $this->forgetMetadata($entity);
                    $count++;
                }
            }
            return $count > 0 ? $count : false;
        });

        return $removed === false ? 0 : $removed;
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        $this->assertWritable();

        $this->mutateUnderLock(function (): bool {
            $this->entities = [];
            return true;
        });
        $this->clearMetadata();
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
                $this->recordReadMetadata($entity);
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
