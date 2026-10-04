<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use InvalidArgumentException;

/**
 * IPartitionRepository - Contrato de repositório particionado em grafo
 *
 * Contrato para nós de um repositório particionado estruturado como Grafo Acíclico Dirigido (DAG).
 * Cada partição delimita um subconjunto de entidades por meio de uma ISpecification,
 * otimizando consultas via descarte antecipado O(1) de ramos disjuntos e
 * direcionando inserções e remoções de forma hierárquica por subsunção.
 *
 * Funcionalidades:
 * - Identificação estrutural (isRoot, isLeaf, getRootPartition, getParentRepository)
 * - Consulta de especificações de delimitação (getSpecification, getParentSpecification)
 * - Acesso ao repositório subjacente encapsulado (getUnderlyingRepository)
 * - Adição de partições por especificação, com identificador e com repositório customizado
 * - Localização inteligente de partição por especificação (findPartition)
 * - Obtenção de partições diretas, todas as partições e filtragem de partições
 * - Obtenção exclusiva de entidades locais (getEntitiesOfThisPartitionOnly)
 * - Reparticionamento dinâmico de entidade e do repositório completo
 *
 * @template T of IEntity
 * @extends IRepository<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IPartitionRepository extends IRepository
{
    /**
     * Retorna o tipo de entidade (FQN da classe ou interface) gerenciada por este repositório.
     */
    public function getEntityType(): string;

    /**
     * Informa se este nó de partição é a raiz do grafo.
     */
    public function isRoot(): bool;

    /**
     * Retorna o nó raiz deste grafo de partições.
     *
     * @return IPartitionRepository<T>
     */
    public function getRootPartition(): IPartitionRepository;

    /**
     * Informa se este nó de partição é folha (sem sub-partições filhas).
     */
    public function isLeaf(): bool;

    /**
     * Retorna o repositório particionado pai deste nó, ou null se este nó for a raiz.
     *
     * @return IPartitionRepository<T>|null
     */
    public function getParentRepository(): ?IPartitionRepository;

    /**
     * Retorna a especificação associada ao nó pai, ou null se este nó for a raiz.
     *
     * @return ISpecification<T>|null
     */
    public function getParentSpecification(): ?ISpecification;

    /**
     * Retorna a especificação que delimita esta partição, ou null caso seja uma raiz irrestrita.
     *
     * @return ISpecification<T>|null
     */
    public function getSpecification(): ?ISpecification;

    /**
     * Retorna o repositório subjacente (alvo) encapsulado por este nó.
     *
     * @return IRepository<T>
     */
    public function getUnderlyingRepository(): IRepository;

    /**
     * Adiciona uma nova partição delimitada pela especificação informada.
     * Instancia automaticamente um repositório compatível do mesmo tipo da base.
     *
     * @param ISpecification<T> $specification Especificação delimitadora da partição
     * @return IPartitionRepository<T> A partição criada e posicionada no grafo
     * @throws InvalidArgumentException Se a especificação for nula ou inválida
     */
    public function addPartition(ISpecification $specification): IPartitionRepository;

    /**
     * Adiciona uma nova partição informando a especificação delimitadora e um identificador explícito.
     *
     * @param ISpecification<T> $specification Especificação delimitadora da partição
     * @param string $partitionId Identificador único da partição (obrigatório em repositórios persistentes)
     * @return IPartitionRepository<T> A partição criada e posicionada no grafo
     * @throws InvalidArgumentException Se a especificação for nula ou o identificador for inválido/repetido
     */
    public function addPartitionWithId(ISpecification $specification, string $partitionId): IPartitionRepository;

    /**
     * Adiciona uma nova partição informando a especificação e a instância concreta de repositório a utilizar.
     *
     * @param ISpecification<T> $specification Especificação delimitadora da partição
     * @param IRepository<T> $repository Repositório concreto que armazenará as entidades da partição
     * @return IPartitionRepository<T> A partição criada e posicionada no grafo
     * @throws InvalidArgumentException Se a especificação ou repositório forem nulos/inválidos
     */
    public function addPartitionWithRepository(ISpecification $specification, IRepository $repository): IPartitionRepository;

    /**
     * Localiza a partição mais especializada que corresponde ou generaliza a especificação informada.
     *
     * @param ISpecification<T> $specification Especificação buscada
     * @return IPartitionRepository<T>|null A partição encontrada ou null
     */
    public function findPartition(ISpecification $specification): ?IPartitionRepository;

    /**
     * Retorna a lista de partições filhas diretas deste nó.
     *
     * @return array<IPartitionRepository<T>>
     */
    public function getDirectPartitions(): array;

    /**
     * Retorna todas as partições sob este nó em profundidade no grafo.
     *
     * @return array<IPartitionRepository<T>>
     */
    public function getAllPartitions(): array;

    /**
     * Coleta partições sob este nó, opcionalmente filtrando por uma especificação.
     *
     * @param ISpecification<T>|null $filterSpecification
     * @return array<IPartitionRepository<T>>
     */
    public function collectPartitions(?ISpecification $filterSpecification = null): array;

    /**
     * Retorna apenas as entidades residentes diretamente na coleção deste nó,
     * sem agregar entidades de sub-partições filhas.
     *
     * @return array<T>
     */
    public function getEntitiesOfThisPartitionOnly(): array;

    /**
     * Reparticiona uma entidade específica após mudança em seu estado interno,
     * realocando-a para as partições adequadas e removendo-a das partições que não satisfaz mais.
     *
     * @param T $entity Entidade a ser reavaliada
     * @return bool True se a entidade mudou de partição, False caso contrário
     */
    public function repartition(IEntity $entity): bool;

    /**
     * Reparticiona todas as entidades deste nó e de suas sub-partições filhas.
     *
     * @return int Quantidade total de entidades que foram realocadas
     */
    public function repartitionAll(): int;
}
