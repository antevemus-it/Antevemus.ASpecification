<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IVolatileRepository;

/**
 * NullRepository - Null Object Pattern Repository Implementation
 *
 * Implementation of the Null Object Pattern for the Repository contract.
 * Stores nothing, returns nothing, throws no exceptions.
 * Designed purely for fast structural test mocks or innocuous services
 * that strictly require dependency injection of an IRepository.
 *
 * Features:
 * - Harmless no-op implementation of all persistence and query operations
 * - Safe neutral return values (0, empty array, null, false)
 * - Specification validations maintained for contract compliance
 *
 * @template T of IEntity
 * @extends AbstractRepository<T>
 * @implements IVolatileRepository<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class NullRepository extends AbstractRepository implements IVolatileRepository
{
    /**
     * Always returns zero (Null Object).
     *
     * @param ISpecification $specification Filter specification
     * @return int Always 0
     */
    public function countAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        $this->validateSpecification($specification);
        return 0;
    }

    /**
     * Returns an empty iterable (Null Object).
     *
     * @param ISpecification $specification Filter specification
     * @return iterable<T> Always empty
     */
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        $this->validateSpecification($specification);
        return []; // In PHP, empty array is a valid iterable without yielding
    }

    /**
     * Returns an empty array (Null Object).
     *
     * @param ISpecification $specification Filter specification
     * @return array<T> Always empty
     */
    public function findAllEntitiesSpecifiedBy(ISpecification $specification): array
    {
        $this->validateSpecification($specification);
        return [];
    }

    /**
     * Always returns null (Null Object).
     *
     * @param ISpecification $specification Filter specification
     * @return null
     */
    public function findSingleEntitySpecifiedBy(ISpecification $specification): ?IEntity
    {
        $this->validateSpecification($specification);
        return null;
    }

    /**
     * No-op operation (Null Object).
     *
     * @param IEntity $entity
     * @return void
     */
    public function put(IEntity $entity): void
    {
        // Null Object Pattern - no-op
    }

    /**
     * No-op operation (Null Object).
     *
     * @param array<IEntity> $collectionOfEntities
     * @return void
     */
    public function putAll(array $collectionOfEntities): void
    {
        // Null Object Pattern - no-op
    }

    /**
     * No-op operation (Null Object).
     *
     * @param IEntity $entity
     * @return void
     */
    public function update(IEntity $entity): void
    {
        // Null Object Pattern - no-op
    }

    /**
     * No-op operation (Null Object).
     *
     * @param IEntity $entity
     * @param ISpecification|null $deltaSpecification
     * @return void
     */
    public function updateWithDelta(IEntity $entity, ?ISpecification $deltaSpecification = null): void
    {
        // Null Object Pattern - no-op
    }

    /**
     * Always returns 0 (Null Object).
     *
     * @param ISpecification $specification
     * @return int Always 0
     */
    public function removeAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        $this->validateSpecification($specification);
        return 0;
    }

    /**
     * Always returns false (Null Object).
     *
     * @param IEntity $entity
     * @return bool Always false
     */
    public function remove(IEntity $entity): bool
    {
        return false;
    }
}
