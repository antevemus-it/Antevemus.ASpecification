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
 * - Per-entity persistence metadata (getEntityMetaData), written next to each entity as
 *   `__persistence_metadata` and hydrated on load since 2.0.0 (forward 006 data delta, forward 020 RN-08)
 * - Files of 1.x in the PHP-native `serialize()` format (PhpNativeEntitySerializer, removed in 2.0.0)
 *   are refused with a RepositoryException pointing to the conversion recipe of UPGRADE-2.0.md
 *
 * @template T of IEntity
 * @extends AbstractRepository<T>
 * @implements IPersistentRepository<T>
 * @version    2.0.0
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

    /**
     * Key of the per-entity persistence metadata block written next to the entity data (2.0.0):
     * `{"read_count", "write_count", "first_read_at", "last_read_at", "first_write_at", "last_write_at"}`,
     * timestamps in ISO 8601 with microseconds, null when unknown.
     */
    public const PERSISTENCE_METADATA_KEY = '__persistence_metadata';

    /** Upgrade guide quoted by the exceptions raised on a file written in a format removed in 2.0.0. */
    public const UPGRADE_GUIDE = 'UPGRADE-2.0.md';

    /** Timestamp format of the persisted metadata (ISO 8601 with microseconds and offset). */
    private const METADATA_TIME_FORMAT = 'Y-m-d\\TH:i:s.uP';

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
            throw new RepositoryException("Write operation rejected: repository '{$this->repositoryId}' is in ReadOnly mode.");
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
     * Per-entity persistence metadata known to this instance, keyed by entity id: records hydrated
     * from the storage (`__persistence_metadata`, 2.0.0) and the reads and writes this instance
     * registered since. They reach the storage with the entity document (see the concrete classes).
     * Counters are statistics, not a ledger: two processes updating the same entity keep the record
     * of the last one that writes it (last writer wins).
     *
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
     * Returns the persistence metadata of the entity: the record persisted with it
     * (`__persistence_metadata`, hydrated on load since 2.0.0) plus the reads and writes registered
     * by this instance. Every write increments the write counter, the first one included (a new
     * entity has `write_count` 1 after its first put(), as in Domian; 0 before 2.0.0); every read
     * served by this instance (findAll, findSingle, iterate) increments the read counter. An entity
     * stored without metadata (a file written by 1.x) has no record until this instance reads or
     * writes it; a record opened by a read leaves the write history unknown (null timestamps,
     * `write_count` 0). Null for an entity without any record. remove()/clear() forget the record.
     *
     * {@inheritdoc}
     */
    public function getEntityMetaData(IEntity $entity): ?IEntityPersistenceMetaData
    {
        $id = (string) $entity->getEntityId();
        return $this->metadataMap[$id] ?? null;
    }

    /**
     * Forgets the metadata recorded for an entity (after remove()).
     *
     * @param IEntity $entity
     * @return void
     */
    protected function forgetMetadata(IEntity $entity): void
    {
        unset($this->metadataMap[(string) $entity->getEntityId()]);
    }

    /**
     * Forgets every metadata record (after clear()).
     *
     * @return void
     */
    protected function clearMetadata(): void
    {
        $this->metadataMap = [];
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
            // The first write counts (Domian: writeCount 1 after the first put, forward 020 RN-08).
            $now = new \DateTimeImmutable();
            $this->metadataMap[$id] = new EntityPersistenceMetaData($now);
            $this->metadataMap[$id]->registerWrite($now);
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
            // Read of an entity stored without metadata: its write history is unknown, not "now".
            $this->metadataMap[$id] = EntityPersistenceMetaData::restore(0, 0, null, null, null, null);
        }
        $this->metadataMap[$id]->registerRead();
    }

    /**
     * Exports a metadata record as the `__persistence_metadata` block.
     *
     * @param IEntityPersistenceMetaData $metadata
     * @return array{read_count: int, write_count: int, first_read_at: ?string, last_read_at: ?string, first_write_at: ?string, last_write_at: ?string}
     */
    protected static function exportMetadata(IEntityPersistenceMetaData $metadata): array
    {
        $format = static fn(?\DateTimeInterface $time): ?string => $time?->format(self::METADATA_TIME_FORMAT);

        return [
            'read_count' => $metadata->getReadCount(),
            'write_count' => $metadata->getWriteCount(),
            'first_read_at' => $format($metadata->getFirstRead()),
            'last_read_at' => $format($metadata->getLastRead()),
            'first_write_at' => $format($metadata->getFirstWrite()),
            'last_write_at' => $format($metadata->getLastWrite()),
        ];
    }

    /**
     * Rebuilds a metadata record from a persisted `__persistence_metadata` block. A malformed block
     * is refused, never replaced by an empty record in silence.
     *
     * @param mixed $block Decoded block
     * @param string $entityId Entity the block belongs to (error message)
     * @param string $source File the block was read from (error message)
     * @return EntityPersistenceMetaData
     * @throws RepositoryException When the block is not an object of the expected fields and types
     */
    protected static function importMetadata(mixed $block, string $entityId, string $source): EntityPersistenceMetaData
    {
        $fail = static fn(string $detail): RepositoryException => new RepositoryException(sprintf(
            'Invalid %s of entity "%s" in %s: %s.',
            self::PERSISTENCE_METADATA_KEY,
            $entityId,
            $source,
            $detail
        ));

        if (!is_array($block)) {
            throw $fail('expected an object, got ' . get_debug_type($block));
        }

        $counters = [];
        foreach (['read_count', 'write_count'] as $key) {
            $value = $block[$key] ?? 0;
            if (!is_int($value) || $value < 0) {
                throw $fail("{$key} must be a non-negative integer");
            }
            $counters[$key] = $value;
        }

        $times = [];
        foreach (['first_read_at', 'last_read_at', 'first_write_at', 'last_write_at'] as $key) {
            $value = $block[$key] ?? null;
            if ($value === null) {
                $times[$key] = null;
                continue;
            }
            if (!is_string($value)) {
                throw $fail("{$key} must be an ISO 8601 timestamp or null");
            }
            try {
                $times[$key] = new \DateTimeImmutable($value);
            } catch (\Exception) {
                throw $fail("{$key} \"{$value}\" is not a readable timestamp");
            }
        }

        return EntityPersistenceMetaData::restore(
            $counters['read_count'],
            $counters['write_count'],
            $times['first_read_at'],
            $times['last_read_at'],
            $times['first_write_at'],
            $times['last_write_at']
        );
    }

    /**
     * Tells whether a stored content is a PHP-native `serialize()` payload: the `.bin` format of
     * PhpNativeEntitySerializer, and the single-file envelope that 1.x wrote for every serializer whose
     * extension was not `json`.
     *
     * @param string $content
     * @return bool
     */
    protected static function isPhpSerializedPayload(string $content): bool
    {
        return preg_match('/\A\s*(?:a:\d+:\{|O:\d+:"|C:\d+:"|E:\d+:"|s:\d+:"|i:-?\d+;|d:[^;]+;|b:[01];|N;)/', $content) === 1;
    }

    /**
     * Exception for a file written in the PHP-native format removed in 2.0.0: it names the file and
     * points to the conversion recipe, which runs on ASpecification 1.14.x (the last version that
     * reads both formats), before the upgrade.
     *
     * @param string $path File that holds the PHP-native payload
     * @return RepositoryException
     */
    protected static function legacyNativeFormatException(string $path): RepositoryException
    {
        return new RepositoryException(sprintf(
            '%s holds the PHP serialize() format of 1.x (written by PhpNativeEntitySerializer, or the envelope of a '
            . 'SingleFileRepository whose serializer extension was not "json"), removed in 2.0.0 (the library no longer '
            . 'reads PHP-serialized data). Convert it to JSON with ASpecification 1.14.x, which reads both formats, '
            . 'before upgrading: see %s, "Converting .bin files to JSON".',
            $path,
            self::UPGRADE_GUIDE
        ));
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
                throw new RepositoryException("Could not create the destination directory: {$dir}");
            }
        }

        $tmpFile = $targetFile . ".tmp." . bin2hex(random_bytes(4));

        $this->withExclusiveLock($lockPath ?? $targetFile, function () use ($tmpFile, $targetFile, $content): void {
            $handle = @fopen($tmpFile, "wb");
            if ($handle === false) {
                throw new RepositoryException("Failed to open the temporary file descriptor: {$tmpFile}");
            }

            $bytesWritten = fwrite($handle, $content);
            fflush($handle);
            fclose($handle);

            if ($bytesWritten === false || $bytesWritten !== strlen($content)) {
                @unlink($tmpFile);
                throw new RepositoryException("Failed to write bytes to the temporary file: {$tmpFile}");
            }

            if (!@rename($tmpFile, $targetFile)) {
                @unlink($tmpFile);
                throw new RepositoryException("Failed to move the temporary file to its final destination: {$targetFile}");
            }
        });
    }
}
