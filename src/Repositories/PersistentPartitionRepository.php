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
 * @version    1.4.4
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
     * Loads persistent data, propagating to every persistent repository of the DAG below this
     * node (RN-14), each exactly once.
     *
     * @return void
     */
    public function load(): void
    {
        foreach ($this->persistentRepositoriesInGraph() as $repository) {
            $repository->load();
        }
    }

    /**
     * Persists repository state, propagating to every persistent repository of the DAG below this
     * node (RN-14), each exactly once.
     *
     * @return void
     */
    public function store(): void
    {
        foreach ($this->persistentRepositoriesInGraph() as $repository) {
            $repository->store();
        }
    }

    /**
     * Closes the repository, releasing resources of every persistent repository of the DAG below
     * this node (RN-14), each exactly once.
     *
     * @return void
     */
    public function close(): void
    {
        foreach ($this->persistentRepositoriesInGraph() as $repository) {
            $repository->close();
        }
    }

    /**
     * Collects this node's repository and the repository of every descendant partition that is
     * persistent, each once. As in Domian (load/persist/close over getAllPartitions()), a
     * persistent partition below a volatile one is reached, and a partition shared by several
     * parents (diamond) is visited a single time; volatile repositories are ignored.
     *
     * @return array<IPersistentRepository<T>>
     */
    private function persistentRepositoriesInGraph(): array
    {
        $result = [];
        if ($this->underlyingRepository instanceof IPersistentRepository) {
            $result[] = $this->underlyingRepository;
        }
        foreach ($this->getAllPartitions() as $partition) {
            $repository = $partition->getUnderlyingRepository();
            if ($repository instanceof IPersistentRepository && !in_array($repository, $result, true)) {
                $result[] = $repository;
            }
        }
        return $result;
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
