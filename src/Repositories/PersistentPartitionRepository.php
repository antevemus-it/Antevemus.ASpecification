<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\IEntityPersistenceMetaData;
use Antevemus\ASpecification\Contracts\Repositories\IPersistentRepository;
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;

/**
 * PersistentPartitionRepository - Persistent partitioned entity repository
 *
 * Specialization of PartitionRepository preserving the IPersistentRepository contract
 * and propagating lifecycle operations (load, store, close) to persistent partitions in the DAG (RN-14).
 *
 * Features:
 * - Load and store propagation across persistent partitions in the graph
 * - Exposure of unique repository identifier and persistence definition mode
 * - Deterministic signaling of unsupported individual entity metadata (RN-14)
 *
 * @template T of IEntity
 * @extends PartitionRepository<T>
 * @implements IPersistentRepository<T>
 * @version    1.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class PersistentPartitionRepository extends PartitionRepository implements IPersistentRepository
{
    /**
     * Counts all entities present in the partition.
     *
     * @return int Total number of entities
     */
    public function countAllEntities(): int
    {
        return $this->countAllEntitiesSpecifiedBy(new AllEntitiesSpecification($this->entityType));
    }

    /**
     * Returns the unique identifier of the partitioned repository.
     *
     * @return string Repository identifier
     */
    public function getRepositoryId(): string
    {
        if ($this->underlyingRepository instanceof IPersistentRepository) {
            return $this->underlyingRepository->getRepositoryId();
        }
        return 'partition-' . spl_object_hash($this);
    }

    /**
     * Returns the base data storage directory, if applicable.
     *
     * @return string|null Path to data directory or null if purely in-memory
     */
    public function getDataDirectory(): ?string
    {
        if ($this->underlyingRepository instanceof IPersistentRepository) {
            return $this->underlyingRepository->getDataDirectory();
        }
        return null;
    }

    /**
     * Returns the persistence definition mode configured for this repository.
     *
     * @return PersistenceDefinition Persistence mode
     */
    public function getPersistenceDefinition(): PersistenceDefinition
    {
        if ($this->underlyingRepository instanceof IPersistentRepository) {
            return $this->underlyingRepository->getPersistenceDefinition();
        }
        return PersistenceDefinition::MemoryOnly;
    }

    /**
     * Returns a human-readable description of the repository serialization format.
     *
     * @return string Format description
     */
    public function getFormatDescription(): string
    {
        if ($this->underlyingRepository instanceof IPersistentRepository) {
            return $this->underlyingRepository->getFormatDescription();
        }
        return 'Partition DAG Format';
    }

    /**
     * Loads persistent data, propagating across all sub-partitions in the DAG.
     *
     * @return void
     */
    public function load(): void
    {
        if ($this->underlyingRepository instanceof IPersistentRepository) {
            $this->underlyingRepository->load();
        }

        foreach ($this->subPartitions as $partition) {
            if ($partition instanceof IPersistentRepository) {
                $partition->load();
            }
        }
    }

    /**
     * Persists repository state, propagating store operations across the DAG.
     *
     * @return void
     */
    public function store(): void
    {
        if ($this->underlyingRepository instanceof IPersistentRepository) {
            $this->underlyingRepository->store();
        }

        foreach ($this->subPartitions as $partition) {
            if ($partition instanceof IPersistentRepository) {
                $partition->store();
            }
        }
    }

    /**
     * Closes the repository, releasing resources and propagating to sub-partitions.
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->underlyingRepository instanceof IPersistentRepository) {
            $this->underlyingRepository->close();
        }

        foreach ($this->subPartitions as $partition) {
            if ($partition instanceof IPersistentRepository) {
                $partition->close();
            }
        }
    }

    /**
     * Operation unsupported directly at aggregated partition level.
     *
     * @param IEntity $entity
     * @return IEntityPersistenceMetaData|null
     * @throws RepositoryException Always thrown (RN-14)
     */
    public function getEntityMetaData(IEntity $entity): ?IEntityPersistenceMetaData
    {
        throw new RepositoryException('Per-entity metadata operations are not supported on a partitioned repository.');
    }
}
