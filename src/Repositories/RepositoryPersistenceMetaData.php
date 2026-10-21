<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\IEntityPersistenceMetaData;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * RepositoryPersistenceMetaData - Persistence record of a whole write-behind repository
 *
 * The record Domian keeps for an INMEMORY_AND_FILE repository: one record for the repository, not one per
 * entity. A read is a load() (the working set read from the storage), a write is a persist() (the working
 * set written to it); put(), find() and the other operations on the working set do not touch it. The
 * write-behind hybrid (InMemoryAndFileRepository in MemoryAsyncFile or MemoryAsyncDelegated) keeps it, and
 * the file backends store it next to the entities:
 *
 *     {"__repository_metadata": {"format_version": 1, "read_count", "write_count",
 *                                "first_read_at", "last_read_at", "first_write_at", "last_write_at"}}
 *
 * (a key of the single-file document; the `_repository.json` file of a FilePerEntityRepository directory).
 * Timestamps in ISO 8601 with microseconds, null when the event never happened. A storage without the key
 * (written by a write-through repository, or before 2.8.0) has no record: the first load() starts one.
 *
 * Features:
 * - Read (load) and write (persist) counters, first and last of each
 * - getLastLoad() / getLastPersist(), names of the write-behind vocabulary for the last read and write
 * - Format version of the stored record; a record of a newer format is refused, never misread
 * - Export to and validated import from the stored block
 *
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class RepositoryPersistenceMetaData implements IEntityPersistenceMetaData
{
    /** Key of the record in the stored document. */
    public const KEY = '__repository_metadata';

    /** Format of the record written by this version. */
    public const FORMAT_VERSION = 1;

    /** Timestamp format of the stored record (ISO 8601 with microseconds and offset). */
    private const TIME_FORMAT = 'Y-m-d\\TH:i:s.uP';

    private int $readCount = 0;
    private int $writeCount = 0;
    private ?DateTimeInterface $firstRead = null;
    private ?DateTimeInterface $lastRead = null;
    private ?DateTimeInterface $firstWrite = null;
    private ?DateTimeInterface $lastWrite = null;
    private int $formatVersion = self::FORMAT_VERSION;

    /**
     * A record of a repository never loaded nor persisted: counters at zero, no timestamp.
     */
    public function __construct()
    {
    }

    /**
     * Rebuilds a record from stored values, without registering any read or write.
     *
     * @param int $readCount Loads registered so far (>= 0)
     * @param int $writeCount Persists registered so far (>= 0)
     * @param DateTimeInterface|null $firstRead First load, null when never loaded
     * @param DateTimeInterface|null $lastRead Last load, null when never loaded
     * @param DateTimeInterface|null $firstWrite First persist, null when never persisted
     * @param DateTimeInterface|null $lastWrite Last persist, null when never persisted
     * @param int $formatVersion Format of the stored record (1 to FORMAT_VERSION)
     * @return self
     * @throws \InvalidArgumentException When a counter is negative or the format is unknown
     */
    public static function restore(
        int $readCount,
        int $writeCount,
        ?DateTimeInterface $firstRead,
        ?DateTimeInterface $lastRead,
        ?DateTimeInterface $firstWrite,
        ?DateTimeInterface $lastWrite,
        int $formatVersion = self::FORMAT_VERSION
    ): self {
        if ($readCount < 0 || $writeCount < 0) {
            throw new \InvalidArgumentException(sprintf(
                'Repository persistence metadata counters cannot be negative (read_count %d, write_count %d).',
                $readCount,
                $writeCount
            ));
        }
        if ($formatVersion < 1 || $formatVersion > self::FORMAT_VERSION) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown repository persistence metadata format %d (this version reads 1 to %d).',
                $formatVersion,
                self::FORMAT_VERSION
            ));
        }

        $record = new self();
        $record->readCount = $readCount;
        $record->writeCount = $writeCount;
        $record->firstRead = $firstRead;
        $record->lastRead = $lastRead;
        $record->firstWrite = $firstWrite;
        $record->lastWrite = $lastWrite;
        $record->formatVersion = $formatVersion;

        return $record;
    }

    /**
     * Rebuilds a record from the stored block. A malformed block is refused, never replaced by an empty
     * record in silence.
     *
     * @param mixed $block Decoded `__repository_metadata` block
     * @param string $source File the block was read from (error message)
     * @return self
     * @throws RepositoryException When the block is not an object of the expected fields, types and format
     */
    public static function fromArray(mixed $block, string $source): self
    {
        $fail = static fn(string $detail): RepositoryException => new RepositoryException(sprintf(
            'Invalid %s in %s: %s.',
            self::KEY,
            $source,
            $detail
        ));

        if (!is_array($block)) {
            throw $fail('expected an object, got ' . get_debug_type($block));
        }

        $integers = [];
        foreach (['format_version' => self::FORMAT_VERSION, 'read_count' => 0, 'write_count' => 0] as $key => $default) {
            $value = $block[$key] ?? $default;
            if (!is_int($value) || $value < 0) {
                throw $fail("{$key} must be a non-negative integer");
            }
            $integers[$key] = $value;
        }
        if ($integers['format_version'] < 1 || $integers['format_version'] > self::FORMAT_VERSION) {
            throw $fail(sprintf(
                'format_version %d is not readable by this version (1 to %d)',
                $integers['format_version'],
                self::FORMAT_VERSION
            ));
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
                $times[$key] = new DateTimeImmutable($value);
            } catch (\Exception) {
                throw $fail("{$key} \"{$value}\" is not a readable timestamp");
            }
        }

        return self::restore(
            $integers['read_count'],
            $integers['write_count'],
            $times['first_read_at'],
            $times['last_read_at'],
            $times['first_write_at'],
            $times['last_write_at'],
            $integers['format_version']
        );
    }

    /**
     * The block stored under KEY, always in the current format.
     *
     * @return array{format_version: int, read_count: int, write_count: int, first_read_at: ?string, last_read_at: ?string, first_write_at: ?string, last_write_at: ?string}
     */
    public function toArray(): array
    {
        $format = static fn(?DateTimeInterface $time): ?string => $time?->format(self::TIME_FORMAT);

        return [
            'format_version' => self::FORMAT_VERSION,
            'read_count' => $this->readCount,
            'write_count' => $this->writeCount,
            'first_read_at' => $format($this->firstRead),
            'last_read_at' => $format($this->lastRead),
            'first_write_at' => $format($this->firstWrite),
            'last_write_at' => $format($this->lastWrite),
        ];
    }

    /**
     * Format of the record as it was read (FORMAT_VERSION for a record created by this version).
     */
    public function getFormatVersion(): int
    {
        return $this->formatVersion;
    }

    /**
     * Instant of the last load(), null when the repository was never loaded.
     */
    public function getLastLoad(): ?DateTimeInterface
    {
        return $this->lastRead;
    }

    /**
     * Instant of the last persist(), null when the repository was never persisted.
     */
    public function getLastPersist(): ?DateTimeInterface
    {
        return $this->lastWrite;
    }

    /**
     * {@inheritdoc}
     */
    public function getReadCount(): int
    {
        return $this->readCount;
    }

    /**
     * {@inheritdoc}
     */
    public function getWriteCount(): int
    {
        return $this->writeCount;
    }

    /**
     * {@inheritdoc}
     */
    public function getFirstRead(): ?DateTimeInterface
    {
        return $this->firstRead;
    }

    /**
     * {@inheritdoc}
     */
    public function getLastRead(): ?DateTimeInterface
    {
        return $this->lastRead;
    }

    /**
     * {@inheritdoc}
     */
    public function getFirstWrite(): ?DateTimeInterface
    {
        return $this->firstWrite;
    }

    /**
     * {@inheritdoc}
     */
    public function getLastWrite(): ?DateTimeInterface
    {
        return $this->lastWrite;
    }

    /**
     * Registers a load of the repository.
     *
     * {@inheritdoc}
     */
    public function registerRead(?DateTimeInterface $timestamp = null): void
    {
        $time = $timestamp ?? new DateTimeImmutable();
        $this->readCount++;
        $this->firstRead ??= $time;
        $this->lastRead = $time;
    }

    /**
     * Registers a persist of the repository.
     *
     * {@inheritdoc}
     */
    public function registerWrite(?DateTimeInterface $timestamp = null): void
    {
        $time = $timestamp ?? new DateTimeImmutable();
        $this->writeCount++;
        $this->firstWrite ??= $time;
        $this->lastWrite = $time;
    }
}
