<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\PartitionCreationException;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\IBinaryFormatRepository;
use Antevemus\ASpecification\Contracts\Repositories\IFakeRepository;
use Antevemus\ASpecification\Contracts\Repositories\IHumanReadableFormatRepository;
use Antevemus\ASpecification\Contracts\Repositories\IPartitionRepository;
use Antevemus\ASpecification\Contracts\Repositories\IPersistentRepository;
use Antevemus\ASpecification\Contracts\Repositories\IRepository;
use Antevemus\ASpecification\Contracts\Repositories\ITextualFormatRepository;
use Antevemus\ASpecification\Contracts\Repositories\IVolatileRepository;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Closure;
use Generator;
use InvalidArgumentException;
use SplObjectStorage;
use Throwable;

/**
 * PartitionRepository - Directed Acyclic Graph (DAG) Partitioned Repository
 *
 * Implementation of the Decorator / Composite structural pattern for repository partitioning,
 * ported from Domian's PartitionRepositoryInvocationHandler. Delimits subsets of entities via
 * ISpecifications, optimizing queries via early O(1) branch pruning of disjoint partitions and
 * routing insertions and deletions.
 *
 * Features:
 * - Node and edge management in a directed acyclic graph via SplObjectStorage; a partition that
 *   is a special case of several siblings is ONE node referenced by all of them (RN-02 (b))
 * - Insertion of a generalization re-wires the existing node under the new one, keeping its
 *   sub-partitions (RN-02 (c)); insertion order never changes the resulting graph
 * - Preservation of semantic markers (Volatile, Persistent, Fake, and format types)
 * - Hierarchical insertion routing (put/putAll) with boundary enforcement: an entity outside a
 *   node's specification is handed to the root, which routes it or rejects it (RN-03)
 * - Lazy, duplicate-free iteration delegating to the partitions on demand (RN-05, RN-18)
 * - Aggregated query execution with disjoint partition pruning and duplicate elimination
 * - Consistent removal across sibling partitions
 * - In-place repartitioning of mutated entities, never inserting unknown entities (RN-09)
 * - Membership test (contains) over the node and every descendant partition
 * - Materialized DAG index (1.6.0, RN-06), invalidated by every structural change (a partition
 *   attached, detached or re-wired by addPartition*()), in two parts with their own switches:
 *   (i) per node, the clusters of mutually disjoint direct partitions: put() stops testing the
 *       partitions of a cluster as soon as one of them accepts the entity, since the algebra
 *       guarantees that no other member can (disjointness is required in both directions, and a
 *       failing algebra call never clusters); maintained incrementally on attach/detach. Off by
 *       default (setDagRoutingEnabled()), see the benchmark report;
 *   (ii) per subtree, the topological order of its nodes (general to specific, each node once,
 *       shared nodes included) with the parent and child lists: findAll()/iterate*() walk the
 *       subtree with an explicit stack over it (no generator per node, one duplicate filter),
 *       keeping the result order of the recursive walk; remove(), removeAll*() and contains() walk
 *       the topological order. Pruning by disjunction is unchanged. A structural change during an
 *       iteration hands the rest of it to the recursive walk (RN-18 preserved). On by default
 *       (setDagIndexEnabled()).
 *   Both switches are process-wide; tests/Benchmark/REPORT-dag-index.md holds the measurements that
 *   decided their defaults.
 *
 * @template T of IEntity
 * @extends AbstractRepository<T>
 * @implements IPartitionRepository<T>
 * @version    1.6.0
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

    /** Process-wide switch of the subtree index (RN-06 (ii), topological order); see setDagIndexEnabled(). */
    private static bool $dagIndexEnabled = true;

    /** Process-wide switch of the routing clusters (RN-06 (i)); see setDagRoutingEnabled(). */
    private static bool $dagRoutingEnabled = false;

    /** Bumped on every structural change of any partition graph (attach, detach, re-wiring). */
    private static int $graphVersion = 0;

    /** Library classes whose traversal the subtree index may take over (user subclasses keep the recursive walk). */
    private const INDEXABLE_CLASSES = [
        self::class => true,
        VolatilePartitionRepository::class => true,
        PersistentPartitionRepository::class => true,
        FakePartitionRepository::class => true,
        HumanReadableFormatPartitionRepository::class => true,
        BinaryFormatPartitionRepository::class => true,
        TextualFormatPartitionRepository::class => true,
    ];

    /**
     * Routing index of the direct partitions (RN-06 (i)): object id => [partition, specification, cluster id],
     * in attachment order; null until the first put() with the index enabled, then maintained incrementally.
     *
     * @var array<int, array{0: IPartitionRepository<T>, 1: ISpecification<T>|null, 2: int}>|null
     */
    private ?array $routingIndex = null;

    /** @var array<int, array<int, true>> Cluster id => object ids of its mutually disjoint members */
    private array $routingClusters = [];

    private int $nextClusterId = 0;

    /**
     * Subtree index (RN-06 (ii)) and the graph version it was built at; 'plan' is null when the subtree
     * cannot be indexed (a node of a user class, or a cycle).
     *
     * @var array{version: int, plan: array{nodes: list<PartitionRepository<T>>, repos: list<IRepository<T>>, specs: list<ISpecification<T>|null>, children: list<list<int>>, parents: list<list<int>>, topo: list<int>}|null}|null
     */
    private ?array $subtreeIndex = null;

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
        $this->subPartitions = new SplObjectStorage();
        $this->entityType = $entityType ?? IEntity::class;
        $this->wireUp($parentRepository);
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
     * Turns the subtree index of the DAG (RN-06 (ii): topological order and adjacency walked by
     * findAll()/iterate*()/remove()/removeAll*()/contains()) on or off for every partition graph of
     * the process. On by default. Results never depend on it, only the traversal cost does.
     *
     * @param bool $enabled
     * @return void
     */
    public static function setDagIndexEnabled(bool $enabled): void
    {
        self::$dagIndexEnabled = $enabled;
    }

    /**
     * Whether the subtree index of the DAG (RN-06 (ii)) is in use.
     *
     * @return bool
     */
    public static function isDagIndexEnabled(): bool
    {
        return self::$dagIndexEnabled;
    }

    /**
     * Turns the routing clusters of put() (RN-06 (i): direct partitions grouped by mutual disjunction,
     * so that put() stops testing a cluster once a member accepts the entity) on or off for every
     * partition graph of the process. Off by default: building the clusters costs one
     * isDisjointWith() pair per sibling pair, and with set specifications (in() of a few hundred
     * values) that pair costs tens of milliseconds, more than the evaluations it saves on the measured
     * workloads (tests/Benchmark/REPORT-dag-index.md). Turn it on for graphs whose partition
     * specifications have cheap disjunction and many siblings. Results never depend on it.
     *
     * @param bool $enabled
     * @return void
     */
    public static function setDagRoutingEnabled(bool $enabled): void
    {
        self::$dagRoutingEnabled = $enabled;
    }

    /**
     * Whether the routing clusters of put() (RN-06 (i)) are in use.
     *
     * @return bool
     */
    public static function isDagRoutingEnabled(): bool
    {
        return self::$dagRoutingEnabled;
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

        return $this->addPartitionWithRepository($specification, $this->withInheritedExpiry($newRepo));
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

            // A persistent repository needs its own storage, which is never derived from the id:
            // the caller builds the sibling and uses addPartitionWithRepository() (BUG-20261007-QFSJ).
            throw new PartitionCreationException(get_class($this->underlyingRepository), $partitionId);
        }

        // Instantiates a new sibling repository of the same type, handing it the identifier when
        // its constructor accepts one (named parameter "repositoryId"); otherwise the type has no
        // identifier to keep and the id is used only as the partition's requested name (RN-10).
        $repoClass = get_class($this->underlyingRepository);
        $newRepo = $this->constructorAccepts($repoClass, 'repositoryId')
            ? new $repoClass(repositoryId: $partitionId)
            : new $repoClass();

        return $this->addPartitionWithRepository($specification, $this->withInheritedExpiry($newRepo));
    }

    /**
     * A repository the library builds for a new partition expires like this node's own (TTL and clock,
     * RN-05 of 1.6.0); a repository supplied by the caller is never touched.
     *
     * @param IRepository<T> $repository
     * @return IRepository<T>
     */
    private function withInheritedExpiry(IRepository $repository): IRepository
    {
        if ($repository instanceof InMemoryRepository && $this->underlyingRepository instanceof InMemoryRepository) {
            $repository->adoptExpiryPolicyOf($this->underlyingRepository);
        }
        return $repository;
    }

    /**
     * Tells whether the constructor of the class declares a parameter with the given name.
     *
     * @param class-string $className
     * @param string $parameterName
     * @return bool
     */
    private function constructorAccepts(string $className, string $parameterName): bool
    {
        $constructor = (new \ReflectionClass($className))->getConstructor();
        if ($constructor === null) {
            return false;
        }
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->getName() === $parameterName) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reports whether the entity is stored in this node or in any descendant partition.
     *
     * @param IEntity $entity Entity to look for
     * @return bool
     */
    public function contains(IEntity $entity): bool
    {
        $plan = $this->subtreePlan();
        if ($plan !== null) {
            foreach ($plan['topo'] as $i) {
                if ($plan['repos'][$i]->contains($entity)) {
                    return true;
                }
            }
            return false;
        }

        if ($this->underlyingRepository->contains($entity)) {
            return true;
        }

        foreach ($this->subPartitions as $partition) {
            if ($partition->contains($entity)) {
                return true;
            }
        }

        return false;
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

        // The node is built once and threaded through the whole insertion, so that a partition
        // that is a special case of several siblings ends up as ONE node shared by all of them.
        $node = self::create($repository, $specification, $this, $this->entityType);
        if (!$node instanceof self) {
            throw new RepositoryException('Partition nodes must be instances of ' . self::class . '.');
        }

        return $this->insertNode($node);
    }

    /**
     * Positions a partition node in the graph below this node, following the subsumption
     * algorithm of Domian's PartitionRepositoryInvocationHandler.addPartition() (RN-02).
     *
     * For each existing direct partition P, compared with the new specification S:
     * - (a) S equals P: the node replaces P, receiving every entity of P's subtree; P's
     *   sub-partitions are dropped (RN-02 (a), RN-19).
     * - (b) S is a special case of P: the SAME node is inserted recursively inside P; when several
     *   siblings generalize S, the node is shared by all of them (diamond).
     * - (c) S generalizes P: P (with its whole subtree) is re-wired below the node, and the entities
     *   of this level that satisfy S move into the node.
     * - (d) otherwise (sibling): the node receives a copy of P's own entities that satisfy S and a
     *   shared reference to each sub-partition of P that is a special case of S.
     * When no (a)/(b) placement happened, the node becomes a direct child of this node and the
     * entities of this level satisfying S migrate into it.
     *
     * The node passed in may already belong to the graph (relocation under (c), sharing under (d)).
     *
     * @param PartitionRepository<T> $node Node carrying the specification and the target repository
     * @return IPartitionRepository<T> The node actually placed for the specification
     */
    protected function insertNode(PartitionRepository $node): IPartitionRepository
    {
        $specification = $node->getSpecification();
        if ($specification === null) {
            throw new InvalidArgumentException('Partition specification cannot be null.');
        }

        $placed = false;       // Java: "put"
        $result = null;        // Java: subPartitionRepository
        $onThisLevel = null;   // Java: subPartitionRepositoryOnThisLevel

        foreach ($this->getDirectPartitions() as $existing) {
            $existingSpec = $existing->getSpecification();
            if ($existingSpec === null) {
                continue;
            }

            // The very same node is already a child here (a shared sub-partition reached twice).
            if ($existing === $node) {
                $placed = true;
                $result ??= $node;
                continue;
            }

            if ($this->specsEquivalent($specification, $existingSpec)) {
                // (a) Equivalent: replaces P, migrating every entity of P's subtree
                $onThisLevel = $this->adopt($node);
                $this->detachPartition($existing);
                $this->attachPartition($onThisLevel);
                foreach ($existing->findAllEntitiesSpecifiedBy(new AlwaysTrueSpecification()) as $entity) {
                    $onThisLevel->put($entity);
                }
                $placed = true;
            } elseif ($specification->isSpecialCaseOf($existingSpec)) {
                // (b) Special case of P: the same node goes recursively inside P
                $result = $existing instanceof self
                    ? $existing->insertNode($node)
                    : $existing->addPartitionWithRepository($specification, $node->getUnderlyingRepository());
                $placed = true;
            } elseif ($specification->isGeneralizationOf($existingSpec)) {
                // (c) S generalizes P: P is re-wired (same node, sub-partitions kept) below the new node
                $onThisLevel ??= $this->adopt($node);
                $this->detachPartition($existing);
                if ($existing instanceof self) {
                    $onThisLevel->insertNode($existing);
                } else {
                    $onThisLevel->addPartitionWithRepository($existingSpec, $existing->getUnderlyingRepository());
                }
                if ($this->underlyingRepository !== $onThisLevel->getUnderlyingRepository()) {
                    $this->moveMatchingEntities($specification, $onThisLevel);
                }
            } else {
                // (d) Sibling: copies intersecting entities and shares intersecting sub-partitions
                $result ??= $this->adopt($node);
                foreach ($existing->getEntitiesOfThisPartitionOnly() as $entity) {
                    if ($specification->isSatisfiedBy($entity)) {
                        $result->put($entity);
                    }
                }
                foreach ($existing->getDirectPartitions() as $grandChild) {
                    $grandChildSpec = $grandChild->getSpecification();
                    if ($grandChildSpec !== null && $grandChildSpec->isSpecialCaseOf($specification)) {
                        if ($grandChild instanceof self && $result instanceof self) {
                            $result->insertNode($grandChild);
                        } else {
                            $result->addPartitionWithRepository($grandChildSpec, $grandChild->getUnderlyingRepository());
                        }
                    }
                }
            }
        }

        if ($result === null) {
            $result = $onThisLevel ?? $this->adopt($node);
        }

        if (!$placed) {
            $this->attachPartition($result);
            if ($this->underlyingRepository !== $result->getUnderlyingRepository()) {
                $this->moveMatchingEntities($specification, $result);
            }
        }

        return $result;
    }

    /**
     * Makes this node the parent of the given node (Domian's wireUpPartition) and returns it.
     *
     * @param PartitionRepository<T> $node
     * @return PartitionRepository<T>
     */
    protected function adopt(PartitionRepository $node): PartitionRepository
    {
        $node->wireUp($this);
        return $node;
    }

    /**
     * Sets the parent of this node, deriving the parent specification and the root.
     * A node shared by several parents (diamond) keeps the parent wired last, as in Domian.
     *
     * @param IPartitionRepository<T>|null $parent Parent node, or null for a root
     * @return void
     */
    protected function wireUp(?IPartitionRepository $parent): void
    {
        $this->parentRepository = $parent;
        $this->parentSpecification = $parent?->getSpecification();
        $this->rootPartition = $parent !== null ? $parent->getRootPartition() : $this;
        self::$graphVersion++;
    }

    /**
     * Attaches a direct partition, invalidating the subtree indexes and updating this node's routing
     * clusters incrementally (RN-06).
     *
     * @param IPartitionRepository<T> $partition
     * @return void
     */
    private function attachPartition(IPartitionRepository $partition): void
    {
        if ($this->subPartitions->contains($partition)) {
            return;
        }
        $this->subPartitions->attach($partition);
        self::$graphVersion++;
        if ($this->routingIndex !== null) {
            if (self::$dagRoutingEnabled) {
                $this->addToRouting($partition);
            } else {
                $this->dropRouting(); // rebuilt on demand if the clusters are turned on again
            }
        }
    }

    /**
     * Forgets the routing clusters of this node.
     *
     * @return void
     */
    private function dropRouting(): void
    {
        $this->routingIndex = null;
        $this->routingClusters = [];
    }

    /**
     * Detaches a direct partition, invalidating the subtree indexes and updating this node's routing
     * clusters incrementally (RN-06).
     *
     * @param IPartitionRepository<T> $partition
     * @return void
     */
    private function detachPartition(IPartitionRepository $partition): void
    {
        if (!$this->subPartitions->contains($partition)) {
            return;
        }
        $this->subPartitions->detach($partition);
        self::$graphVersion++;
        if ($this->routingIndex !== null && !self::$dagRoutingEnabled) {
            $this->dropRouting();
        } elseif ($this->routingIndex !== null) {
            $oid = spl_object_id($partition);
            $cluster = $this->routingIndex[$oid][2] ?? null;
            unset($this->routingIndex[$oid]);
            if ($cluster !== null) {
                unset($this->routingClusters[$cluster][$oid]);
                if ($this->routingClusters[$cluster] === []) {
                    unset($this->routingClusters[$cluster]);
                }
            }
        }
    }

    /**
     * The routing index of the direct partitions, built on first use (RN-06 (i)); null when the index is off.
     *
     * @return array<int, array{0: IPartitionRepository<T>, 1: ISpecification<T>|null, 2: int}>|null
     */
    private function routing(): ?array
    {
        if (!self::$dagRoutingEnabled) {
            return null;
        }
        if ($this->routingIndex === null) {
            $this->routingIndex = [];
            $this->routingClusters = [];
            foreach ($this->subPartitions as $partition) {
                $this->addToRouting($partition);
            }
        }
        return $this->routingIndex;
    }

    /**
     * Places a direct partition in the first cluster whose members are all disjoint with it, or in a
     * new cluster of its own.
     *
     * @param IPartitionRepository<T> $partition
     * @return void
     */
    private function addToRouting(IPartitionRepository $partition): void
    {
        $spec = $partition->getSpecification();
        $target = null;
        if ($spec !== null) {
            foreach ($this->routingClusters as $cluster => $members) {
                $fits = true;
                foreach ($members as $memberOid => $_) {
                    if (!self::mutuallyDisjoint($spec, $this->routingIndex[$memberOid][1])) {
                        $fits = false;
                        break;
                    }
                }
                if ($fits) {
                    $target = $cluster;
                    break;
                }
            }
        }
        $target ??= $this->nextClusterId++;

        $oid = spl_object_id($partition);
        $this->routingIndex[$oid] = [$partition, $spec, $target];
        $this->routingClusters[$target][$oid] = true;
    }

    /**
     * Two partition specifications can share a routing cluster only when the algebra declares them
     * disjoint in both directions; a null specification or a failing algebra call never clusters.
     *
     * @param ISpecification<T>|null $a
     * @param ISpecification<T>|null $b
     * @return bool
     */
    private static function mutuallyDisjoint(?ISpecification $a, ?ISpecification $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }
        try {
            return $a->isDisjointWith($b) && $b->isDisjointWith($a);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The subtree index of this node (RN-06 (ii)), rebuilt when any graph changed since it was built:
     * the nodes in first-visit order (index 0 is this node) with their repositories, specifications,
     * child and parent lists, and their topological order (every parent before its children).
     * Null when the index is off or the subtree cannot be indexed.
     *
     * @return array{nodes: list<PartitionRepository<T>>, repos: list<IRepository<T>>, specs: list<ISpecification<T>|null>, children: list<list<int>>, parents: list<list<int>>, topo: list<int>}|null
     */
    private function subtreePlan(): ?array
    {
        if (!self::$dagIndexEnabled) {
            return null;
        }
        if ($this->subtreeIndex !== null && $this->subtreeIndex['version'] === self::$graphVersion) {
            return $this->subtreeIndex['plan'];
        }

        $plan = $this->buildSubtreePlan();
        $this->subtreeIndex = ['version' => self::$graphVersion, 'plan' => $plan];
        return $plan;
    }

    /**
     * Builds the subtree index (see subtreePlan()).
     *
     * @return array{nodes: list<PartitionRepository<T>>, repos: list<IRepository<T>>, specs: list<ISpecification<T>|null>, children: list<list<int>>, parents: list<list<int>>, topo: list<int>}|null
     */
    private function buildSubtreePlan(): ?array
    {
        if (!isset(self::INDEXABLE_CLASSES[static::class])) {
            return null;
        }

        $ids = [spl_object_id($this) => 0];
        $nodes = [$this];
        $children = [[]];
        $parents = [[]];
        for ($i = 0; $i < count($nodes); $i++) {
            foreach ($nodes[$i]->subPartitions as $child) {
                if (!$child instanceof self || !isset(self::INDEXABLE_CLASSES[$child::class])) {
                    return null;
                }
                $oid = spl_object_id($child);
                if (!isset($ids[$oid])) {
                    $ids[$oid] = count($nodes);
                    $nodes[] = $child;
                    $children[] = [];
                    $parents[] = [];
                }
                $children[$i][] = $ids[$oid];
                $parents[$ids[$oid]][] = $i;
            }
        }

        // Kahn's algorithm, first-in first-out: general to specific, ties in discovery order
        $inDegree = array_map('count', $parents);
        if ($inDegree[0] !== 0) {
            return null;
        }
        $topo = [];
        $queue = [0];
        for ($head = 0; $head < count($queue); $head++) {
            $i = $queue[$head];
            $topo[] = $i;
            foreach ($children[$i] as $c) {
                if (--$inDegree[$c] === 0) {
                    $queue[] = $c;
                }
            }
        }
        if (count($topo) !== count($nodes)) {
            return null; // a cycle: the recursive walk keeps its own behaviour
        }

        $repos = [];
        $specs = [];
        foreach ($nodes as $node) {
            $repos[] = $node->underlyingRepository;
            $specs[] = $node->specification;
        }

        return [
            'nodes' => $nodes,
            'repos' => $repos,
            'specs' => $specs,
            'children' => $children,
            'parents' => $parents,
            'topo' => $topo,
        ];
    }

    /**
     * Moves the entities of this node's own repository that satisfy the specification into the
     * target partition (the target routes them further down its own sub-partitions).
     *
     * @param ISpecification<T> $specification
     * @param IPartitionRepository<T> $target
     * @return void
     */
    protected function moveMatchingEntities(ISpecification $specification, IPartitionRepository $target): void
    {
        foreach ($this->underlyingRepository->findAllEntitiesSpecifiedBy($specification) as $entity) {
            $this->underlyingRepository->remove($entity);
            $target->put($entity);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findPartition(ISpecification $specification): ?IPartitionRepository
    {
        if ($this->specification !== null) {
            if ($this->specsEquivalent($this->specification, $specification)) {
                return $this;
            }
            if (!$specification->isSpecialCaseOf($this->specification)) {
                // Outside this node's boundary: no partition below can generalize the specification
                return null;
            }
        }

        foreach ($this->subPartitions as $child) {
            $childSpec = $child->getSpecification();
            if ($childSpec === null) {
                continue;
            }
            if ($this->specsEquivalent($childSpec, $specification) || $specification->isSpecialCaseOf($childSpec)) {
                $found = $child->findPartition($specification);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        // Most specific partition generalizing the specification (Domian: findPartitionFor returns this)
        return $this;
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
        /** @var SplObjectStorage<IPartitionRepository<T>, null> $visited */
        $visited = new SplObjectStorage();
        $result = [];
        $this->collectDescendants($visited, $result);
        return $result;
    }

    /**
     * Depth-first collection of every descendant partition, each node exactly once (a node shared
     * by several parents is listed once, as Domian's map keyed by specification does).
     *
     * @param SplObjectStorage<IPartitionRepository<T>, null> $visited
     * @param array<IPartitionRepository<T>> $result
     * @return void
     */
    private function collectDescendants(SplObjectStorage $visited, array &$result): void
    {
        foreach ($this->subPartitions as $partition) {
            if ($visited->contains($partition)) {
                continue;
            }
            $visited->attach($partition);
            $result[] = $partition;
            if ($partition instanceof self) {
                $partition->collectDescendants($visited, $result);
            } else {
                foreach ($partition->getAllPartitions() as $descendant) {
                    if (!$visited->contains($descendant)) {
                        $visited->attach($descendant);
                        $result[] = $descendant;
                    }
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function collectPartitions(?ISpecification $filterSpecification = null): array
    {
        if ($filterSpecification === null) {
            return $this->getAllPartitions();
        }

        $filterType = $filterSpecification->getType();
        $filtersRepositories = $filterType !== 'mixed' && is_a($filterType, IRepository::class, true);

        $result = [];
        foreach ($this->getAllPartitions() as $partition) {
            if ($filtersRepositories) {
                // Domian collectAllPartitionsWithRepositorySatisfying(): the specification is
                // evaluated on the partition repository objects themselves
                if ($filterSpecification->isSatisfiedBy($partition)) {
                    $result[] = $partition;
                }
                continue;
            }
            // Entity-typed filter: matches the partitions whose specification is equal to or a
            // special case of the filter (RN-19)
            $spec = $partition->getSpecification();
            if ($spec !== null && ($this->specsEquivalent($spec, $filterSpecification) || $spec->isSpecialCaseOf($filterSpecification))) {
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
        $residents = $this->underlyingRepository->findAllEntitiesSpecifiedBy(new AlwaysTrueSpecification());

        // Domian: a partition reports every entity of its type physically stored in its own
        // repository, whether or not it still satisfies the partition specification (RN-09).
        $type = $this->specification?->getType();
        if ($type === null || $type === 'mixed' || !(class_exists($type) || interface_exists($type))) {
            return array_values($residents);
        }

        $result = [];
        foreach ($residents as $entity) {
            if ($entity instanceof $type) {
                $result[] = $entity;
            }
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function put(IEntity $entity): void
    {
        $routed = false;
        if (self::$dagRoutingEnabled && ($routing = $this->routing()) !== null) {
            // RN-06 (i): once a member of a cluster of disjoint partitions accepts the entity, the
            // other members cannot, so they are not evaluated
            $matched = [];
            foreach ($routing as [$partition, $spec, $cluster]) {
                if (isset($matched[$cluster])) {
                    continue;
                }
                if ($spec !== null && $spec->isSatisfiedBy($entity)) {
                    $partition->put($entity);
                    $routed = true;
                    $matched[$cluster] = true;
                }
            }
        } else {
            foreach ($this->subPartitions as $partition) {
                $spec = $partition->getSpecification();
                if ($spec !== null && $spec->isSatisfiedBy($entity)) {
                    $partition->put($entity);
                    $routed = true;
                }
            }
        }

        if ($routed) {
            return;
        }

        if ($this->specification === null || $this->specification->isSatisfiedBy($entity)) {
            $this->underlyingRepository->put($entity);
            return;
        }

        // Outside this partition's boundary (RN-03): the root decides, and rejects what fits nowhere
        if ($this->isRoot()) {
            throw new InvalidArgumentException('No suitable partition exists for entity ' . $this->describe($entity) . '.');
        }

        $this->rootPartition->put($entity);
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
        $this->updateWhereStored($entity, static function (IRepository $repository) use ($entity): void {
            $repository->update($entity);
        });
        $this->repartition($entity);
    }

    /**
     * {@inheritdoc}
     */
    public function updateWithDelta(IEntity $entity, ?ISpecification $deltaSpecification = null): void
    {
        $this->updateWhereStored($entity, static function (IRepository $repository) use ($entity, $deltaSpecification): void {
            $repository->updateWithDelta($entity, $deltaSpecification);
        });
        $this->repartition($entity);
    }

    /**
     * Applies an update operation to every repository of the graph that stores the entity, so
     * that a persistent partition holding it is rewritten. When the entity is stored nowhere, the
     * operation goes to this node's own repository (Domian: repositoryDelegate.update()) and the
     * subsequent repartitioning routes the entity to its proper partition.
     *
     * @param IEntity $entity
     * @param Closure(IRepository<T>): void $operation
     * @return void
     */
    protected function updateWhereStored(IEntity $entity, Closure $operation): void
    {
        $touched = false;
        foreach ($this->residentRepositoriesOf($entity) as $repository) {
            $operation($repository);
            $touched = true;
        }
        if (!$touched) {
            $operation($this->underlyingRepository);
        }
    }

    /**
     * Returns the repositories of this subtree that physically store the entity, each once.
     *
     * @param IEntity $entity
     * @return array<IRepository<T>>
     */
    protected function residentRepositoriesOf(IEntity $entity): array
    {
        $result = [];
        $nodes = $this->getAllPartitions();
        array_unshift($nodes, $this);
        foreach ($nodes as $node) {
            $repository = $node->getUnderlyingRepository();
            if ($repository->contains($entity) && !in_array($repository, $result, true)) {
                $result[] = $repository;
            }
        }
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function repartition(IEntity $entity): bool
    {
        // Like put(), repartitioning is a graph-wide decision: a non-root node hands it to the root
        if (!$this->isRoot()) {
            return $this->rootPartition->repartition($entity);
        }

        // Domian: an entity that does not exist in the repository is never stored by repartition()
        if (!$this->contains($entity)) {
            return false;
        }

        return $this->repartitionWithin($entity);
    }

    /**
     * In-place repartitioning of an entity known to exist in the graph, following Domian's
     * PartitionRepositoryInvocationHandler.repartition(): the entity stays where it still
     * satisfies the partition specification, descends into sub-partitions it now satisfies, and
     * is removed from partitions it no longer satisfies and handed back to the root for routing.
     *
     * @param IEntity $entity
     * @return bool True when the entity resides in a suitable partition of this subtree afterwards
     */
    protected function repartitionWithin(IEntity $entity): bool
    {
        if (!$this->contains($entity)) {
            return false;
        }

        $repartitioned = false;
        $keepsHere = ($this->specification === null && $this->subPartitions->count() === 0)
            || ($this->specification !== null && $this->specification->isSatisfiedBy($entity));

        if ($keepsHere) {
            // The entity should continue to reside in this partition, unless a sub-partition fits
            $repartitioned = true;
            $specialized = false;
            foreach ($this->subPartitions as $partition) {
                $spec = $partition->getSpecification();
                if ($spec !== null && $spec->isSatisfiedBy($entity)) {
                    $partition->put($entity);
                    $specialized = true;
                }
            }
            if ($specialized) {
                $this->underlyingRepository->remove($entity);
            }
        } else {
            // The entity should not reside in this partition: the root routes it again
            // (back into this same node when nothing more specific fits)
            $this->underlyingRepository->remove($entity);
            $this->rootPartition->put($entity);
            $repartitioned = true;
        }

        foreach ($this->subPartitions as $partition) {
            $moved = $partition instanceof self
                ? $partition->repartitionWithin($entity)
                : $partition->repartition($entity);
            if ($moved) {
                $repartitioned = true;
            }
        }

        return $repartitioned;
    }

    /**
     * {@inheritdoc}
     */
    public function repartitionAll(): int
    {
        $all = $this->findAllEntitiesSpecifiedBy(new AlwaysTrueSpecification());
        $root = $this->rootPartition;
        $relocated = 0;
        foreach ($all as $entity) {
            $before = $root instanceof self ? $root->residenceKeysOf($entity) : [];
            $this->repartition($entity);
            $after = $root instanceof self ? $root->residenceKeysOf($entity) : [];
            if ($before !== $after) {
                $relocated++;
            }
        }
        return $relocated;
    }

    /**
     * Identifies the repositories of this subtree that store the entity (sorted object ids), so
     * that a change of residence can be detected.
     *
     * @param IEntity $entity
     * @return array<int>
     */
    protected function residenceKeysOf(IEntity $entity): array
    {
        $keys = [];
        foreach ($this->residentRepositoriesOf($entity) as $repository) {
            $keys[] = spl_object_id($repository);
        }
        sort($keys);
        return $keys;
    }

    /**
     * {@inheritdoc}
     */
    public function remove(IEntity $entity): bool
    {
        $removed = false;

        $plan = $this->subtreePlan();
        if ($plan !== null) {
            // RN-06 (ii): every repository of the subtree once, in topological order (RN-07)
            foreach ($plan['topo'] as $i) {
                if ($plan['repos'][$i]->remove($entity)) {
                    $removed = true;
                }
            }
            return $removed;
        }

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

        $plan = $this->subtreePlan();
        if ($plan !== null) {
            // RN-06 (ii): topological order; a node is visited when a visited parent reaches it and it
            // is not disjoint with the specification (the same nodes the recursive walk prunes)
            $active = [];
            $totalRemoved = 0;
            foreach ($plan['topo'] as $i) {
                if ($i !== 0) {
                    $reached = false;
                    foreach ($plan['parents'][$i] as $parent) {
                        if (isset($active[$parent])) {
                            $reached = true;
                            break;
                        }
                    }
                    $spec = $plan['specs'][$i];
                    if (!$reached || ($spec !== null && $spec->isDisjointWith($specification))) {
                        continue;
                    }
                }
                $active[$i] = true;
                $totalRemoved += $plan['repos'][$i]->removeAllEntitiesSpecifiedBy($specification);
            }
            return $totalRemoved;
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
     * Yields on demand the entities of this node and of its partitions that satisfy the
     * specification, each entity once (RN-05). Nothing is materialized: the node's own repository
     * is consumed lazily, then each partition's own generator, so the first element costs only the
     * evaluations needed to reach it. Partitions attached to a node while it is being iterated are
     * visited as well (RN-18); disjoint partitions are pruned without evaluating any entity (RN-04).
     *
     * @param ISpecification<T> $specification Filter specification
     * @return Generator<int, T>
     */
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        $this->validateSpecification($specification);

        if ($this->specification !== null && $this->specification->isDisjointWith($specification)) {
            return;
        }

        $plan = $this->subtreePlan();
        if ($plan === null) {
            foreach ($this->iterateRecursively($specification) as $entity) {
                yield $entity;
            }
            return;
        }

        // RN-06 (ii): depth-first walk with an explicit stack over the index, each node once, in the
        // order of the recursive walk; one duplicate filter for the whole subtree
        $version = self::$graphVersion;
        $seen = [];
        $visited = [];
        $stack = [0];
        $stale = false;
        while ($stack !== []) {
            $i = array_pop($stack);
            if (isset($visited[$i])) {
                continue;
            }
            $visited[$i] = true;
            if ($i !== 0) {
                $spec = $plan['specs'][$i];
                if ($spec !== null && $spec->isDisjointWith($specification)) {
                    continue;
                }
            }

            foreach ($plan['repos'][$i]->iterateAllEntitiesSpecifiedBy($specification) as $entity) {
                $key = self::identityKey($entity);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                yield $entity;
            }

            if (self::$graphVersion !== $version) {
                $stale = true;
                break;
            }

            $kids = $plan['children'][$i];
            for ($k = count($kids) - 1; $k >= 0; $k--) {
                if (!isset($visited[$kids[$k]])) {
                    $stack[] = $kids[$k];
                }
            }
        }

        if ($stale) {
            // The graph changed while the consumer held the iteration (RN-18): the live recursive
            // walk finishes it, the duplicate filter keeps what was already yielded out
            foreach ($this->iterateRecursively($specification) as $entity) {
                $key = self::identityKey($entity);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                yield $entity;
            }
        }
    }

    /**
     * The recursive walk of 1.4.4 (index off, user node classes, or a graph changed mid-iteration):
     * the node's own repository, then each partition's own generator, re-scanning the partitions of
     * this node until no new one appears (RN-18).
     *
     * @param ISpecification<T> $specification
     * @return Generator<int, T>
     */
    private function iterateRecursively(ISpecification $specification): Generator
    {
        $seen = [];

        foreach ($this->underlyingRepository->iterateAllEntitiesSpecifiedBy($specification) as $entity) {
            $key = self::identityKey($entity);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            yield $entity;
        }

        /** @var SplObjectStorage<IPartitionRepository<T>, null> $visited */
        $visited = new SplObjectStorage();
        do {
            $pending = [];
            foreach ($this->subPartitions as $partition) {
                if (!$visited->contains($partition)) {
                    $pending[] = $partition;
                }
            }
            foreach ($pending as $partition) {
                $visited->attach($partition);
                foreach ($partition->iterateAllEntitiesSpecifiedBy($specification) as $entity) {
                    $key = self::identityKey($entity);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    yield $entity;
                }
            }
        } while ($pending !== []);
    }

    /**
     * {@inheritdoc}
     */
    public function findAllEntitiesSpecifiedBy(ISpecification $specification): array
    {
        $this->validateSpecification($specification);

        try {
            $entities = [];
            foreach ($this->iterateAllEntitiesSpecifiedBy($specification) as $entity) {
                $entities[] = $entity;
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

    /**
     * Key identifying an entity across partitions: its scalar identifier, or the object itself
     * when the identifier is not scalar (the same rule InMemoryRepository uses to store it).
     *
     * @param IEntity $entity
     * @return string
     */
    protected static function identityKey(IEntity $entity): string
    {
        $id = $entity->getEntityId();
        return is_scalar($id) ? 'id:' . (string) $id : 'obj:' . spl_object_hash($entity);
    }

    /**
     * Short textual form of an entity for error messages.
     *
     * @param IEntity $entity
     * @return string
     */
    private function describe(IEntity $entity): string
    {
        $id = $entity->getEntityId();
        return get_class($entity) . (is_scalar($id) ? "#{$id}" : '');
    }
}
