<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\File;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Contracts\Repositories\Serialization\IEntitySerializer;
use Antevemus\ASpecification\Helpers\SpecificationHelper;

/**
 * SingleFileRepository - Entity repository persisting all entities in a single central document
 *
 * Maintains the entity collection indexed by unique identity and persists all records
 * into a single JSON document with atomic write semantics.
 *
 * Document (2.0.0): the envelope is always JSON and belongs to the repository; the serializer only
 * serializes each entity:
 *
 *     {"__meta": {"repository_id", "entity_type", "updated_at", "count"},
 *      "entities": [ {<entity>, "__persistence_metadata": {...}}, ... ]}
 *
 * An entity whose serialized form is a JSON object (JsonEntitySerializer) is embedded as that object,
 * with its persistence metadata under `__persistence_metadata`; the output of a serializer that does
 * not produce a JSON object is embedded as a JSON string, without metadata. JSON documents written by
 * 1.x (no metadata) are read as they are. The PHP-native `serialize()` envelope of 1.x
 * (PhpNativeEntitySerializer, `.bin`) is refused with a RepositoryException pointing to UPGRADE-2.0.md;
 * so is any other content that is not a JSON document of this shape (until 1.14 it was read as an
 * empty repository and overwritten by the next write).
 *
 * Features:
 * - Centralized single-file persistence
 * - Support for immediate write-through (ReadWrite) or on-demand snapshot (Snapshot)
 * - Safe reloading and shared locking for concurrent reads
 * - Persistence metadata per entity, written with the document and hydrated on load (2.0.0); reads
 *   served by this instance reach the file with its next write, store() or close()
 *
 * @template T of IEntity
 * @extends AbstractFileRepository<T>
 * @version    2.0.0
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
            // The reload takes the entities (and their persisted metadata) from disk; the records
            // this instance holds win for the entities it touched, so reads it served since its last
            // write are not lost (counters are last-writer-wins between processes).
            $session = $this->metadataMap;
            $this->load();
            foreach ($session as $id => $metadata) {
                if (isset($this->entities[$id])) {
                    $this->metadataMap[$id] = $metadata;
                }
            }
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
     *
     * Reads the JSON document: every entity is deserialized by the serializer and its
     * `__persistence_metadata`, when present, becomes its metadata record (2.0.0). The records this
     * instance held are replaced by the persisted ones (reload semantics).
     *
     * @throws RepositoryException When the file holds the PHP-native envelope of 1.x (see
     *                             UPGRADE-2.0.md) or is not a single-file JSON document
     */
    public function load(): void
    {
        if (!$this->isReadable()) {
            return;
        }

        if (!file_exists($this->storagePath)) {
            $this->entities = [];
            $this->metadataMap = [];
            $this->isLoaded = true;
            return;
        }

        $this->withSharedLock($this->lockFilePath(), function (): void {
            $content = file_get_contents($this->storagePath);
            if ($content === false || trim($content) === "") {
                $this->entities = [];
                $this->metadataMap = [];
                $this->isLoaded = true;
                return;
            }

            if (self::isPhpSerializedPayload($content)) {
                throw self::legacyNativeFormatException($this->storagePath);
            }

            $decoded = json_decode($content, true);
            if (!is_array($decoded) || !isset($decoded["entities"]) || !is_array($decoded["entities"])) {
                throw new RepositoryException(sprintf(
                    '%s is not a single-file entity document (a JSON object with an "entities" list%s); '
                    . 'refusing to read it as an empty repository.',
                    $this->storagePath,
                    json_last_error() !== JSON_ERROR_NONE ? ': ' . json_last_error_msg() : ''
                ));
            }

            $entities = [];
            $metadata = [];
            foreach ($decoded["entities"] as $rawEntityData) {
                $block = null;
                if (is_array($rawEntityData) && array_key_exists(self::PERSISTENCE_METADATA_KEY, $rawEntityData)) {
                    $block = $rawEntityData[self::PERSISTENCE_METADATA_KEY];
                    unset($rawEntityData[self::PERSISTENCE_METADATA_KEY]);
                }
                $entityJson = is_string($rawEntityData) ? $rawEntityData : json_encode($rawEntityData);
                if ($entityJson === false) {
                    continue;
                }
                /** @var T $entity */
                $entity = $this->serializer->deserialize($entityJson, $this->entityClass);
                $id = (string)$entity->getEntityId();
                $entities[$id] = $entity;
                if ($block !== null) {
                    $metadata[$id] = self::importMetadata($block, $id, $this->storagePath);
                }
            }

            $this->entities = $entities;
            $this->metadataMap = $metadata;
            $this->isLoaded = true;
        });
    }

    /**
     * {@inheritdoc}
     *
     * Writes the JSON document (the envelope belongs to the repository since 2.0.0, whatever the
     * serializer): each entity with its `__persistence_metadata` when its serialized form is a JSON
     * object, as a JSON string otherwise.
     */
    public function store(): void
    {
        $this->assertWritable();

        if ($this->persistenceDefinition === PersistenceDefinition::Transient ||
            $this->persistenceDefinition === PersistenceDefinition::MemoryOnly) {
            return;
        }

        $list = [];
        foreach ($this->entities as $id => $entity) {
            $serialized = $this->serializer->serialize($entity);
            $decodedEntity = json_decode($serialized, true);
            if (!is_array($decodedEntity) || ($decodedEntity !== [] && array_is_list($decodedEntity))) {
                // Not a JSON object: embedded as a string, there is no object to carry the metadata.
                $list[] = $serialized;
                continue;
            }
            if (isset($this->metadataMap[(string)$id])) {
                $decodedEntity[self::PERSISTENCE_METADATA_KEY] = self::exportMetadata($this->metadataMap[(string)$id]);
            }
            $list[] = $decodedEntity;
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
    }

    /**
     * {@inheritdoc}
     *
     * ReadWrite (2.0.0): the document is re-read under the exclusive lock and written back with this
     * instance's metadata records, like any write, so that closing never overwrites what another
     * instance or process wrote since this one last loaded (until 1.14 close() stored the in-memory map
     * as it was). Snapshot stores the in-memory map, as before.
     */
    public function close(): void
    {
        if ($this->persistenceDefinition === PersistenceDefinition::ReadWrite) {
            $this->mutateUnderLock(static fn(): bool => true);
            return;
        }

        parent::close();
    }

    /**
     * {@inheritdoc}
     */
    public function put(IEntity $entity): void
    {
        $this->assertWritable();

        $this->mutateUnderLock(function () use ($entity): bool {
            $this->entities[(string)$entity->getEntityId()] = $entity;
            $this->recordWriteMetadata($entity);
            return true;
        });
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
                    $this->recordWriteMetadata($entity);
                }
            }
            return true;
        });
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

        $removed = $this->mutateUnderLock(function () use ($entity): bool {
            $id = (string)$entity->getEntityId();
            if (!isset($this->entities[$id])) {
                return false;
            }
            unset($this->entities[$id]);
            $this->forgetMetadata($entity);
            return true;
        });

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
            $this->clearMetadata();
            return true;
        });
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
     *
     * Lazy over the loaded document (1.5.0, RN-04): the file is loaded once (load(), when not yet
     * loaded) and each entity is evaluated only when the consumer pulls it; nothing is collected
     * into an intermediate array. The iteration walks a snapshot of the in-memory map (PHP
     * copy-on-write), so writes during the iteration do not disturb it.
     */
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        if (!$this->isReadable()) {
            return;
        }
        $this->ensureLoaded();

        foreach ($this->entities as $entity) {
            if ($specification->isSatisfiedBy($entity)) {
                $this->recordReadMetadata($entity);
                yield $entity;
            }
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
