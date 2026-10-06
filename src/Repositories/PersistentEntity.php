<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\IEntityPersistenceMetaData;
use Antevemus\ASpecification\Contracts\Repositories\IPersistentEntity;
use DateTimeInterface;

/**
 * PersistentEntity - Persistent Entity and Metadata Wrapper
 *
 * Default implementation of persistent entity envelope (wrapper).
 * Associates a domain entity (IEntity) with its lifecycle persistence metadata (IEntityPersistenceMetaData).
 *
 * Features:
 * - Safe access and mutation of encapsulated entity
 * - Maintenance of persistence lifecycle metadata
 * - Forwarding of read and write event notifications
 *
 * @template T of IEntity
 * @implements IPersistentEntity<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
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
     * Constructs persistent entity wrapper.
     *
     * @param T $entity Encapsulated entity
     * @param IEntityPersistenceMetaData|null $metaData Persistence metadata (or automatically initialized)
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
