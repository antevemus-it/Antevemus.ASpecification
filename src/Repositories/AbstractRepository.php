<?php

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IPartitionRepository;
use Antevemus\ASpecification\Contracts\Repositories\IRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * AbstractRepository class.
 *
 * Classe abstrata base que implementa as conexões de alias e o comportamento de rotina
 * de proteção dos contratos mestre (IRepository).
 *
 * Funcionalidades:
 * - Provê atalhos fluentes (count, iterate, find, findSingle, removeBy) mapeados aos contratos canônicos
 * - Implementa findSingleEntitySpecifiedBy com validação estrita de cardinalidade unitária
 * - Validações estruturais de especificação
 * - Fábrica de partições virtuais via makePartition
 *
 * @template T of IEntity
 * @implements IRepository<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractRepository implements IRepository
{
    /**
     * Alias fluente para countAllEntitiesSpecifiedBy().
     *
     * @param ISpecification $specification Especificação de filtragem
     * @return int Quantidade total de entidades que atendem à regra
     */
    public function countAll(ISpecification $specification): int
    {
        return $this->countAllEntitiesSpecifiedBy($specification);
    }

    /**
     * Alias curto para countAllEntitiesSpecifiedBy().
     *
     * @param ISpecification $specification Especificação de filtragem
     * @return int Quantidade total de entidades que atendem à regra
     */
    public function count(ISpecification $specification): int
    {
        return $this->countAllEntitiesSpecifiedBy($specification);
    }

    /**
     * Alias fluente para iterateAllEntitiesSpecifiedBy().
     *
     * @param ISpecification $specification Especificação de filtragem
     * @return iterable<T> Gerador/iterável lazy de entidades
     */
    public function iterateAll(ISpecification $specification): iterable
    {
        return $this->iterateAllEntitiesSpecifiedBy($specification);
    }

    /**
     * Alias curto para iterateAllEntitiesSpecifiedBy().
     *
     * @param ISpecification $specification Especificação de filtragem
     * @return iterable<T> Gerador/iterável lazy de entidades
     */
    public function iterate(ISpecification $specification): iterable
    {
        return $this->iterateAllEntitiesSpecifiedBy($specification);
    }

    /**
     * Alias fluente para findAllEntitiesSpecifiedBy().
     *
     * @param ISpecification $specification Especificação de filtragem
     * @return array<T> Lista com todas as entidades encontradas
     */
    public function findAll(ISpecification $specification): array
    {
        return $this->findAllEntitiesSpecifiedBy($specification);
    }

    /**
     * Alias curto para findAllEntitiesSpecifiedBy().
     *
     * @param ISpecification $specification Especificação de filtragem
     * @return array<T> Lista com todas as entidades encontradas
     */
    public function find(ISpecification $specification): array
    {
        return $this->findAllEntitiesSpecifiedBy($specification);
    }

    /**
     * Alias curto para findSingleEntitySpecifiedBy().
     *
     * @param ISpecification $specification Especificação de filtragem
     * @return T|null A entidade única correspondente, ou null se não encontrada
     * @throws RuntimeException Se mais de uma entidade for encontrada
     */
    public function findSingle(ISpecification $specification): ?IEntity
    {
        return $this->findSingleEntitySpecifiedBy($specification);
    }

    /**
     * Busca uma única entidade que atenda à especificação fornecida, validando cardinalidade unitária.
     *
     * @param ISpecification $specification Especificação que define o filtro
     * @return T|null A entidade única encontrada, ou null se nenhuma atender à regra
     * @throws RuntimeException Se mais de uma entidade satisfizer a especificação
     */
    public function findSingleEntitySpecifiedBy(ISpecification $specification): ?IEntity
    {
        $allFound = $this->findAllEntitiesSpecifiedBy($specification);
        $count = count($allFound);

        if ($count > 1) {
            throw new RuntimeException("Espera-se uma única entidade como resultado, mas foram encontradas " . $count);
        }

        if ($count === 0) {
            return null;
        }

        // Retorna o primeiro e único item
        return reset($allFound);
    }

    /**
     * Alias fluente para removeAllEntitiesSpecifiedBy().
     *
     * @param ISpecification $specification Especificação das entidades a serem removidas
     * @return int Quantidade de entidades removidas
     */
    public function removeAll(ISpecification $specification): int
    {
        return $this->removeAllEntitiesSpecifiedBy($specification);
    }

    /**
     * Alias curto para removeAllEntitiesSpecifiedBy().
     *
     * @param ISpecification $specification Especificação das entidades a serem removidas
     * @return int Quantidade de entidades removidas
     */
    public function removeBy(ISpecification $specification): int
    {
        return $this->removeAllEntitiesSpecifiedBy($specification);
    }

    /**
     * Valida que a especificação fornecida não é nula.
     *
     * @param ISpecification|null $specification
     * @return void
     * @throws InvalidArgumentException Se a especificação for null
     */
    protected function validateSpecification(?ISpecification $specification): void
    {
        if ($specification === null) {
            throw new InvalidArgumentException("A specification não pode ser null.");
        }
    }

    /**
     * Indica se este repositório possui arquitetura de particionamento nativo.
     *
     * @return bool Retorna false por padrão em repositórios homogêneos
     */
    public function isNativelyPartitioned(): bool
    {
        return false;
    }

    /**
     * Indica se este repositório indexa partições recursivamente.
     *
     * @return bool Retorna false por padrão
     */
    public function isRecursivelyIndexing(): bool
    {
        return false;
    }

    /**
     * Cria uma partição vinculada a este repositório baseada na especificação informada.
     *
     * @param ISpecification|null $specification Especificação que delimita a partição
     * @return IPartitionRepository Repositório particionado resultante
     */
    public function makePartition(?ISpecification $specification = null): IPartitionRepository
    {
        return PartitionRepository::create($this, $specification);
    }
}
