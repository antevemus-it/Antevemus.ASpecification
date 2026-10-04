<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;

/**
 * IPersistentRepository - Contrato de repositório persistente
 *
 * Contrato formal para repositórios persistentes. Um repositório persistente
 * gerencia a gravação e recuperação de entidades em mídias de longo prazo
 * (banco de dados, disco, arquivos físicos, serviços remotos), expondo
 * ciclo de vida explícito (load, store, close), identificador único e metadados.
 *
 * Funcionalidades:
 * - Identificação única do repositório (getRepositoryId)
 * - Consulta de diretório de dados em disco (getDataDirectory)
 * - Definição da modalidade de persistência (getPersistenceDefinition)
 * - Descrição de formato de dados (getFormatDescription)
 * - Ciclo de vida: carga (load), gravação (store) e encerramento (close)
 * - Obtenção de metadados de ciclo de vida por entidade (getEntityMetaData)
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
interface IPersistentRepository extends IRepository
{
    /**
     * Retorna o identificador único do repositório no sistema.
     */
    public function getRepositoryId(): string;

    /**
     * Retorna o diretório de dados físico utilizado para armazenamento,
     * ou null caso a persistência não seja baseada em sistema de arquivos local.
     */
    public function getDataDirectory(): ?string;

    /**
     * Retorna a modalidade de persistência deste repositório.
     */
    public function getPersistenceDefinition(): PersistenceDefinition;

    /**
     * Retorna uma descrição curta legível do formato de armazenamento.
     */
    public function getFormatDescription(): string;

    /**
     * Carrega os dados da mídia persistente para a memória do repositório.
     *
     * @throws RepositoryException Caso ocorra erro de E/S ou inconsistência na carga
     */
    public function load(): void;

    /**
     * Grava e sincroniza os dados da memória na mídia de armazenamento de longo prazo.
     *
     * @throws RepositoryException Caso ocorra erro durante o processo de gravação
     */
    public function store(): void;

    /**
     * Fecha o repositório, liberando recursos, locks ou conexões pendentes.
     *
     * @throws RepositoryException Caso ocorra erro durante o fechamento
     */
    public function close(): void;

    /**
     * Retorna os metadados de persistência associados a uma entidade gerenciada.
     *
     * @param T $entity
     * @return IEntityPersistenceMetaData|null
     * @throws RepositoryException Se a operação não for suportada pela implementação
     */
    public function getEntityMetaData(IEntity $entity): ?IEntityPersistenceMetaData;
}
