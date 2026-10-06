<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use InvalidArgumentException;

/**
 * IPartitionRepository - Contract for Graph-Partitioned Repositories
 *
 * Contract for nodes of a partitioned repository structured as a Directed Acyclic Graph (DAG).
 * Each partition bounds a subset of entities via an ISpecification,
 * optimizing queries via O(1) early branch pruning of disjoint paths and
 * hierarchically routing insertions and removals via subsumption.
 *
 * Features:
 * - Structural graph inspection (isRoot, isLeaf, getRootPartition, getParentRepository)
 * - Bounding specification retrieval (getSpecification, getParentSpecification)
 * - Underlying encapsulated repository access (getUnderlyingRepository)
 * - Partition addition by specification, with custom ID or custom repository
 * - Intelligent partition location by specification (findPartition)
 * - Retrieval of direct child partitions, all partitions, and filtered partitions
 * - Local-only entity querying (getEntitiesOfThisPartitionOnly)
 * - Dynamic single-entity and full-graph repartitioning
 *
 * @template T of IEntity
 * @extends IRepository<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IPartitionRepository extends IRepository
{
    /**
     * Returns the entity type (FQN class or interface) managed by this repository.
     */
    public function getEntityType(): string;

    /**
     * Reports whether this partition node is the root of the graph.
     */
    public function isRoot(): bool;

    /**
     * Returns the root node of this partition graph.
     *
     * @return IPartitionRepository<T>
     */
    public function getRootPartition(): IPartitionRepository;

    /**
     * Reports whether this partition node is a leaf (no child partitions).
     */
    public function isLeaf(): bool;

    /**
     * Returns the parent partitioned repository node, or null if this node is root.
     *
     * @return IPartitionRepository<T>|null
     */
    public function getParentRepository(): ?IPartitionRepository;

    /**
     * Returns the specification associated with the parent node, or null if root.
     *
     * @return ISpecification<T>|null
     */
    public function getParentSpecification(): ?ISpecification;

    /**
     * Returns the specification bounding this partition, or null if unconstrained root.
     *
     * @return ISpecification<T>|null
     */
    public function getSpecification(): ?ISpecification;

    /**
     * Returns the underlying repository encapsulated by this node.
     *
     * @return IRepository<T>
     */
    public function getUnderlyingRepository(): IRepository;

    /**
     * Adds a new partition bounded by the provided specification.
     * Automatically instantiates a compatible repository matching base type.
     *
     * @param ISpecification<T> $specification Bounding specification for the partition
     * @return IPartitionRepository<T> Created and positioned partition node
     * @throws InvalidArgumentException If specification is null or invalid
     */
    public function addPartition(ISpecification $specification): IPartitionRepository;

    /**
     * Adds a new partition with a bounding specification and an explicit identifier.
     *
     * @param ISpecification<T> $specification Bounding specification for the partition
     * @param string $partitionId Unique partition ID (required for persistent repositories)
     * @return IPartitionRepository<T> Created and positioned partition node
     * @throws InvalidArgumentException If specification is null or identifier is invalid/duplicate
     */
    public function addPartitionWithId(ISpecification $specification, string $partitionId): IPartitionRepository;

    /**
     * Adds a new partition with a bounding specification and a concrete repository instance.
     *
     * @param ISpecification<T> $specification Bounding specification for the partition
     * @param IRepository<T> $repository Concrete repository storing partition entities
     * @return IPartitionRepository<T> Created and positioned partition node
     * @throws InvalidArgumentException If specification or repository is null/invalid
     */
    public function addPartitionWithRepository(ISpecification $specification, IRepository $repository): IPartitionRepository;

    /**
     * Locates the most specialized partition matching or generalizing the given specification.
     *
     * @param ISpecification<T> $specification Sought specification
     * @return IPartitionRepository<T>|null Found partition node or null
     */
    public function findPartition(ISpecification $specification): ?IPartitionRepository;

    /**
     * Returns the list of direct child partitions of this node.
     *
     * @return array<IPartitionRepository<T>>
     */
    public function getDirectPartitions(): array;

    /**
     * Returns all descending partitions under this node in depth-first traversal.
     *
     * @return array<IPartitionRepository<T>>
     */
    public function getAllPartitions(): array;

    /**
     * Collects partitions under this node, optionally filtering by specification.
     *
     * @param ISpecification<T>|null $filterSpecification
     * @return array<IPartitionRepository<T>>
     */
    public function collectPartitions(?ISpecification $filterSpecification = null): array;

    /**
     * Returns only the entities directly residing in this node collection,
     * without aggregating entities from child sub-partitions.
     *
     * @return array<T>
     */
    public function getEntitiesOfThisPartitionOnly(): array;

    /**
     * Repartitions a specific entity after internal state change,
     * reallocating it to matching partitions and removing from partitions it no longer satisfies.
     *
     * @param T $entity Entity to re-evaluate
     * @return bool True if entity changed partitions, false otherwise
     */
    public function repartition(IEntity $entity): bool;

    /**
     * Repartitions all entities in this node and its descending child sub-partitions.
     *
     * @return int Total number of relocated entities
     */
    public function repartitionAll(): int;
}
