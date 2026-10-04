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
 * PartitionRepository - Repositório particionado em grafo acíclico dirigido (DAG)
 *
 * Implementação do padrão Decorator / Composite estrutural para particionamento de repositórios.
 * Delimita subconjuntos de entidades através de ISpecifications, otimizando consultas via
 * descarte antecipado O(1) de partições disjuntas e roteando inserções e remoções.
 *
 * Funcionalidades:
 * - Gerenciamento de nós e arestas em grafo acíclico via SplObjectStorage
 * - Preservação de marcadores semânticos (Volatile, Persistent, Fake e formatos)
 * - Roteamento hierárquico de inserções (put/putAll)
 * - Busca agregada com descarte de partições disjuntas e eliminação de duplicatas
 * - Remoção consistente em partições irmãs (RN-07)
 * - Reparticionamento dinâmico de entidades alteradas
 *
 * @template T of IEntity
 * @extends AbstractRepository<T>
 * @implements IPartitionRepository<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
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
     * Construtor de PartitionRepository.
     *
     * @param IRepository<T> $underlyingRepository Repositório alvo encapsulado
     * @param ISpecification<T>|null $specification Especificação delimitadora desta partição
     * @param IPartitionRepository<T>|null $parentRepository Repositório particionado pai
     * @param string|null $entityType FQN do tipo de entidade gerenciado
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
     * Fábrica polimórfica que preserva a classificação semântica do repositório base.
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
            throw new InvalidArgumentException('A especificação da partição não pode ser nula.');
        }

        if ($this->underlyingRepository instanceof IPersistentRepository) {
            throw new InvalidArgumentException('Identificador obrigatório para adicionar partição em repositório persistente.');
        }

        // Instancia novo repositório irmão do mesmo tipo
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
            throw new InvalidArgumentException('A especificação da partição não pode ser nula.');
        }

        if (trim($partitionId) === '') {
            throw new InvalidArgumentException('O identificador da partição não pode ser vazio.');
        }

        if ($this->underlyingRepository instanceof IPersistentRepository) {
            if ($partitionId === $this->underlyingRepository->getRepositoryId()) {
                throw new InvalidArgumentException("O identificador '{$partitionId}' não pode repetir o do repositório pai.");
            }
        }

        // Instancia novo repositório
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
            throw new InvalidArgumentException('A especificação da partição não pode ser nula.');
        }

        if ($repository === null) {
            throw new InvalidArgumentException('O repositório da partição não pode ser nulo.');
        }

        // Algoritmo de posicionamento no grafo por subsunção (RN-02)
        $subsumed = false;
        $partitionsToRemove = [];

        foreach ($this->subPartitions as $existingPartition) {
            $existingSpec = $existingPartition->getSpecification();
            if ($existingSpec === null) {
                continue;
            }

            // (a) Equivalente: substitui P
            if ($this->specsEquivalent($specification, $existingSpec)) {
                $newPartition = self::create($repository, $specification, $this, $this->entityType);
                // Migra todas as entidades
                foreach ($existingPartition->findAllEntitiesSpecifiedBy($specification) as $entity) {
                    $newPartition->put($entity);
                }
                $this->subPartitions->detach($existingPartition);
                $this->subPartitions->attach($newPartition);
                return $newPartition;
            }

            // (b) Caso especial de P: adiciona recursivamente dentro de P
            if ($specification->isSpecialCaseOf($existingSpec)) {
                $existingPartition->addPartitionWithRepository($specification, $repository);
                $subsumed = true;
            }

            // (c) S generaliza P: nova partição é inserida entre o nó e P
            if ($specification->isGeneralizationOf($existingSpec)) {
                $partitionsToRemove[] = $existingPartition;
            }
        }

        if ($subsumed) {
            return $this->findPartition($specification) ?? $this;
        }

        // Cria nova partição como filha direta
        $newPartition = self::create($repository, $specification, $this, $this->entityType);

        if (!empty($partitionsToRemove)) {
            foreach ($partitionsToRemove as $p) {
                $this->subPartitions->detach($p);
                $newPartition->addPartitionWithRepository($p->getSpecification(), $p->getUnderlyingRepository());
            }
        }

        // Migra entidades do nó atual que satisfazem a nova partição (RN-02 c/d)
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
        // Validação na raiz (RN-03)
        if ($this->isRoot() && $this->specification !== null) {
            if (!$this->specification->isSatisfiedBy($entity)) {
                throw new InvalidArgumentException('Entidade não satisfaz a especificação definida para a raiz.');
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

        // Se não roteada para nenhuma partição filha, guarda neste nó
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
        // Localiza onde a entidade está atualmente
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

        // Remove em todas as partições filhas (RN-07)
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

        // Descarte O(1) se disjunta da partição deste nó (RN-06)
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
            // Descarte antecipado O(1) se disjunta (RN-04)
            if ($this->specification !== null && $this->specification->isDisjointWith($specification)) {
                return [];
            }

            $entities = [];
            $visitedIds = [];

            // Entidades locais do nó
            foreach ($this->underlyingRepository->findAllEntitiesSpecifiedBy($specification) as $entity) {
                $id = $entity->getEntityId();
                if (!isset($visitedIds[$id])) {
                    $visitedIds[$id] = true;
                    $entities[] = $entity;
                }
            }

            // Agrega entidades das partições filhas evitando duplicações (RN-05)
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
            throw new RepositoryException("Falha na operação de consulta no repositório particionado: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Cria ou anexa uma sub-partição no grafo a partir da especificação informada.
     *
     * @param ISpecification|null $specification Especificação delimitadora da partição
     * @return IPartitionRepository Sub-partição criada ou esta instância caso a especificação seja nula
     */
    public function makePartition(?ISpecification $specification = null): IPartitionRepository
    {
        if ($specification !== null) {
            return $this->addPartition($specification);
        }
        return $this;
    }

    protected function specsEquivalent(ISpecification $a, ISpecification $b): bool
    {
        if (method_exists($a, 'equals')) {
            return $a->equals($b);
        }
        return get_class($a) === get_class($b) && (string)$a === (string)$b;
    }
}
