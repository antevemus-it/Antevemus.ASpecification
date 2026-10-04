<?php

namespace Antevemus\ASpecification\Contracts\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use InvalidArgumentException;
use RuntimeException;

/**
 * Interface IRepository.
 *
 * Contrato definindo um repositório para armazenamento e recuperação
 * de objetos de entidade (IEntity). Todas as buscas e deleções em lote
 * são orientadas a objetos de Especificação.
 *
 * @template T of IEntity
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IRepository
{
    ///////////////////////////////////////////////////////////////////////////
    // Operações de Repositório
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Conta o número de entidades aprovadas pela especificação dada.
     *
     * @param ISpecification<T> $specification
     * @return int
     * @throws InvalidArgumentException
     */
    public function countAllEntitiesSpecifiedBy(ISpecification $specification): int;

    /** Alias de countAllEntitiesSpecifiedBy */
    public function countAll(ISpecification $specification): int;

    /** Alias de countAllEntitiesSpecifiedBy */
    public function count(ISpecification $specification): int;

    /**
     * Encontra e retorna todas as entidades aprovadas pela especificação através
     * de iteração lazy (Generator), economizando RAM.
     *
     * @param ISpecification<T> $specification
     * @return iterable<T>
     * @throws InvalidArgumentException
     */
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable;

    /** Alias de iterateAllEntitiesSpecifiedBy */
    public function iterateAll(ISpecification $specification): iterable;

    /** Alias de iterateAllEntitiesSpecifiedBy */
    public function iterate(ISpecification $specification): iterable;

    /**
     * Encontra e retorna todas as entidades aprovadas pela especificação em um array maciço.
     *
     * @param ISpecification<T> $specification
     * @return array<T>
     * @throws InvalidArgumentException
     */
    public function findAllEntitiesSpecifiedBy(ISpecification $specification): array;

    /** Alias de findAllEntitiesSpecifiedBy */
    public function findAll(ISpecification $specification): array;

    /** Alias de findAllEntitiesSpecifiedBy */
    public function find(ISpecification $specification): array;

    /**
     * Encontra e retorna uma única entidade que atenda à especificação.
     *
     * @param ISpecification<T> $specification
     * @return T|null
     * @throws RuntimeException Se mais de uma entidade corresponder à especificação
     * @throws InvalidArgumentException
     */
    public function findSingleEntitySpecifiedBy(ISpecification $specification): ?IEntity;

    /** Alias de findSingleEntitySpecifiedBy */
    public function findSingle(ISpecification $specification): ?IEntity;

    /**
     * Insere a entidade informada neste repositório.
     *
     * @param T $entity A entidade a ser guardada
     */
    public function put(IEntity $entity): void;

    /**
     * Insere múltiplas entidades neste repositório.
     *
     * @param array<T> $collectionOfEntities
     * @throws InvalidArgumentException
     */
    public function putAll(array $collectionOfEntities): void;

    /**
     * Atualiza uma entidade existente.
     *
     * @param T $entity A entidade a ser atualizada
     */
    public function update(IEntity $entity): void;

    /**
     * Atualiza uma entidade existente, fornecendo uma specification de delta
     * para locks otimistas (Optimistic Locking).
     *
     * @param T $entity A entidade
     * @param ISpecification|null $deltaSpecification Specificação das diferenças
     */
    public function updateWithDelta(IEntity $entity, ?ISpecification $deltaSpecification = null): void;

    /**
     * Remove todas as entidades aprovadas pela especificação informada.
     *
     * @param ISpecification<T> $specification
     * @return int O número de entidades removidas
     * @throws InvalidArgumentException
     */
    public function removeAllEntitiesSpecifiedBy(ISpecification $specification): int;

    /** Alias de removeAllEntitiesSpecifiedBy */
    public function removeAll(ISpecification $specification): int;

    /** Alias de removeAllEntitiesSpecifiedBy */
    public function removeBy(ISpecification $specification): int;

    /**
     * Remove a entidade específica fornecida.
     *
     * @param T $entity
     * @return bool True se encontrada e removida, False se não estava presente
     */
    public function remove(IEntity $entity): bool;

    /**
     * Informa se este repositório possui capacidade nativa de particionamento (reuso de instância).
     *
     * @return bool
     */
    public function isNativelyPartitioned(): bool;

    /**
     * Informa se o repositório indexa recursivamente entidades membro.
     *
     * @return bool
     */
    public function isRecursivelyIndexing(): bool;

    /**
     * Promove este repositório para um repositório particionado em grafo (DAG),
     * preservando todas as suas classificações semânticas (Volátil, Persistente, Formato, Fake).
     *
     * @param ISpecification|null $specification Especificação delimitadora da raiz (opcional)
     * @return IPartitionRepository
     */
    public function makePartition(?ISpecification $specification = null): IPartitionRepository;
}
