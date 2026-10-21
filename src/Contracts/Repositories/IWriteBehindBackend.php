<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Repositories\RepositoryPersistenceMetaData;

/**
 * IWriteBehindBackend - Storage that receives the whole working set of a write-behind repository at once
 *
 * The backend side of the write-behind hybrid (InMemoryAndFileRepository in MemoryAsyncFile or
 * MemoryAsyncDelegated): persist() hands it the entire working set and the repository record, and the
 * backend replaces what it stores with them. SingleFileRepository and FilePerEntityRepository implement
 * it; any other IPersistentRepository still works as a backend, through removeAll() + putAll() + store().
 *
 * Features:
 * - Replacement of the stored content by a snapshot (persistSnapshot), the previous content kept intact
 *   when the replacement fails (one temporary file renamed over the document, for a single-file storage)
 * - The repository record (RepositoryPersistenceMetaData) stored with the content and read back by load()
 *
 * @template T of IEntity
 * @extends IPersistentRepository<T>
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IWriteBehindBackend extends IPersistentRepository
{
    /**
     * Replaces the stored content with $entities (the whole working set: an entity stored and absent from
     * it is removed) and stores $metaData with it. When it throws, the stored content is the previous one
     * and the backend's own state is unchanged.
     *
     * @param list<T> $entities The working set
     * @param list<int|string> $writtenIds Ids of the entities written since the previous snapshot (their
     *                                     per-entity record counts one write; the other records are kept)
     * @param RepositoryPersistenceMetaData $metaData The repository record to store
     * @return void
     * @throws RepositoryException When the content cannot be written
     */
    public function persistSnapshot(array $entities, array $writtenIds, RepositoryPersistenceMetaData $metaData): void;

    /**
     * The repository record read by the last load() (or stored by the last persistSnapshot()); null when
     * the storage holds none.
     *
     * @return RepositoryPersistenceMetaData|null
     */
    public function getRepositoryMetaData(): ?RepositoryPersistenceMetaData;
}
