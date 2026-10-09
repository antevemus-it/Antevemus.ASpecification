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
 * Graph semantics (ported from Domian's PartitionRepository):
 * - Partitions are positioned by subsumption (RN-02): an equal specification replaces the
 *   partition, a special case goes inside it, a generalization is inserted above it (the existing
 *   node is re-wired, keeping its sub-partitions), anything else becomes a sibling that shares
 *   the intersecting sub-partitions. The resulting graph does not depend on insertion order.
 * - A partition that is a special case of several siblings is ONE node referenced by all of
 *   them; getAllPartitions() lists it once, and getParentRepository() returns the parent it was
 *   wired to last.
 * - Boundaries are enforced (RN-03): put() on a partition with an entity outside its
 *   specification hands the entity to the root, which routes it; the root rejects with
 *   InvalidArgumentException an entity that fits nowhere.
 *
 * @template T of IEntity
 * @extends IRepository<T>
 * @version    1.4.4
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
     * A node shared by several parents (special case of sibling partitions) reports the parent
     * it was wired to last.
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
     * On a volatile node the partition is a new instance of the node's repository type, receiving
     * the identifier when its constructor declares a "repositoryId" parameter (RN-10, RN-20). On a
     * persistent node the identifier is validated (non-empty, different from the node's own id)
     * and the call is then refused with PartitionCreationException: a persistent partition needs
     * storage that is never derived from an id, so the caller builds the sibling repository and
     * uses addPartitionWithRepository() (RN-20).
     *
     * @param ISpecification<T> $specification Bounding specification for the partition
     * @param string $partitionId Partition identifier (non-empty, distinct from the node's id)
     * @return IPartitionRepository<T> Created and positioned partition node
     * @throws InvalidArgumentException If specification is null or identifier is invalid/duplicate
     * @throws Exceptions\PartitionCreationException On a persistent node (RN-20)
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
     * Locates the most specialized partition matching or generalizing the given specification
     * (Domian: findPartitionFor).
     *
     * The search returns the partition whose specification equals the sought one and, failing
     * that, descends through every partition the specification is a special case of, returning
     * the deepest one reached; when no sub-partition generalizes the specification, this node
     * itself is returned. The result is null only when the specification is outside this node's
     * boundary (this node has a specification that neither equals nor generalizes it); an
     * unconstrained root therefore never returns null.
     *
     * @param ISpecification<T> $specification Sought specification
     * @return IPartitionRepository<T>|null Most specific partition generalizing the specification, or null when outside this node's boundary
     */
    public function findPartition(ISpecification $specification): ?IPartitionRepository;

    /**
     * Returns the list of direct child partitions of this node.
     *
     * @return array<IPartitionRepository<T>>
     */
    public function getDirectPartitions(): array;

    /**
     * Returns all descending partitions under this node in depth-first traversal, each node once
     * (a partition shared by several parents is listed a single time).
     *
     * @return array<IPartitionRepository<T>>
     */
    public function getAllPartitions(): array;

    /**
     * Collects partitions under this node, optionally filtering by specification.
     *
     * Without a filter, equivalent to getAllPartitions(). A filter whose getType() is a repository
     * type (IRepository or a subtype) is evaluated on the partition repository objects themselves,
     * as Domian's collectAllPartitionsWithRepositorySatisfying() does. Any other filter (an
     * entity-typed specification) selects the partitions whose specification is equal to, or a
     * special case of, the filter (RN-19).
     *
     * @param ISpecification<T>|ISpecification<IRepository<T>>|null $filterSpecification
     * @return array<IPartitionRepository<T>>
     */
    public function collectPartitions(?ISpecification $filterSpecification = null): array;

    /**
     * Returns only the entities physically stored in this node's own repository, without
     * aggregating entities from child sub-partitions. Every stored entity of the partition's
     * type is reported, including one that no longer satisfies the partition specification and
     * has not been repartitioned yet (RN-09).
     *
     * @return array<T>
     */
    public function getEntitiesOfThisPartitionOnly(): array;

    /**
     * Repartitions a specific entity after internal state change, in place: the entity stays in
     * the partitions it still satisfies, descends into sub-partitions it now satisfies, and is
     * removed from partitions it no longer satisfies and routed again from the root. The decision
     * is graph-wide, so a call on a non-root node is handled by the root. An entity that is not
     * stored in the graph is never inserted.
     *
     * @param T $entity Entity to re-evaluate
     * @return bool True when the entity resides in a suitable partition afterwards (also when it
     *              did not move); false when the entity is not stored in the graph
     */
    public function repartition(IEntity $entity): bool;

    /**
     * Repartitions all entities in this node and its descending child sub-partitions.
     *
     * @return int Number of entities whose set of storing repositories changed
     */
    public function repartitionAll(): int;
}
