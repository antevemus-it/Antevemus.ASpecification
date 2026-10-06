<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IPartitionRepository;
use Antevemus\ASpecification\Contracts\Repositories\IRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * AbstractRepository - Base Abstract Repository Implementation
 *
 * Base abstract class implementing repository aliases and safeguard
 * routines for the master repository contract (IRepository).
 *
 * Features:
 * - Provides fluent convenience shortcuts (count, iterate, find, findSingle, removeBy) mapped to canonical contracts
 * - Implements findSingleEntitySpecifiedBy with strict unitary cardinality verification
 * - Structural specification validations
 * - Virtual partition factory via makePartition
 *
 * @template T of IEntity
 * @implements IRepository<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractRepository implements IRepository
{
    /**
     * Fluent alias for countAllEntitiesSpecifiedBy().
     *
     * @param ISpecification $specification Filter specification
     * @return int Total entities satisfying the rule
     */
    public function countAll(ISpecification $specification): int
    {
        return $this->countAllEntitiesSpecifiedBy($specification);
    }

    /**
     * Short alias for countAllEntitiesSpecifiedBy().
     *
     * @param ISpecification $specification Filter specification
     * @return int Total entities satisfying the rule
     */
    public function count(ISpecification $specification): int
    {
        return $this->countAllEntitiesSpecifiedBy($specification);
    }

    /**
     * Fluent alias for iterateAllEntitiesSpecifiedBy().
     *
     * @param ISpecification $specification Filter specification
     * @return iterable<T> Lazy generator/iterable of entities
     */
    public function iterateAll(ISpecification $specification): iterable
    {
        return $this->iterateAllEntitiesSpecifiedBy($specification);
    }

    /**
     * Short alias for iterateAllEntitiesSpecifiedBy().
     *
     * @param ISpecification $specification Filter specification
     * @return iterable<T> Lazy generator/iterable of entities
     */
    public function iterate(ISpecification $specification): iterable
    {
        return $this->iterateAllEntitiesSpecifiedBy($specification);
    }

    /**
     * Fluent alias for findAllEntitiesSpecifiedBy().
     *
     * @param ISpecification $specification Filter specification
     * @return array<T> List of all matching entities
     */
    public function findAll(ISpecification $specification): array
    {
        return $this->findAllEntitiesSpecifiedBy($specification);
    }

    /**
     * Short alias for findAllEntitiesSpecifiedBy().
     *
     * @param ISpecification $specification Filter specification
     * @return array<T> List of all matching entities
     */
    public function find(ISpecification $specification): array
    {
        return $this->findAllEntitiesSpecifiedBy($specification);
    }

    /**
     * Short alias for findSingleEntitySpecifiedBy().
     *
     * @param ISpecification $specification Filter specification
     * @return T|null Matching single entity, or null if not found
     * @throws RuntimeException If more than one entity matches
     */
    public function findSingle(ISpecification $specification): ?IEntity
    {
        return $this->findSingleEntitySpecifiedBy($specification);
    }

    /**
     * Finds a single entity satisfying the given specification, verifying unitary cardinality.
     *
     * @param ISpecification $specification Filter specification
     * @return T|null Matching single entity, or null if none match
     * @throws RuntimeException If more than one entity satisfies the specification
     */
    public function findSingleEntitySpecifiedBy(ISpecification $specification): ?IEntity
    {
        $allFound = $this->findAllEntitiesSpecifiedBy($specification);
        $count = count($allFound);

        if ($count > 1) {
            throw new RuntimeException("Expected a single entity result, but found " . $count);
        }

        if ($count === 0) {
            return null;
        }

        // Return first and only item
        return reset($allFound);
    }

    /**
     * Fluent alias for removeAllEntitiesSpecifiedBy().
     *
     * @param ISpecification $specification Specification of entities to remove
     * @return int Number of removed entities
     */
    public function removeAll(ISpecification $specification): int
    {
        return $this->removeAllEntitiesSpecifiedBy($specification);
    }

    /**
     * Short alias for removeAllEntitiesSpecifiedBy().
     *
     * @param ISpecification $specification Specification of entities to remove
     * @return int Number of removed entities
     */
    public function removeBy(ISpecification $specification): int
    {
        return $this->removeAllEntitiesSpecifiedBy($specification);
    }

    /**
     * Validates that the provided specification is non-null.
     *
     * @param ISpecification|null $specification
     * @return void
     * @throws InvalidArgumentException If specification is null
     */
    protected function validateSpecification(?ISpecification $specification): void
    {
        if ($specification === null) {
            throw new InvalidArgumentException("Specification cannot be null.");
        }
    }

    /**
     * Indicates whether this repository has native partitioning architecture.
     *
     * @return bool Returns false by default in homogeneous repositories
     */
    public function isNativelyPartitioned(): bool
    {
        return false;
    }

    /**
     * Indicates whether this repository recursively indexes partitions.
     *
     * @return bool Returns false by default
     */
    public function isRecursivelyIndexing(): bool
    {
        return false;
    }

    /**
     * Creates a partition linked to this repository based on the provided specification.
     *
     * @param ISpecification|null $specification Bounding specification for partition
     * @return IPartitionRepository Resulting partitioned repository
     */
    public function makePartition(?ISpecification $specification = null): IPartitionRepository
    {
        return PartitionRepository::create($this, $specification);
    }
}
