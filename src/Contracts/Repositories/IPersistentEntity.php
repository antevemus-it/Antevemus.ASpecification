<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use DateTimeInterface;

/**
 * IPersistentEntity - Wrapper for Entity and Persistence Metadata
 *
 * Contract for the envelope (wrapper) joining a domain entity (IEntity)
 * to its corresponding lifecycle metadata in persistent repositories.
 *
 * Features:
 * - Access and mutation of encapsulated domain entity
 * - Access and updating of persistence metadata
 * - Direct forwarding of read and write event notifications
 *
 * @template T of IEntity
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IPersistentEntity
{
    /**
     * Returns the encapsulated domain entity.
     *
     * @return T
     */
    public function getEntity(): IEntity;

    /**
     * Replaces the encapsulated domain entity.
     *
     * @param T $entity
     */
    public function setEntity(IEntity $entity): void;

    /**
     * Returns persistence metadata associated with the entity.
     */
    public function getMetaData(): IEntityPersistenceMetaData;

    /**
     * Updates persistence metadata associated with the entity.
     */
    public function setMetaData(IEntityPersistenceMetaData $metaData): void;

    /**
     * Notifies and records an entity read in the associated metadata.
     *
     * @param DateTimeInterface|null $timestamp
     */
    public function registerRead(?DateTimeInterface $timestamp = null): void;

    /**
     * Notifies and records an entity write in the associated metadata.
     *
     * @param DateTimeInterface|null $timestamp
     */
    public function registerWrite(?DateTimeInterface $timestamp = null): void;
}
