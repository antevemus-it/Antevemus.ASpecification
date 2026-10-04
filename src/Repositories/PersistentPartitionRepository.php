<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\IEntityPersistenceMetaData;
use Antevemus\ASpecification\Contracts\Repositories\IPersistentRepository;
use Antevemus\ASpecification\Contracts\Repositories\PersistenceDefinition;

/**
 * PersistentPartitionRepository - Repositório particionado persistente
 *
 * Especialização de PartitionRepository que preserva o contrato de IPersistentRepository
 * e propaga operações de ciclo de vida (load, store, close) para as partições persistentes do grafo (RN-14).
 *
 * Funcionalidades:
 * - Propagação de carga e gravação para partições persistentes
 * - Exposição de identificador único e modalidade de persistência
 * - Sinalização determinística de não suporte a metadados individuais (RN-14)
 *
 * @template T of IEntity
 * @extends PartitionRepository<T>
 * @implements IPersistentRepository<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class PersistentPartitionRepository extends PartitionRepository implements IPersistentRepository
{
    /**
     * Conta todas as entidades presentes na partição.
     *
     * @return int Quantidade total de entidades
     */
    public function countAllEntities(): int
    {
        return $this->countAllEntitiesSpecifiedBy(new AllEntitiesSpecification($this->entityType));
    }

    /**
     * Retorna o identificador único do repositório particionado.
     *
     * @return string Identificador do repositório
     */
    public function getRepositoryId(): string
    {
        if ($this->underlyingRepository instanceof IPersistentRepository) {
            return $this->underlyingRepository->getRepositoryId();
        }
        return 'partition-' . spl_object_hash($this);
    }

    /**
     * Retorna o diretório base de armazenamento de dados, caso aplicável.
     *
     * @return string|null Caminho do diretório de dados ou null se em memória pura
     */
    public function getDataDirectory(): ?string
    {
        if ($this->underlyingRepository instanceof IPersistentRepository) {
            return $this->underlyingRepository->getDataDirectory();
        }
        return null;
    }

    /**
     * Retorna a modalidade de persistência definida para este repositório.
     *
     * @return PersistenceDefinition Modalidade de persistência
     */
    public function getPersistenceDefinition(): PersistenceDefinition
    {
        if ($this->underlyingRepository instanceof IPersistentRepository) {
            return $this->underlyingRepository->getPersistenceDefinition();
        }
        return PersistenceDefinition::MemoryOnly;
    }

    /**
     * Retorna a descrição legível do formato de serialização do repositório.
     *
     * @return string Descrição do formato
     */
    public function getFormatDescription(): string
    {
        if ($this->underlyingRepository instanceof IPersistentRepository) {
            return $this->underlyingRepository->getFormatDescription();
        }
        return 'Partition DAG Format';
    }

    /**
     * Carrega os dados persistentes propagando para todas as sub-partições do grafo.
     *
     * @return void
     */
    public function load(): void
    {
        if ($this->underlyingRepository instanceof IPersistentRepository) {
            $this->underlyingRepository->load();
        }

        foreach ($this->subPartitions as $partition) {
            if ($partition instanceof IPersistentRepository) {
                $partition->load();
            }
        }
    }

    /**
     * Persiste o estado do repositório propagando a gravação no grafo.
     *
     * @return void
     */
    public function store(): void
    {
        if ($this->underlyingRepository instanceof IPersistentRepository) {
            $this->underlyingRepository->store();
        }

        foreach ($this->subPartitions as $partition) {
            if ($partition instanceof IPersistentRepository) {
                $partition->store();
            }
        }
    }

    /**
     * Fecha o repositório liberando recursos e propagando para sub-partições.
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->underlyingRepository instanceof IPersistentRepository) {
            $this->underlyingRepository->close();
        }

        foreach ($this->subPartitions as $partition) {
            if ($partition instanceof IPersistentRepository) {
                $partition->close();
            }
        }
    }

    /**
     * Operação não suportada diretamente em nível de partição agregada.
     *
     * @param IEntity $entity
     * @return IEntityPersistenceMetaData|null
     * @throws RepositoryException Sempre lançada (RN-14)
     */
    public function getEntityMetaData(IEntity $entity): ?IEntityPersistenceMetaData
    {
        throw new RepositoryException('Operação de metadados individuais não suportada em repositório particionado.');
    }
}
