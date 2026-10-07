<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IFakeRepository;
use BadMethodCallException;

/**
 * UnsupportedRepository - Test Double Repository with Unsupported Operations
 *
 * Test double repository implementation (IFakeRepository) explicitly signaling
 * that storage, query, or deletion operations are unsupported.
 * Used to compose test fixtures, fallbacks, and semantic classification.
 *
 * Features:
 * - Strict implementation of IFakeRepository
 * - Deterministic BadMethodCallException throwing on count, find, put, and remove attempts
 * - Clear identification of unsupported operations
 *
 * @template T of IEntity
 * @extends AbstractRepository<T>
 * @implements IFakeRepository<T>
 * @version    1.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class UnsupportedRepository extends AbstractRepository implements IFakeRepository
{
    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not supported
     */
    public function countAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        throw new BadMethodCallException('Operation not supported by repository.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not supported
     */
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        throw new BadMethodCallException('Operation not supported by repository.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not supported
     */
    public function findAllEntitiesSpecifiedBy(ISpecification $specification): array
    {
        throw new BadMethodCallException('Operation not supported by repository.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not supported
     */
    public function put(IEntity $entity): void
    {
        throw new BadMethodCallException('Operation not supported by repository.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not supported
     */
    public function putAll(array $collectionOfEntities): void
    {
        throw new BadMethodCallException('Operation not supported by repository.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not supported
     */
    public function update(IEntity $entity): void
    {
        throw new BadMethodCallException('Operation not supported by repository.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not supported
     */
    public function updateWithDelta(IEntity $entity, ?ISpecification $deltaSpecification = null): void
    {
        throw new BadMethodCallException('Operation not supported by repository.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not supported
     */
    public function removeAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        throw new BadMethodCallException('Operation not supported by repository.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not supported
     */
    public function remove(IEntity $entity): bool
    {
        throw new BadMethodCallException('Operation not supported by repository.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not supported
     */
    public function contains(IEntity $entity): bool
    {
        throw new BadMethodCallException('Operation not supported by repository.');
    }
}
