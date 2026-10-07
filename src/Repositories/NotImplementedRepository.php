<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use BadMethodCallException;

/**
 * NotImplementedRepository - Test Double Repository with Unimplemented Operations
 *
 * Test double repository implementation (IFakeRepository) explicitly signaling
 * that operations are not yet implemented for the repository under test.
 *
 * Features:
 * - Direct extension of UnsupportedRepository
 * - Deterministic BadMethodCallException throwing indicating pending implementation
 *
 * @template T of IEntity
 * @extends UnsupportedRepository<T>
 * @version    1.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class NotImplementedRepository extends UnsupportedRepository
{
    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not implemented
     */
    public function countAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        throw new BadMethodCallException('Operation not implemented by repository.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not implemented
     */
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        throw new BadMethodCallException('Operation not implemented by repository.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not implemented
     */
    public function findAllEntitiesSpecifiedBy(ISpecification $specification): array
    {
        throw new BadMethodCallException('Operation not implemented by repository.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not implemented
     */
    public function put(IEntity $entity): void
    {
        throw new BadMethodCallException('Operation not implemented by repository.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not implemented
     */
    public function putAll(array $collectionOfEntities): void
    {
        throw new BadMethodCallException('Operation not implemented by repository.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not implemented
     */
    public function update(IEntity $entity): void
    {
        throw new BadMethodCallException('Operation not implemented by repository.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not implemented
     */
    public function updateWithDelta(IEntity $entity, ?ISpecification $deltaSpecification = null): void
    {
        throw new BadMethodCallException('Operation not implemented by repository.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not implemented
     */
    public function removeAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        throw new BadMethodCallException('Operation not implemented by repository.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not implemented
     */
    public function remove(IEntity $entity): bool
    {
        throw new BadMethodCallException('Operation not implemented by repository.');
    }

    /**
     * {@inheritdoc}
     *
     * @throws BadMethodCallException Always thrown indicating operation is not implemented
     */
    public function contains(IEntity $entity): bool
    {
        throw new BadMethodCallException('Operation not implemented by repository.');
    }
}
