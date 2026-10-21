<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\File;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\IEntityPersistenceMetaData;
use Antevemus\ASpecification\Contracts\Repositories\IWriteBehindBackend;
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;
use Antevemus\ASpecification\Contracts\Repositories\Serialization\IEntitySerializer;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Helpers\SpecificationHelper;
use Antevemus\ASpecification\Repositories\RepositoryPersistenceMetaData;

/**
 * FilePerEntityRepository - Entity repository persisting one file per entity
 *
 * Organizes entities into a directory of individual files keyed by unique entity ID
 * (<storageDir>/<sanitizedId>.<ext>), providing direct O(1) lookups for UniqueEntitySpecification.
 *
 * File content (2.0.0): the serialized entity; when it is a JSON object (JsonEntitySerializer), the
 * repository adds the entity's persistence metadata under `__persistence_metadata` (forward 006 data
 * delta). Files written by 1.x carry no metadata and are read as they are. A `.bin` file of the
 * PHP-native serializer of 1.x (removed in 2.0.0) found in the directory is refused with a
 * RepositoryException pointing to UPGRADE-2.0.md, never skipped as if the entity did not exist.
 *
 * Features:
 * - Partitioned persistence via individual files
 * - O(1) optimized direct access by unique key
 * - On-demand filesystem iteration: iterate*() reads the directory entry by entry (readdir), never the whole listing
 * - Persistence metadata per entity, written with the entity on put() and hydrated from its file
 *   (2.0.0); reads served by this instance reach the files on store() or close()
 * - Backend of the write-behind hybrid (IWriteBehindBackend, 2.8.0): persistSnapshot() writes every
 *   entity of the working set (each file atomically: a temporary file renamed over it), deletes the files
 *   of the entities absent from it, and stores the repository record in `_repository.json` next to them.
 *   The replacement is atomic per file, not for the whole directory. `_repository.json` is the record only
 *   when it holds `__repository_metadata` (it is then never listed as an entity, and an entity whose file
 *   would be it is refused); a directory without it behaves as before
 *
 * @template T of IEntity
 * @extends AbstractFileRepository<T>
 * @implements IWriteBehindBackend<T>
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\File
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class FilePerEntityRepository extends AbstractFileRepository implements IWriteBehindBackend
{
    /** Extension of the files written by the PHP-native serializer of 1.x (removed in 2.0.0). */
    private const LEGACY_NATIVE_EXTENSION = 'bin';

    /** File of the repository record of the write-behind hybrid, next to the entity files (2.8.0). */
    public const REPOSITORY_RECORD_FILE = '_repository.json';

    /**
     * Record of the write-behind repository read by load() or stored by persistSnapshot() (2.8.0).
     */
    private ?RepositoryPersistenceMetaData $repositoryMetaData = null;

    /**
     * Ids whose metadata record changed in this instance after the entity was last written
     * (reads served), flushed to the entity files by store().
     *
     * @var array<string, true>
     */
    private array $unflushedMetadata = [];

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
        $this->repositoryMetaData = $this->readRepositoryRecord();
    }

    /**
     * Path of the repository record file (`_repository.json` in the storage directory).
     *
     * @return string
     */
    public function getRepositoryRecordPath(): string
    {
        return rtrim($this->storagePath, "/\\") . DIRECTORY_SEPARATOR . self::REPOSITORY_RECORD_FILE;
    }

    /**
     * {@inheritdoc}
     *
     * Writes each entity of the working set (one atomic write per file), deletes the files of the
     * entities absent from it, then writes `_repository.json`. When a write fails the files written
     * before it keep their new content (the replacement is atomic per file) and this instance keeps its
     * previous repository record.
     *
     * @throws RepositoryException In ReadOnly mode, or when a file cannot be written
     */
    public function persistSnapshot(array $entities, array $writtenIds, RepositoryPersistenceMetaData $metaData): void
    {
        $this->assertWritable();

        if ($this->persistenceDefinition === PersistenceDefinition::Transient
            || $this->persistenceDefinition === PersistenceDefinition::MemoryOnly) {
            return;
        }

        $written = array_fill_keys(array_map('strval', $writtenIds), true);
        $kept = [];
        foreach ($entities as $entity) {
            if (!$entity instanceof IEntity) {
                continue;
            }
            $id = (string) $entity->getEntityId();
            $target = $this->getFilePath($id);
            $kept[$target] = true;
            if (!isset($this->metadataMap[$id])) {
                $this->hydrateMetadataFromFile($id);
            }
            if (isset($written[$id]) || !isset($this->metadataMap[$id])) {
                $this->writeEntityFile($entity, $target);
                continue;
            }
            $this->writeEntityFile($entity, $target, false);
        }

        foreach ($this->scanEntityFiles() as $filePath) {
            if (!isset($kept[$filePath])) {
                $this->withExclusiveLock($filePath, static function () use ($filePath): void {
                    if (file_exists($filePath)) {
                        @unlink($filePath);
                    }
                });
            }
        }
        foreach (array_keys($this->metadataMap) as $id) {
            if (!isset($kept[$this->getFilePath((string) $id)])) {
                unset($this->metadataMap[$id], $this->unflushedMetadata[$id]);
            }
        }

        $this->writeAtomic(
            $this->getRepositoryRecordPath(),
            $this->encodeDocument([RepositoryPersistenceMetaData::KEY => $metaData->toArray()])
        );
        $this->repositoryMetaData = $metaData;
    }

    /**
     * {@inheritdoc}
     */
    public function getRepositoryMetaData(): ?RepositoryPersistenceMetaData
    {
        return $this->repositoryMetaData;
    }

    /**
     * {@inheritdoc}
     */
    public function store(): void
    {
        // Entities are written by put(); what store() still has to write is the metadata of the
        // entities this instance served since their last write (2.0.0).
        if ($this->persistenceDefinition === PersistenceDefinition::ReadOnly
            || $this->persistenceDefinition === PersistenceDefinition::Transient
            || $this->persistenceDefinition === PersistenceDefinition::MemoryOnly) {
            $this->unflushedMetadata = [];
            return;
        }

        foreach (array_keys($this->unflushedMetadata) as $id) {
            $id = (string) $id;
            $target = $this->getFilePath($id);
            $metadata = $this->metadataMap[$id] ?? null;
            if ($metadata === null || !file_exists($target)) {
                continue;
            }
            $content = @file_get_contents($target);
            $document = is_string($content) ? json_decode($content, true) : null;
            if (!is_array($document) || ($document !== [] && array_is_list($document))) {
                continue; // not a JSON object: this serializer's files carry no metadata
            }
            $document[self::PERSISTENCE_METADATA_KEY] = self::exportMetadata($metadata);
            $this->writeAtomic($target, $this->encodeDocument($document));
        }
        $this->unflushedMetadata = [];
    }

    /**
     * {@inheritdoc}
     *
     * Reads the record persisted in the entity's file when this instance holds none (2.0.0).
     */
    public function getEntityMetaData(IEntity $entity): ?IEntityPersistenceMetaData
    {
        $id = (string) $entity->getEntityId();
        if (!isset($this->metadataMap[$id]) && $this->isReadable()) {
            $this->hydrateMetadataFromFile($id);
        }

        return $this->metadataMap[$id] ?? null;
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

        $id = (string) $entity->getEntityId();
        $target = $this->getFilePath($id);
        if (!isset($this->metadataMap[$id]) && $this->isReadable()) {
            // The record persisted with the previous version of the entity keeps counting.
            $this->hydrateMetadataFromFile($id);
        }
        $this->writeEntityFile($entity, $target);
    }

    /**
     * Writes the file of an entity with its persistence metadata (when its serialized form is a JSON
     * object), counting one write in its record unless $countWrite is false.
     *
     * @param IEntity $entity
     * @param string $target File of the entity
     * @param bool $countWrite Whether the write counts in the entity's record
     * @return void
     * @throws RepositoryException When the file would be the repository record, or cannot be written
     */
    private function writeEntityFile(IEntity $entity, string $target, bool $countWrite = true): void
    {
        if ($target === $this->getRepositoryRecordPath() && $this->isRepositoryRecordFile($target)) {
            throw new RepositoryException(sprintf(
                'The entity "%s" cannot be stored: its file would be %s, the repository record of %s.',
                (string) $entity->getEntityId(),
                self::REPOSITORY_RECORD_FILE,
                $this->storagePath
            ));
        }

        $id = (string) $entity->getEntityId();
        if ($countWrite || !isset($this->metadataMap[$id])) {
            $this->recordWriteMetadata($entity);
        }

        $content = $this->serializer->serialize($entity);
        $document = json_decode($content, true);
        if (is_array($document) && ($document === [] || !array_is_list($document))) {
            $document[self::PERSISTENCE_METADATA_KEY] = self::exportMetadata($this->metadataMap[$id]);
            $content = $this->encodeDocument($document);
        }
        $this->writeAtomic($target, $content);
        unset($this->unflushedMetadata[$id]);
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
            unset($this->unflushedMetadata[(string) $entity->getEntityId()]);
            return true;
        }

        $this->assertNoLegacyNativeFile($entity->getEntityId());

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
        $this->unflushedMetadata = [];
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
                $this->assertNoLegacyNativeFile($expectedId);
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
     *
     * Lazy file by file (1.5.0, RN-04): the directory is read entry by entry (readdir), each file is
     * read and deserialized only when the consumer pulls the next entity, and the directory listing
     * is never collected into an array. The order is the directory order of the filesystem (not
     * sorted, unlike findAllEntitiesSpecifiedBy()).
     */
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        if (!$this->isReadable()) {
            return;
        }

        foreach ($this->iterateEntityFiles() as $filePath) {
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
        if (file_exists($target)) {
            return true;
        }
        $this->assertNoLegacyNativeFile($entity->getEntityId());

        return false;
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

        // One listing serves both the entity files (`*.<ext>`, sorted, hidden entries excluded) and
        // the refusal of `.bin` files left by the PHP-native serializer of 1.x (2.0.0).
        $suffix = '.' . $this->serializer->getFileExtension();
        $legacySuffix = '.' . self::LEGACY_NATIVE_EXTENSION;
        $entries = glob(rtrim($this->storagePath, "/\\") . DIRECTORY_SEPARATOR . '*');

        $files = [];
        foreach ($entries !== false ? $entries : [] as $path) {
            if (str_ends_with($path, $suffix)) {
                if ($this->isRepositoryRecordFile($path)) {
                    continue;
                }
                $files[] = $path;
            } elseif ($suffix !== $legacySuffix && str_ends_with($path, $legacySuffix) && is_file($path)) {
                throw self::legacyNativeFormatException($path);
            }
        }

        return $files;
    }

    /**
     * Lazily yields the entity files of the storage directory, one directory entry at a time
     * (opendir/readdir), without building the listing: the same files scanEntityFiles() returns
     * (`*.<ext>`, hidden entries excluded), in directory order.
     *
     * @return \Generator<int, string>
     */
    protected function iterateEntityFiles(): \Generator
    {
        if (!is_dir($this->storagePath)) {
            return;
        }

        $handle = @opendir($this->storagePath);
        if ($handle === false) {
            return;
        }

        $directory = rtrim($this->storagePath, "/\\") . DIRECTORY_SEPARATOR;
        $suffix = '.' . $this->serializer->getFileExtension();

        try {
            $legacySuffix = '.' . self::LEGACY_NATIVE_EXTENSION;
            $checkLegacy = $suffix !== $legacySuffix;
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '' || $entry[0] === '.') {
                    continue;
                }
                if ($checkLegacy && str_ends_with($entry, $legacySuffix) && is_file($directory . $entry)) {
                    throw self::legacyNativeFormatException($directory . $entry);
                }
                if (!str_ends_with($entry, $suffix)) {
                    continue;
                }
                $path = $directory . $entry;
                if (is_file($path) && !$this->isRepositoryRecordFile($path)) {
                    yield $path;
                }
            }
        } finally {
            closedir($handle);
        }
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
            [$content, $block] = $this->splitMetadata($content);
            /** @var T $entity */
            $entity = $this->serializer->deserialize($content, $this->entityClass);
            $id = (string) $entity->getEntityId();
            if ($block !== null && !isset($this->metadataMap[$id])) {
                $this->metadataMap[$id] = self::importMetadata($block, $id, $filePath);
            }
            return $entity;
        });
    }

    /**
     * {@inheritdoc}
     *
     * The read reaches the entity's file on the next store() or close().
     */
    protected function recordReadMetadata(IEntity $entity): void
    {
        parent::recordReadMetadata($entity);
        $this->unflushedMetadata[(string) $entity->getEntityId()] = true;
    }

    /**
     * Separates the `__persistence_metadata` block from an entity document, so that the serializer
     * only sees the entity.
     *
     * @param string $content File content
     * @return array{0: string, 1: mixed} Entity content and the metadata block (null when absent)
     */
    private function splitMetadata(string $content): array
    {
        if (!str_contains($content, '"' . self::PERSISTENCE_METADATA_KEY . '"')) {
            return [$content, null];
        }
        $document = json_decode($content, true);
        if (!is_array($document) || !array_key_exists(self::PERSISTENCE_METADATA_KEY, $document)) {
            return [$content, null];
        }
        $block = $document[self::PERSISTENCE_METADATA_KEY];
        unset($document[self::PERSISTENCE_METADATA_KEY]);

        return [$this->encodeDocument($document), $block];
    }

    /**
     * Loads the metadata record persisted in the file of an entity, when the file carries one.
     *
     * @param string $id Entity id
     * @return void
     */
    private function hydrateMetadataFromFile(string $id): void
    {
        $target = $this->getFilePath($id);
        if (!file_exists($target)) {
            return;
        }
        $content = $this->withSharedLock($target, static fn(): string|false => @file_get_contents($target));
        if (!is_string($content)) {
            return;
        }
        [, $block] = $this->splitMetadata($content);
        if ($block !== null) {
            $this->metadataMap[$id] = self::importMetadata($block, $id, $target);
        }
    }

    /**
     * Tells whether a file of the storage directory is the repository record: named `_repository.json`
     * and holding `__repository_metadata`. Any other content under that name is an entity file, as before
     * 2.8.0.
     *
     * @param string $path
     * @return bool
     */
    private function isRepositoryRecordFile(string $path): bool
    {
        if (basename($path) !== self::REPOSITORY_RECORD_FILE || !is_file($path)) {
            return false;
        }
        $content = @file_get_contents($path);
        if (!is_string($content) || !str_contains($content, '"' . RepositoryPersistenceMetaData::KEY . '"')) {
            return false;
        }
        $document = json_decode($content, true);

        return is_array($document) && array_key_exists(RepositoryPersistenceMetaData::KEY, $document);
    }

    /**
     * Reads the repository record (`_repository.json`), null when the directory holds none.
     *
     * @return RepositoryPersistenceMetaData|null
     * @throws RepositoryException When the record is malformed
     */
    private function readRepositoryRecord(): ?RepositoryPersistenceMetaData
    {
        $path = $this->getRepositoryRecordPath();
        if (!$this->isRepositoryRecordFile($path)) {
            return null;
        }
        $document = json_decode((string) $this->withSharedLock($path, static fn(): string|false => @file_get_contents($path)), true);

        return RepositoryPersistenceMetaData::fromArray($document[RepositoryPersistenceMetaData::KEY] ?? null, $path);
    }

    /**
     * Encodes an entity document the way JsonEntitySerializer does.
     *
     * @param array<array-key, mixed> $document
     * @return string
     * @throws RepositoryException
     */
    private function encodeDocument(array $document): string
    {
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RepositoryException('Failed to encode the entity document: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Refuses the `.bin` file of an entity written by the PHP-native serializer of 1.x when this
     * repository reads another extension: the entity exists, in a format 2.0.0 no longer reads.
     *
     * @param string|int $id Entity id
     * @return void
     * @throws RepositoryException
     */
    private function assertNoLegacyNativeFile(string|int $id): void
    {
        if ($this->serializer->getFileExtension() === self::LEGACY_NATIVE_EXTENSION) {
            return;
        }
        $legacy = rtrim($this->storagePath, "/\\") . DIRECTORY_SEPARATOR
            . FileNameSanitizer::sanitize($id) . '.' . self::LEGACY_NATIVE_EXTENSION;
        if (is_file($legacy)) {
            throw self::legacyNativeFormatException($legacy);
        }
    }
}
