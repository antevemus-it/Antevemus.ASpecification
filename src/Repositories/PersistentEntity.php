<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\IEntityPersistenceMetaData;
use Antevemus\ASpecification\Contracts\Repositories\IPersistentEntity;
use DateTimeInterface;

/**
 * PersistentEntity - Envelope de entidade e metadados persistentes
 *
 * Implementação padrão do envelope (wrapper) de entidade persistente.
 * Associa uma entidade de domínio (IEntity) aos seus metadados de ciclo de vida (IEntityPersistenceMetaData).
 *
 * Funcionalidades:
 * - Acesso e substituição segura de entidade encapsulada
 * - Manutenção de metadados de ciclo de vida de persistência
 * - Encaminhamento de notificações de leitura e gravação
 *
 * @template T of IEntity
 * @implements IPersistentEntity<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class PersistentEntity implements IPersistentEntity
{
    /**
     * @var T
     */
    private IEntity $entity;

    private IEntityPersistenceMetaData $metaData;

    /**
     * Construtor do envelope de entidade persistente.
     *
     * @param T $entity Entidade encapsulada
     * @param IEntityPersistenceMetaData|null $metaData Metadados de persistência (ou gerados automaticamente)
     */
    public function __construct(IEntity $entity, ?IEntityPersistenceMetaData $metaData = null)
    {
        $this->entity = $entity;
        $this->metaData = $metaData ?? new EntityPersistenceMetaData();
    }

    /**
     * {@inheritdoc}
     *
     * @return T
     */
    public function getEntity(): IEntity
    {
        return $this->entity;
    }

    /**
     * {@inheritdoc}
     *
     * @param T $entity
     */
    public function setEntity(IEntity $entity): void
    {
        $this->entity = $entity;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetaData(): IEntityPersistenceMetaData
    {
        return $this->metaData;
    }

    /**
     * {@inheritdoc}
     */
    public function setMetaData(IEntityPersistenceMetaData $metaData): void
    {
        $this->metaData = $metaData;
    }

    /**
     * {@inheritdoc}
     */
    public function registerRead(?DateTimeInterface $timestamp = null): void
    {
        $this->metaData->registerRead($timestamp);
    }

    /**
     * {@inheritdoc}
     */
    public function registerWrite(?DateTimeInterface $timestamp = null): void
    {
        $this->metaData->registerWrite($timestamp);
    }
}
