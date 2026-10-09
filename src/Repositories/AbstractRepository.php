<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ALinq\Interfaces\IALinqLazyCollection;
use Antevemus\ASpecification\Concurrent\NullSynchronizer;
use Antevemus\ASpecification\Contracts\Concurrent\ISynchronizer;
use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IPartitionRepository;
use Antevemus\ASpecification\Contracts\Repositories\IRepository;
use Antevemus\ASpecification\Helpers\SpecificationHelper;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use InvalidArgumentException;
use RuntimeException;

/**
 * AbstractRepository - Base Abstract Repository Implementation
 *
 * Base abstract class implementing repository aliases and safeguard
 * routines for the master repository contract (IRepository).
 *
 * Every repository carries an ISynchronizer, as net.sourceforge.domian.repository.AbstractDomianCoreRepository
 * does (Domian, Copyright 2006-2010 the original author or authors, Apache License 2.0; see
 * THIRD_PARTY_NOTICES.md): NullSynchronizer by default, replaced with withSynchronizer()/setSynchronizer()
 * or by the trailing constructor parameter of the concrete repositories. Reads run in
 * callConcurrently() and writes in callExclusively(); subclasses reach it through synchronizer(),
 * readConcurrently() and writeExclusively(), and a partition repository wraps the operations of its
 * underlying repository with getSynchronizer().
 *
 * Features:
 * - Provides fluent convenience shortcuts (count, iterate, find, findSingle, removeBy) mapped to canonical contracts
 * - Implements findSingleEntitySpecifiedBy with strict unitary cardinality verification
 * - Default contains() by entity identity (getEntityId) with equals() fallback
 * - Default updateWithDelta() applying the delta specification to the entity before update()
 * - Structural specification validations
 * - Pluggable ISynchronizer (NullSynchronizer by default) wrapping reads and writes
 * - Virtual partition factory via makePartition
 * - Lazy ALinq streams over iterate() for every repository: asLazyCollection(), findAsLazyCollection()
 *   (1.5.0; antevemus/alinq-collection ^1.3)
 *
 * @template T of IEntity
 * @implements IRepository<T>
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractRepository implements IRepository
{
    /**
     * Synchronizer controlling concurrent and exclusive access to the repository operations
     * (Java AbstractDomianCoreRepository.synchronizer). Created lazily so that subclasses need not call a constructor.
     *
     * @var ISynchronizer|null
     */
    private ?ISynchronizer $synchronizer = null;

    ///////////////////////////////////////////////////////////////////////////
    // Synchronizer
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Replaces the synchronizer (Java setSynchronizer()), fluently.
     *
     * @param ISynchronizer $synchronizer
     * @return static
     */
    public function withSynchronizer(ISynchronizer $synchronizer): static
    {
        $this->synchronizer = $synchronizer;
        return $this;
    }

    /**
     * Replaces the synchronizer (Java name).
     *
     * @param ISynchronizer $synchronizer
     * @return void
     */
    public function setSynchronizer(ISynchronizer $synchronizer): void
    {
        $this->synchronizer = $synchronizer;
    }

    /**
     * Returns the synchronizer in use (NullSynchronizer until one is set). Public so that a partition
     * repository can synchronize the operations of its underlying repository with the same instance.
     *
     * @return ISynchronizer
     */
    public function getSynchronizer(): ISynchronizer
    {
        return $this->synchronizer();
    }

    /**
     * Hook for subclasses: the synchronizer in use, NullSynchronizer until one is set.
     *
     * @return ISynchronizer
     */
    protected function synchronizer(): ISynchronizer
    {
        return $this->synchronizer ??= new NullSynchronizer();
    }

    /**
     * Runs a read operation under the synchronizer's concurrent (shared) mode.
     *
     * @template R
     * @param callable(): R $read
     * @return R
     */
    protected function readConcurrently(callable $read): mixed
    {
        return $this->synchronizer()->callConcurrently($read);
    }

    /**
     * Runs a write operation under the synchronizer's exclusive mode.
     *
     * @template R
     * @param callable(): R $write
     * @return R
     */
    protected function writeExclusively(callable $write): mixed
    {
        return $this->synchronizer()->callExclusively($write);
    }

    ///////////////////////////////////////////////////////////////////////////
    // Aliases
    ///////////////////////////////////////////////////////////////////////////

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
     * Returns every stored entity as a lazy ALinqLazyCollection over iterate() (1.5.0, RN-04).
     *
     * The collection asks this repository for a new generator on every traversal: it is
     * re-iterable, getAll() is never called and only the entities the pipeline pulls are read.
     *
     * @return IALinqLazyCollection
     * @throws RuntimeException When antevemus/alinq-collection is not installed
     */
    public function asLazyCollection(): IALinqLazyCollection
    {
        return \Antevemus\ASpecification\Linq\ALinqBridge::toLazyCollection($this);
    }

    /**
     * Returns the entities satisfying the specification as a lazy ALinqLazyCollection over
     * iterate($specification) (1.5.0, RN-04): `$repo->findAsLazyCollection($spec)->take(10)->toArray()`
     * evaluates only the entities needed to collect ten matches, in any repository.
     *
     * @param ISpecification $specification Filter specification
     * @return IALinqLazyCollection
     * @throws RuntimeException When antevemus/alinq-collection is not installed
     */
    public function findAsLazyCollection(ISpecification $specification): IALinqLazyCollection
    {
        return \Antevemus\ASpecification\Linq\ALinqBridge::filterLazy($this, $specification);
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
        return $this->readConcurrently(function () use ($specification): ?IEntity {
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
        });
    }

    /**
     * Default membership test: scans the repository comparing the scalar entity identifier
     * and, when the identifier is not scalar or does not match, IEntity::equals().
     * Concrete repositories with an index override this with an O(1) lookup.
     *
     * @param T $entity Entity to look for
     * @return bool True when a stored entity has the same identity or equals the given one
     */
    public function contains(IEntity $entity): bool
    {
        return $this->readConcurrently(function () use ($entity): bool {
            $id = $entity->getEntityId();
            $scalarId = is_scalar($id) ? (string) $id : null;

            foreach ($this->iterateAllEntitiesSpecifiedBy(new AlwaysTrueSpecification()) as $stored) {
                if ($scalarId !== null && is_scalar($stored->getEntityId()) && (string) $stored->getEntityId() === $scalarId) {
                    return true;
                }
                if ($stored->equals($entity)) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Default implementation of the update with a delta specification: applies the delta to the entity
     * (every property clause bound to a value sets that property: port of Domian
     * SpecificationUtils.updateEntityState(), see SpecificationHelper::updateEntityState()) and then
     * updates the entity. A null delta is a plain update(), as in Java AbstractRepository.
     *
     * @param T $entity Entity to update
     * @param ISpecification|null $deltaSpecification Conjunction of property clauses bound to the new values, or null
     * @return void
     * @throws InvalidArgumentException When the delta targets a type the entity is not an instance of,
     *                                  or a property the entity does not have
     */
    public function updateWithDelta(IEntity $entity, ?ISpecification $deltaSpecification = null): void
    {
        $this->writeExclusively(function () use ($entity, $deltaSpecification): void {
            SpecificationHelper::updateEntityState($entity, $deltaSpecification);
            $this->update($entity);
        });
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
