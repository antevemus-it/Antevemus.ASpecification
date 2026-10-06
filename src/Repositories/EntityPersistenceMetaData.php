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
 *
 * @version    1.1.0
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
