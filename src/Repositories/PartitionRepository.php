<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\IBinaryFormatRepository;
use Antevemus\ASpecification\Contracts\Repositories\IFakeRepository;
use Antevemus\ASpecification\Contracts\Repositories\IHumanReadableFormatRepository;
use Antevemus\ASpecification\Contracts\Repositories\IPartitionRepository;
use Antevemus\ASpecification\Contracts\Repositories\IPersistentRepository;
use Antevemus\ASpecification\Contracts\Repositories\IRepository;
use Antevemus\ASpecification\Contracts\Repositories\ITextualFormatRepository;
use Antevemus\ASpecification\Contracts\Repositories\IVolatileRepository;
use InvalidArgumentException;
use SplObjectStorage;
use Throwable;

/**
 * PartitionRepository - Directed Acyclic Graph (DAG) Partitioned Repository
 *
 * Implementation of the Decorator / Composite structural pattern for repository partitioning.
 * Delimits subsets of entities via ISpecifications, optimizing queries via
 * early O(1) branch pruning of disjoint partitions and routing insertions and deletions.
 *
 * Features:
 * - Node and edge management in a directed acyclic graph via SplObjectStorage
 * - Preservation of semantic markers (Volatile, Persistent, Fake, and format types)
 * - Hierarchical insertion routing (put/putAll)
 * - Aggregated query execution with disjoint partition pruning and duplicate elimination
 * - Consistent removal across sibling partitions
 * - Dynamic repartitioning of mutated entities
 *
 * @template T of IEntity
 * @extends AbstractRepository<T>
 * @implements IPartitionRepository<T>
 * @version    1.2.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class PartitionRepository extends AbstractRepository implements IPartitionRepository
{
    /** @var IRepository<T> */
    protected IRepository $underlyingRepository;

    /** @var ISpecification<T>|null */
    protected ?ISpecification $specification;

    /** @var IPartitionRepository<T>|null */
    protected ?IPartitionRepository $parentRepository;

    /** @var ISpecification<T>|null */
    protected ?ISpecification $parentSpecification;

    /** @var IPartitionRepository<T> */
    protected IPartitionRepository $rootPartition;

    /** @var SplObjectStorage<IPartitionRepository<T>, null> */
    protected SplObjectStorage $subPartitions;

    protected string $entityType;

    /**
     * Constructs a PartitionRepository.
     *
     * @param IRepository<T> $underlyingRepository Encapsulated target repository
     * @param ISpecification<T>|null $specification Bounding specification for this partition
     * @param IPartitionRepository<T>|null $parentRepository Parent partitioned repository
     * @param string|null $entityType FQN of managed entity type
     */
    public function __construct(
        IRepository $underlyingRepository,
        ?ISpecification $specification = null,
        ?IPartitionRepository $parentRepository = null,
        ?string $entityType = null
    ) {
        $this->underlyingRepository = $underlyingRepository;
        $this->specification = $specification;
        $this->parentRepository = $parentRepository;
        $this->parentSpecification = $parentRepository?->getSpecification();
        $this->rootPartition = $parentRepository !== null ? $parentRepository->getRootPartition() : $this;
        $this->subPartitions = new SplObjectStorage();
        $this->entityType = $entityType ?? IEntity::class;
    }

    /**
     * Polymorphic factory preserving semantic classification of base repository.
     *
     * @template U of IEntity
     * @param IRepository<U> $underlying
     * @param ISpecification<U>|null $spec
     * @param IPartitionRepository<U>|null $parent
     * @param string|null $entityType
     * @return IPartitionRepository<U>
     */
    public static function create(
        IRepository $underlying,
        ?ISpecification $spec = null,
        ?IPartitionRepository $parent = null,
        ?string $entityType = null
    ): IPartitionRepository {
        if ($underlying instanceof IFakeRepository) {
            return new FakePartitionRepository($underlying, $spec, $parent, $entityType);
        }
        if ($underlying instanceof IHumanReadableFormatRepository) {
            return new HumanReadableFormatPartitionRepository($underlying, $spec, $parent, $entityType);
        }
        if ($underlying instanceof IBinaryFormatRepository) {
            return new BinaryFormatPartitionRepository($underlying, $spec, $parent, $entityType);
        }
        if ($underlying instanceof ITextualFormatRepository) {
            return new TextualFormatPartitionRepository($underlying, $spec, $parent, $entityType);
        }
        if ($underlying instanceof IPersistentRepository) {
            return new PersistentPartitionRepository($underlying, $spec, $parent, $entityType);
        }
        if ($underlying instanceof IVolatileRepository) {
            return new VolatilePartitionRepository($underlying, $spec, $parent, $entityType);
        }

        return new self($underlying, $spec, $parent, $entityType);
    }

    /**
     * {@inheritdoc}
     */
    public function getEntityType(): string
    {
        return $this->entityType;
    }

    /**
     * {@inheritdoc}
     */
    public function isRoot(): bool
    {
        return $this->parentRepository === null;
    }

    /**
     * {@inheritdoc}
     */
    public function getRootPartition(): IPartitionRepository
    {
        return $this->rootPartition;
    }

    /**
     * {@inheritdoc}
     */
    public function isLeaf(): bool
    {
        return $this->subPartitions->count() === 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getParentRepository(): ?IPartitionRepository
    {
        return $this->parentRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function getParentSpecification(): ?ISpecification
    {
        return $this->parentSpecification;
    }

    /**
     * {@inheritdoc}
     */
    public function getSpecification(): ?ISpecification
    {
        return $this->specification;
    }

    /**
     * {@inheritdoc}
     */
    public function getUnderlyingRepository(): IRepository
    {
        return $this->underlyingRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function addPartition(ISpecification $specification): IPartitionRepository
    {
        if ($specification === null) {
            throw new InvalidArgumentException('Partition specification cannot be null.');
        }

        if ($this->underlyingRepository instanceof IPersistentRepository) {
            throw new InvalidArgumentException('Identifier required to add partition in persistent repository.');
        }

        // Instantiates new sibling repository of matching type
        $repoClass = get_class($this->underlyingRepository);
        $newRepo = new $repoClass();

        return $this->addPartitionWithRepository($specification, $newRepo);
    }

    /**
     * {@inheritdoc}
     */
    public function addPartitionWithId(ISpecification $specification, string $partitionId): IPartitionRepository
    {
        if ($specification === null) {
            throw new InvalidArgumentException('Partition specification cannot be null.');
        }

        if (trim($partitionId) === '') {
            throw new InvalidArgumentException('Partition identifier cannot be empty.');
        }

        if ($this->underlyingRepository instanceof IPersistentRepository) {
            if ($partitionId === $this->underlyingRepository->getRepositoryId()) {
                throw new InvalidArgumentException("Identifier '{$partitionId}' cannot duplicate parent repository ID.");
            }
        }

        // Instantiates new repository
        $repoClass = get_class($this->underlyingRepository);
        $newRepo = new $repoClass();

        return $this->addPartitionWithRepository($specification, $newRepo);
    }

    /**
     * {@inheritdoc}
     */
    public function addPartitionWithRepository(ISpecification $specification, IRepository $repository): IPartitionRepository
    {
        if ($specification === null) {
            throw new InvalidArgumentException('Partition specification cannot be null.');
        }

        if ($repository === null) {
            throw new InvalidArgumentException('Partition repository cannot be null.');
        }

        // Subsumption positioning algorithm in graph (RN-02)
        $subsumed = false;
        $partitionsToRemove = [];

        foreach ($this->subPartitions as $existingPartition) {
            $existingSpec = $existingPartition->getSpecification();
            if ($existingSpec === null) {
                continue;
            }

            // (a) Equivalent: replaces P
            if ($this->specsEquivalent($specification, $existingSpec)) {
                $newPartition = self::create($repository, $specification, $this, $this->entityType);
                // Migrate all entities
                foreach ($existingPartition->findAllEntitiesSpecifiedBy($specification) as $entity) {
                    $newPartition->put($entity);
                }
                $this->subPartitions->detach($existingPartition);
                $this->subPartitions->attach($newPartition);
                return $newPartition;
            }

            // (b) Special case of P: add recursively inside P
            if ($specification->isSpecialCaseOf($existingSpec)) {
                $existingPartition->addPartitionWithRepository($specification, $repository);
                $subsumed = true;
            }

            // (c) S generalizes P: new partition is inserted between current node and P
            if ($specification->isGeneralizationOf($existingSpec)) {
                $partitionsToRemove[] = $existingPartition;
            }
        }

        if ($subsumed) {
            return $this->findPartition($specification) ?? $this;
        }

        // Creates new partition as direct child
        $newPartition = self::create($repository, $specification, $this, $this->entityType);

        if (!empty($partitionsToRemove)) {
            foreach ($partitionsToRemove as $p) {
                $this->subPartitions->detach($p);
                $newPartition->addPartitionWithRepository($p->getSpecification(), $p->getUnderlyingRepository());
            }
        }

        // Migrates entities from current node satisfying new partition (RN-02 c/d)
        $migratedEntities = [];
        foreach ($this->underlyingRepository->findAllEntitiesSpecifiedBy($specification) as $entity) {
            $newPartition->put($entity);
            $migratedEntities[] = $entity;
        }

        foreach ($migratedEntities as $entity) {
            $this->underlyingRepository->remove($entity);
        }

        $this->subPartitions->attach($newPartition);

        return $newPartition;
    }

    /**
     * {@inheritdoc}
     */
    public function findPartition(ISpecification $specification): ?IPartitionRepository
    {
        if ($this->specification !== null && $this->specsEquivalent($this->specification, $specification)) {
            return $this;
        }

        foreach ($this->subPartitions as $child) {
            $found = $child->findPartition($specification);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getDirectPartitions(): array
    {
        $result = [];
        foreach ($this->subPartitions as $partition) {
            $result[] = $partition;
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getAllPartitions(): array
    {
        $result = [];
        foreach ($this->subPartitions as $partition) {
            $result[] = $partition;
            foreach ($partition->getAllPartitions() as $descendant) {
                if (!in_array($descendant, $result, true)) {
                    $result[] = $descendant;
                }
            }
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function collectPartitions(?ISpecification $filterSpecification = null): array
    {
        if ($filterSpecification === null) {
            return $this->getAllPartitions();
        }

        $result = [];
        foreach ($this->getAllPartitions() as $partition) {
            $spec = $partition->getSpecification();
            if ($spec !== null && ($spec->equals($filterSpecification) || $spec->isSpecialCaseOf($filterSpecification))) {
                $result[] = $partition;
            }
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getEntitiesOfThisPartitionOnly(): array
    {
        return $this->underlyingRepository->findAllEntitiesSpecifiedBy(
            $this->specification ?? new \Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification()
        );
    }

    /**
     * {@inheritdoc}
     */
    public function put(IEntity $entity): void
    {
        // Root validation (RN-03)
        if ($this->isRoot() && $this->specification !== null) {
            if (!$this->specification->isSatisfiedBy($entity)) {
                throw new InvalidArgumentException('Entity does not satisfy specification defined for root.');
            }
        }

        $routed = false;
        foreach ($this->subPartitions as $partition) {
            $spec = $partition->getSpecification();
            if ($spec !== null && $spec->isSatisfiedBy($entity)) {
                $partition->put($entity);
                $routed = true;
            }
        }

        // If not routed to child partition, keep in this node
        if (!$routed) {
            $this->underlyingRepository->put($entity);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function putAll(array $collectionOfEntities): void
    {
        foreach ($collectionOfEntities as $entity) {
            $this->put($entity);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function update(IEntity $entity): void
    {
        $this->underlyingRepository->update($entity);
        $this->repartition($entity);
    }

    /**
     * {@inheritdoc}
     */
    public function updateWithDelta(IEntity $entity, ?ISpecification $deltaSpecification = null): void
    {
        $this->underlyingRepository->updateWithDelta($entity, $deltaSpecification);
        $this->repartition($entity);
    }

    /**
     * {@inheritdoc}
     */
    public function repartition(IEntity $entity): bool
    {
        // Locates where the entity currently resides
        $removed = $this->remove($entity);
        $this->rootPartition->put($entity);
        return $removed;
    }

    /**
     * {@inheritdoc}
     */
    public function repartitionAll(): int
    {
        $all = $this->findAllEntitiesSpecifiedBy(
            new \Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification()
        );
        $reallocated = 0;
        foreach ($all as $entity) {
            if ($this->repartition($entity)) {
                $reallocated++;
            }
        }
        return $reallocated;
    }

    /**
     * {@inheritdoc}
     */
    public function remove(IEntity $entity): bool
    {
        $removed = false;

        // Remove across all child partitions (RN-07)
        foreach ($this->subPartitions as $partition) {
            if ($partition->remove($entity)) {
                $removed = true;
            }
        }

        if ($this->underlyingRepository->remove($entity)) {
            $removed = true;
        }

        return $removed;
    }

    /**
     * {@inheritdoc}
     */
    public function removeAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        $this->validateSpecification($specification);

        // O(1) pruning if disjoint from current partition (RN-06)
        if ($this->specification !== null && $this->specification->isDisjointWith($specification)) {
            return 0;
        }

        $totalRemoved = $this->underlyingRepository->removeAllEntitiesSpecifiedBy($specification);

        foreach ($this->subPartitions as $partition) {
            $totalRemoved += $partition->removeAllEntitiesSpecifiedBy($specification);
        }

        return $totalRemoved;
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
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        foreach ($this->findAllEntitiesSpecifiedBy($specification) as $entity) {
            yield $entity;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findAllEntitiesSpecifiedBy(ISpecification $specification): array
    {
        $this->validateSpecification($specification);

        try {
            // Early O(1) pruning if disjoint (RN-04)
            if ($this->specification !== null && $this->specification->isDisjointWith($specification)) {
                return [];
            }

            $entities = [];
            $visitedIds = [];

            // Local node entities
            foreach ($this->underlyingRepository->findAllEntitiesSpecifiedBy($specification) as $entity) {
                $id = $entity->getEntityId();
                if (!isset($visitedIds[$id])) {
                    $visitedIds[$id] = true;
                    $entities[] = $entity;
                }
            }

            // Aggregate entities from child partitions avoiding duplicates (RN-05)
            foreach ($this->subPartitions as $partition) {
                foreach ($partition->findAllEntitiesSpecifiedBy($specification) as $entity) {
                    $id = $entity->getEntityId();
                    if (!isset($visitedIds[$id])) {
                        $visitedIds[$id] = true;
                        $entities[] = $entity;
                    }
                }
            }

            return $entities;
        } catch (Throwable $e) {
            if ($e instanceof InvalidArgumentException) {
                throw $e;
            }
            throw new RepositoryException("Failed query operation on partitioned repository: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Creates or attaches a sub-partition in the graph from the given specification.
     *
     * @param ISpecification|null $specification Bounding specification for partition
     * @return IPartitionRepository Created sub-partition or this instance if specification is null
     */
    public function makePartition(?ISpecification $specification = null): IPartitionRepository
    {
        if ($specification !== null) {
            return $this->addPartition($specification);
        }
        return $this;
    }

    /**
     * Two partition specifications are equivalent only when they denote the same
     * predicate (ISpecification::equals, structural). "Same class" is never enough:
     * AbstractSpecification::__toString() returns the class name, so the former
     * fallback (class + string form) treated every leaf of one class as equivalent
     * and the replacement branch of addPartitionWithRepository orphaned entities
     * (BUG-20261007-C5YG).
     */
    protected function specsEquivalent(ISpecification $a, ISpecification $b): bool
    {
        return $a->equals($b);
    }
}
