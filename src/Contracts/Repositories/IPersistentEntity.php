<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use DateTimeInterface;

/**
 * IPersistentEntity - Envelope de entidade e metadados persistentes
 *
 * Contrato para o envelope (wrapper) que une uma entidade de domínio (IEntity)
 * aos seus respectivos metadados de ciclo de vida em repositórios persistentes.
 *
 * Funcionalidades:
 * - Acesso e substituição da entidade de domínio encapsulada
 * - Acesso e atualização dos metadados de persistência
 * - Encaminhamento direto de registro de leituras e gravações
 *
 * @template T of IEntity
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IPersistentEntity
{
    /**
     * Retorna a entidade de domínio encapsulada.
     *
     * @return T
     */
    public function getEntity(): IEntity;

    /**
     * Substitui a entidade de domínio encapsulada.
     *
     * @param T $entity
     */
    public function setEntity(IEntity $entity): void;

    /**
     * Retorna os metadados de persistência associados à entidade.
     */
    public function getMetaData(): IEntityPersistenceMetaData;

    /**
     * Atualiza os metadados de persistência associados à entidade.
     */
    public function setMetaData(IEntityPersistenceMetaData $metaData): void;

    /**
     * Notifica e registra uma leitura da entidade nos metadados associados.
     *
     * @param DateTimeInterface|null $timestamp
     */
    public function registerRead(?DateTimeInterface $timestamp = null): void;

    /**
     * Notifica e registra uma gravação da entidade nos metadados associados.
     *
     * @param DateTimeInterface|null $timestamp
     */
    public function registerWrite(?DateTimeInterface $timestamp = null): void;
}
