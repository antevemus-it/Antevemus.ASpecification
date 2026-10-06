<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

use DateTimeInterface;

/**
 * IEntityPersistenceMetaData - Contract for Lifecycle and Persistence Metadata
 *
 * Contract for persistence metadata associated with an entity (IEntity).
 * Maintains counters and temporal logs of entity read and write cycles
 * across storage media. Does not possess its own identity and is not queryable directly.
 *
 * Features:
 * - Total read and write operation counters
 * - First and last read timestamps tracking
 * - First and last write timestamps tracking
 * - Lifecycle event registration hooks
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IEntityPersistenceMetaData
{
    /**
     * Returns the total count of times the entity was read/loaded.
     */
    public function getReadCount(): int;

    /**
     * Returns the total count of times the entity was written/persisted.
     */
    public function getWriteCount(): int;

    /**
     * Returns the timestamp of the first recorded read operation, or null if never read.
     */
    public function getFirstRead(): ?DateTimeInterface;

    /**
     * Returns the timestamp of the last recorded read operation, or null if never read.
     */
    public function getLastRead(): ?DateTimeInterface;

    /**
     * Returns the timestamp of the first recorded write operation.
     */
    public function getFirstWrite(): ?DateTimeInterface;

    /**
     * Returns the timestamp of the last recorded write operation.
     */
    public function getLastWrite(): ?DateTimeInterface;

    /**
     * Registers an entity read occurrence, incrementing the counter
     * and updating first/last read timestamps.
     *
     * @param DateTimeInterface|null $timestamp Event timestamp (or current time if null)
     */
    public function registerRead(?DateTimeInterface $timestamp = null): void;

    /**
     * Registers an entity write occurrence, incrementing the counter
     * and updating first/last write timestamps.
     *
     * @param DateTimeInterface|null $timestamp Event timestamp (or current time if null)
     */
    public function registerWrite(?DateTimeInterface $timestamp = null): void;
}
