<?php

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IVolatileRepository;
use InvalidArgumentException;

/**
 * InMemoryRepository class.
 *
 * Repositório Volátil baseado em memória (array associativo interno).
 * As buscas operam com custo O(N) e não há concorrência protegida por ser in-memory process.
 * Ideal para armazenamento temporário, cache transacional local ou baterias pesadas de Unit Tests.
 *
 * Funcionalidades:
 * - Armazenamento volátil rápido em memória indexado por hash/ID
 * - Filtros síncronos via Specification com iteração lazy (yield) e contagem O(N)
 * - Inserções e remoções idempotentes
 * - Suporte a limpeza atômica rápida via clear()
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
class InMemoryRepository extends AbstractRepository implements IVolatileRepository
{
    /** @var array<string, T> Storage principal map hash->object */
    protected array $db = [];

    /**
     * Construtor do repositório em memória.
     *
     * @param array<T> $initialEntities Conjunto inicial de entidades para pré-popular o repositório
     */
    public function __construct(array $initialEntities = [])
    {
        if (!empty($initialEntities)) {
            $this->putAll($initialEntities);
        }
    }

    /**
     * Conta quantas entidades no repositório satisfazem a especificação.
     *
     * @param ISpecification $specification Regra de filtragem
     * @return int Quantidade de entidades correspondentes
     */
    public function countAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        $this->validateSpecification($specification);
        $count = 0;
        foreach ($this->db as $entity) {
            if ($specification->isSatisfiedBy($entity)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Itera sob demanda (lazy) sobre todas as entidades que satisfazem a especificação.
     *
     * @param ISpecification $specification Regra de filtragem
     * @return iterable<T> Gerador lazy das entidades correspondentes
     */
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        $this->validateSpecification($specification);
        foreach ($this->db as $entity) {
            if ($specification->isSatisfiedBy($entity)) {
                yield $entity;
            }
        }
    }

    /**
     * Localiza e retorna em array todas as entidades que satisfazem a especificação.
     *
     * @param ISpecification $specification Regra de filtragem
     * @return array<T> Lista de entidades encontradas
     */
    public function findAllEntitiesSpecifiedBy(ISpecification $specification): array
    {
        $this->validateSpecification($specification);
        $results = [];
        foreach ($this->db as $entity) {
            if ($specification->isSatisfiedBy($entity)) {
                $results[] = $entity;
            }
        }
        return $results;
    }

    /**
     * Insere ou substitui uma entidade no armazenamento em memória.
     *
     * @param IEntity $entity Entidade a ser armazenada
     * @return void
     */
    public function put(IEntity $entity): void
    {
        if ($entity !== null) {
            // Utiliza o ID da entidade (se convertível em string/int) ou o hash do objeto
            $id = $entity->getEntityId();
            $key = (is_scalar($id)) ? (string) $id : spl_object_hash($entity);
            $this->db[$key] = $entity;
        }
    }

    /**
     * Insere uma coleção de entidades no repositório.
     *
     * @param array<IEntity> $collectionOfEntities Coleção de entidades
     * @return void
     * @throws InvalidArgumentException Se algum item não implementar IEntity
     */
    public function putAll(array $collectionOfEntities): void
    {
        foreach ($collectionOfEntities as $entity) {
            if (!$entity instanceof IEntity) {
                throw new InvalidArgumentException("Todos os itens devem implementar IEntity.");
            }
            $this->put($entity);
        }
    }

    /**
     * Atualiza o estado da entidade no repositório em memória.
     *
     * @param IEntity $entity Entidade atualizada
     * @return void
     */
    public function update(IEntity $entity): void
    {
        // Em repositórios de memória RAM (pointer references), updates da aplicação já afetam a entidade.
        // Contudo, fazemos o replace da instância caso venha um clone ou override pela interface.
        $this->put($entity);
    }

    /**
     * Atualiza a entidade considerando uma especificação delta condicional.
     *
     * @param IEntity $entity Entidade a ser atualizada
     * @param ISpecification|null $deltaSpecification Especificação condicional opcional
     * @return void
     */
    public function updateWithDelta(IEntity $entity, ?ISpecification $deltaSpecification = null): void
    {
        // Semelhante ao update básico num ambiente em memória
        $this->put($entity);
    }

    /**
     * Remove todas as entidades que satisfazem a especificação informada.
     *
     * @param ISpecification $specification Regra para seleção de remoção
     * @return int Quantidade total de entidades removidas
     */
    public function removeAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        $this->validateSpecification($specification);
        $removed = 0;
        foreach ($this->db as $key => $entity) {
            if ($specification->isSatisfiedBy($entity)) {
                unset($this->db[$key]);
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Remove uma entidade específica do armazenamento.
     *
     * @param IEntity $entity Entidade a ser removida
     * @return bool True se a entidade foi encontrada e removida, false caso contrário
     */
    public function remove(IEntity $entity): bool
    {
        if ($entity === null) {
            return false;
        }
        
        $id = $entity->getEntityId();
        $key = (is_scalar($id)) ? (string) $id : spl_object_hash($entity);

        if (array_key_exists($key, $this->db)) {
            unset($this->db[$key]);
            return true;
        }
        
        // Fallback pro-ativo em caso de falha no hash relacional
        foreach ($this->db as $k => $e) {
            if ($e->equals($entity)) {
                unset($this->db[$k]);
                return true;
            }
        }
        
        return false;
    }

    /**
     * Limpa o repositório inteiro rapidamente (custo O(1)).
     *
     * @return void
     */
    public function clear(): void
    {
        $this->db = [];
    }

    /**
     * Retorna todas as entidades armazenadas como um array puro.
     *
     * @return array<T>
     */
    public function getAll(): array
    {
        return array_values($this->db);
    }

    /**
     * Retorna todas as entidades armazenadas como uma ALinqCollection fluente.
     *
     * @return object Retorna instância de \Antevemus\ALinq\ALinqCollection
     */
    public function asLinqCollection(): object
    {
        return \Antevemus\ASpecification\Linq\ALinqBridge::toCollection($this->db);
    }

    /**
     * Localiza todas as entidades que satisfazem a especificação retornando uma ALinqCollection.
     *
     * @param ISpecification $specification
     * @return object Retorna instância de \Antevemus\ALinq\ALinqCollection
     */
    public function findAsLinqCollection(ISpecification $specification): object
    {
        return \Antevemus\ASpecification\Linq\ALinqBridge::queryRepository($this, $specification);
    }
}
