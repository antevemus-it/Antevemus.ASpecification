<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;

/**
 * IPersistentRepository - Contract for Persistent Repositories
 *
 * Formal contract for persistent repositories. A persistent repository
 * manages storing and retrieving entities across long-term media
 * (database, filesystem, physical files, remote storage services), exposing
 * explicit lifecycle methods (load, store, close), a unique identifier, and metadata.
 *
 * Features:
 * - Unique repository identification (getRepositoryId)
 * - Physical storage directory retrieval (getDataDirectory)
 * - Persistence mode definition (getPersistenceDefinition)
 * - Format description reporting (getFormatDescription)
 * - Explicit lifecycle control: load, store, and close
 * - Per-entity persistence metadata tracking (getEntityMetaData)
 *
 * @template T of IEntity
 * @extends IRepository<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IPersistentRepository extends IRepository
{
    /**
     * Returns the unique repository identifier in the system.
     */
    public function getRepositoryId(): string;

    /**
     * Returns the physical filesystem data directory used for storage,
     * or null if persistence is not based on local disk storage.
     */
    public function getDataDirectory(): ?string;

    /**
     * Returns the persistence modality and definition of this repository.
     */
    public function getPersistenceDefinition(): PersistenceDefinition;

    /**
     * Returns a short human-readable description of the storage format.
     */
    public function getFormatDescription(): string;

    /**
     * Loads entities from persistent storage media into repository memory.
     *
     * @throws RepositoryException If an I/O error or load inconsistency occurs
     */
    public function load(): void;

    /**
     * Stores and synchronizes memory data into persistent storage media.
     *
     * @throws RepositoryException If an error occurs during persistence
     */
    public function store(): void;

    /**
     * Closes the repository, releasing locks, file handles, or open connections.
     *
     * @throws RepositoryException If an error occurs during closing
     */
    public function close(): void;

    /**
     * Returns persistence metadata associated with a managed entity.
     *
     * @param T $entity
     * @return IEntityPersistenceMetaData|null
     * @throws RepositoryException If the operation is not supported by implementation
     */
    public function getEntityMetaData(IEntity $entity): ?IEntityPersistenceMetaData;
}
