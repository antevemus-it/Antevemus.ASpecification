<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Repositories\IEntityPersistenceMetaData;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * EntityPersistenceMetaData - Entity Persistence Metadata Implementation
 *
 * Default implementation of entity persistence and lifecycle metadata.
 * Maintains temporal history and counters for read and write operations.
 *
 * Features:
 * - Accurate counting of read and write operations
 * - Tracking of first and last read occurrences
 * - Tracking of first and last write occurrences
 * - Initialization with creation timestamp
 * - Restoration of a persisted record (restore(), 2.0.0): the file repositories write the record
 *   next to each entity (`__persistence_metadata`) and rebuild it on load
 *
 * @version    2.0.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class EntityPersistenceMetaData implements IEntityPersistenceMetaData
{
    private int $readCount = 0;
    private int $writeCount = 0;
    private ?DateTimeInterface $firstRead = null;
    private ?DateTimeInterface $lastRead = null;
    private ?DateTimeInterface $firstWrite = null;
    private ?DateTimeInterface $lastWrite = null;

    /**
     * Constructs persistence metadata.
     * At creation time, the provided (or current) timestamp defines both first and last initial write,
     * while counters start at zero.
     *
     * @param DateTimeInterface|null $initialWriteTime Initial creation/write timestamp
     */
    public function __construct(?DateTimeInterface $initialWriteTime = null)
    {
        $time = $initialWriteTime ?? new DateTimeImmutable();
        $this->firstWrite = $time;
        $this->lastWrite = $time;
    }

    /**
     * Rebuilds a record from persisted values (the `__persistence_metadata` block the file
     * repositories write since 2.0.0), without registering any read or write.
     *
     * @param int $readCount Reads registered so far (>= 0)
     * @param int $writeCount Writes registered so far (>= 0)
     * @param DateTimeInterface|null $firstRead First read, null when never read
     * @param DateTimeInterface|null $lastRead Last read, null when never read
     * @param DateTimeInterface|null $firstWrite First write, null when unknown
     * @param DateTimeInterface|null $lastWrite Last write, null when unknown
     * @return self
     * @throws \InvalidArgumentException When a counter is negative
     */
    public static function restore(
        int $readCount,
        int $writeCount,
        ?DateTimeInterface $firstRead,
        ?DateTimeInterface $lastRead,
        ?DateTimeInterface $firstWrite,
        ?DateTimeInterface $lastWrite
    ): self {
        if ($readCount < 0 || $writeCount < 0) {
            throw new \InvalidArgumentException(sprintf(
                'Persistence metadata counters cannot be negative (read_count %d, write_count %d).',
                $readCount,
                $writeCount
            ));
        }

        $metadata = new self();
        $metadata->readCount = $readCount;
        $metadata->writeCount = $writeCount;
        $metadata->firstRead = $firstRead;
        $metadata->lastRead = $lastRead;
        $metadata->firstWrite = $firstWrite;
        $metadata->lastWrite = $lastWrite;

        return $metadata;
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
     * {@inheritdoc}
     */
    public function registerRead(?DateTimeInterface $timestamp = null): void
    {
        $time = $timestamp ?? new DateTimeImmutable();
        $this->readCount++;

        if ($this->firstRead === null) {
            $this->firstRead = $time;
        }

        $this->lastRead = $time;
    }

    /**
     * {@inheritdoc}
     */
    public function registerWrite(?DateTimeInterface $timestamp = null): void
    {
        $time = $timestamp ?? new DateTimeImmutable();
        $this->writeCount++;

        if ($this->firstWrite === null) {
            $this->firstWrite = $time;
        }

        $this->lastWrite = $time;
    }
}
