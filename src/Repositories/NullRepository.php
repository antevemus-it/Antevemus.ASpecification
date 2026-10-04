<?php

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IVolatileRepository;

/**
 * NullRepository class.
 *
 * Implementação do NullObject Pattern para o contrato de Repositório.
 * Não armazena nada, não retorna nada, não levanta exceções. 
 * É desenhado puramente para mocks estruturais rápidos ou serviços inócuos 
 * que obrigatoriamente dependam da injeção de dependência de um IRepository.
 *
 * Funcionalidades:
 * - Implementação inócua de todas as operações de persistência e consulta
 * - Retornos neutros seguros (0, array vazio, null, false)
 * - Validações de especificação mantidas para conformidade de contrato
 *
 * @template T of IEntity
 * @extends AbstractRepository<T>
 * @implements IVolatileRepository<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class NullRepository extends AbstractRepository implements IVolatileRepository
{
    /**
     * Retorna sempre zero (Null Object).
     *
     * @param ISpecification $specification Regra de filtragem
     * @return int Sempre 0
     */
    public function countAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        $this->validateSpecification($specification);
        return 0;
    }

    /**
     * Retorna um iterável vazio (Null Object).
     *
     * @param ISpecification $specification Regra de filtragem
     * @return iterable<T> Sempre vazio
     */
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        $this->validateSpecification($specification);
        return []; // Em PHP, array vazio é um iterable válido sem yield
    }

    /**
     * Retorna uma lista vazia (Null Object).
     *
     * @param ISpecification $specification Regra de filtragem
     * @return array<T> Sempre vazio
     */
    public function findAllEntitiesSpecifiedBy(ISpecification $specification): array
    {
        $this->validateSpecification($specification);
        return [];
    }

    /**
     * Retorna sempre null (Null Object).
     *
     * @param ISpecification $specification Regra de filtragem
     * @return null
     */
    public function findSingleEntitySpecifiedBy(ISpecification $specification): ?IEntity
    {
        $this->validateSpecification($specification);
        return null;
    }

    /**
     * Operação inócua (Null Object).
     *
     * @param IEntity $entity
     * @return void
     */
    public function put(IEntity $entity): void
    {
        // Null Object Pattern - não faz nada
    }

    /**
     * Operação inócua (Null Object).
     *
     * @param array<IEntity> $collectionOfEntities
     * @return void
     */
    public function putAll(array $collectionOfEntities): void
    {
        // Null Object Pattern - não faz nada
    }

    /**
     * Operação inócua (Null Object).
     *
     * @param IEntity $entity
     * @return void
     */
    public function update(IEntity $entity): void
    {
        // Null Object Pattern - não faz nada
    }

    /**
     * Operação inócua (Null Object).
     *
     * @param IEntity $entity
     * @param ISpecification|null $deltaSpecification
     * @return void
     */
    public function updateWithDelta(IEntity $entity, ?ISpecification $deltaSpecification = null): void
    {
        // Null Object Pattern - não faz nada
    }

    /**
     * Retorna sempre 0 (Null Object).
     *
     * @param ISpecification $specification
     * @return int Sempre 0
     */
    public function removeAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        $this->validateSpecification($specification);
        return 0;
    }

    /**
     * Retorna sempre false (Null Object).
     *
     * @param IEntity $entity
     * @return bool Sempre false
     */
    public function remove(IEntity $entity): bool
    {
        return false;
    }
}
