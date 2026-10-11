<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\IPartitionRepository;
use Antevemus\ASpecification\Contracts\Repositories\IRepository;
use Antevemus\ASpecification\DomainEvents\Exceptions\DomainEventTriggerException;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Persistence\ITransactionManager;
use Antevemus\ASpecification\Repositories\Pdo\PdoRepository;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;
use WeakMap;

/**
 * TriggeringRepository - Repository Decorator That Emits Domain Events
 *
 * Wraps any IRepository (forward 029, D6). On put/update/updateWithDelta it reads the stored state
 * of the entity by its identity, writes, evaluates the triggers on the before and the after
 * (TriggerEvaluator) and publishes the events; on remove/removeBy it evaluates the exit of every
 * removed entity. Reads are delegated untouched.
 *
 * Reading "before":
 * - The stored entity is looked up with findSingle() and an identity specification built from the
 *   entity id: by default a predicate on getEntityId() (fine for the in-memory and file
 *   repositories, which evaluate it in PHP), and `byIdProperty('entityId')` when the decorated
 *   repository is a PdoRepository (the predicate is not translatable to SQL; `entityId` always maps
 *   to the id column). For another repository that translates specifications to SQL, give
 *   `identitySpecification: TriggeringRepository::byIdProperty(...)` (or any closure
 *   `fn(mixed $id, IEntity $entity): ISpecification`). An entity whose id is null (not inserted
 *   yet, auto id) is a creation: no lookup.
 * - When the stored entity is a different object (a repository that hydrates, as a PDO one), its
 *   state is evaluated: that is the truth.
 * - When it is the SAME object (the in-memory repositories store the caller's instance, so a
 *   mutation in place already changed the "stored" state), the decorator uses the trigger states it
 *   recorded at the last write of that object through it (a WeakMap, freed with the object). An
 *   object never written through this decorator (stored before the decorator was built, or written
 *   to the inner repository directly) and then mutated in place has no recorded "before": its
 *   current state is used, so that write emits nothing. Write through the decorator from the start.
 *
 * Cost (declared): one extra read per write (the identity lookup; a scan in the in-memory
 * repositories), one evaluation of every trigger on the after state (and on the before state when
 * it was read), one findAll() before a removeBy().
 *
 * Errors (D3): by default an evaluation error lets the write go on and goes to the
 * IDomainEventErrorReporter (NullDomainEventErrorReporter by default; an exception thrown by the
 * reporter is ignored); with `failOnEvaluationError: true` the write throws
 * DomainEventTriggerException after reporting, and nothing is published. A failure of the
 * publisher (outbox down) is never swallowed.
 *
 * Transactions: with an ITransactionManager, each write (lookup, write, evaluation, publication)
 * runs in one transaction. When the repository and the outbox use the same connection and the same
 * ITransactionManager (a PdoTransactionManager over the PDO both write to), the write and its
 * events are atomic: both commit or neither does. Without one, the write is not undone when the
 * publication or a strict evaluation fails.
 *
 * The clock is read once per write (default: system clock) and stamps every event of that write.
 *
 * @template T of IEntity
 * @implements IRepository<T>
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class TriggeringRepository implements IRepository
{
    private readonly IDomainEventErrorReporter $errors;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /** @var Closure(mixed, IEntity): ISpecification */
    private readonly Closure $identitySpecification;

    private readonly TriggerEvaluator $evaluator;

    /** @var WeakMap<IEntity, TriggerStates> Trigger states recorded at the last write of each object */
    private WeakMap $recorded;

    /**
     * @param IRepository<T> $inner Decorated repository
     * @param TriggerSet $triggers Triggers evaluated on every write
     * @param IDomainEventPublisher $publisher Where the events go (an outbox in production)
     * @param ITransactionManager|null $transactions Unit of work around each write; null = none
     * @param IDomainEventErrorReporter|null $errors Reporter of evaluation errors; null = discard
     * @param (Closure(): DateTimeImmutable)|null $clock Instant of each write; null = system clock
     * @param (Closure(mixed, IEntity): ISpecification)|null $identitySpecification Specification
     *        selecting the stored entity of an id; null = predicate on getEntityId()
     * @param bool $failOnEvaluationError True = an evaluation error makes the write throw
     * @param TriggerEvaluator|null $evaluator Evaluator (custom identity reader); null = default
     */
    public function __construct(
        private readonly IRepository $inner,
        private readonly TriggerSet $triggers,
        private readonly IDomainEventPublisher $publisher,
        private readonly ?ITransactionManager $transactions = null,
        ?IDomainEventErrorReporter $errors = null,
        ?Closure $clock = null,
        ?Closure $identitySpecification = null,
        private readonly bool $failOnEvaluationError = false,
        ?TriggerEvaluator $evaluator = null
    ) {
        $this->errors = $errors ?? new NullDomainEventErrorReporter();
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable('now');
        $this->identitySpecification = $identitySpecification
            ?? ($inner instanceof PdoRepository ? self::byIdProperty('entityId') : self::byEntityId());
        $this->evaluator = $evaluator ?? new TriggerEvaluator();
        $this->recorded = new WeakMap();
    }

    /**
     * Identity specification on a mapped id property, translatable to SQL:
     * `Spec::property($property, Spec::equalTo($id))`.
     *
     * @param string $property Id property name (as the repository's mapping knows it)
     * @return Closure(mixed, IEntity): ISpecification
     */
    public static function byIdProperty(string $property): Closure
    {
        if ($property === '') {
            throw new InvalidArgumentException('The id property name cannot be empty.');
        }

        return static fn(mixed $id): ISpecification => Spec::property($property, Spec::equalTo($id));
    }

    /**
     * Default identity specification: a PHP predicate comparing getEntityId() (ints and strings by
     * their text form, other values with ==). Not translatable to SQL.
     *
     * @return Closure(mixed, IEntity): ISpecification
     */
    public static function byEntityId(): Closure
    {
        return static fn(mixed $id): ISpecification => new PredicateSpecification(
            static fn(mixed $candidate): bool => $candidate instanceof IEntity && self::sameId($candidate->getEntityId(), $id)
        );
    }

    /** The decorated repository. */
    public function getInner(): IRepository
    {
        return $this->inner;
    }

    /** The triggers. */
    public function getTriggers(): TriggerSet
    {
        return $this->triggers;
    }

    /** The publisher. */
    public function getPublisher(): IDomainEventPublisher
    {
        return $this->publisher;
    }

    ///////////////////////////////////////////////////////////////////////////
    // Writes (evaluated)
    ///////////////////////////////////////////////////////////////////////////

    /** {@inheritdoc} */
    public function put(IEntity $entity): void
    {
        $this->write($entity, fn() => $this->inner->put($entity));
    }

    /** {@inheritdoc} */
    public function putAll(array $collectionOfEntities): void
    {
        foreach ($collectionOfEntities as $entity) {
            if (!$entity instanceof IEntity) {
                throw new InvalidArgumentException('All items must implement IEntity.');
            }
        }
        $this->atomically(function () use ($collectionOfEntities): void {
            foreach ($collectionOfEntities as $entity) {
                $this->put($entity);
            }
        });
    }

    /** {@inheritdoc} */
    public function update(IEntity $entity): void
    {
        $this->write($entity, fn() => $this->inner->update($entity));
    }

    /** {@inheritdoc} */
    public function updateWithDelta(IEntity $entity, ?ISpecification $deltaSpecification = null): void
    {
        $this->write($entity, fn() => $this->inner->updateWithDelta($entity, $deltaSpecification));
    }

    /** {@inheritdoc} */
    public function remove(IEntity $entity): bool
    {
        return $this->atomically(function () use ($entity): bool {
            $now = ($this->clock)();
            $stored = $this->findStored($entity);
            $subject = $stored ?? $entity;
            $before = $this->recordedOrEvaluated($subject);
            if (!$this->inner->remove($entity)) {
                return false;
            }
            unset($this->recorded[$entity], $this->recorded[$subject]);
            $this->emit([$this->evaluator->compare($this->triggers, $before, TriggerStates::absent(), $subject, $now)]);

            return true;
        });
    }

    /** {@inheritdoc} */
    public function removeAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        return $this->atomically(function () use ($specification): int {
            $now = ($this->clock)();
            $victims = $this->inner->findAllEntitiesSpecifiedBy($specification);
            $before = array_map($this->recordedOrEvaluated(...), $victims);
            $removed = $this->inner->removeAllEntitiesSpecifiedBy($specification);
            $changes = [];
            foreach ($victims as $i => $victim) {
                unset($this->recorded[$victim]);
                $changes[] = $this->evaluator->compare($this->triggers, $before[$i], TriggerStates::absent(), $victim, $now);
            }
            $this->emit($changes);

            return $removed;
        });
    }

    /** {@inheritdoc} */
    public function removeAll(ISpecification $specification): int
    {
        return $this->removeAllEntitiesSpecifiedBy($specification);
    }

    /** {@inheritdoc} */
    public function removeBy(ISpecification $specification): int
    {
        return $this->removeAllEntitiesSpecifiedBy($specification);
    }

    ///////////////////////////////////////////////////////////////////////////
    // Reads (delegated)
    ///////////////////////////////////////////////////////////////////////////

    /** {@inheritdoc} */
    public function countAllEntitiesSpecifiedBy(ISpecification $specification): int
    {
        return $this->inner->countAllEntitiesSpecifiedBy($specification);
    }

    /** {@inheritdoc} */
    public function countAll(ISpecification $specification): int
    {
        return $this->inner->countAllEntitiesSpecifiedBy($specification);
    }

    /** {@inheritdoc} */
    public function count(ISpecification $specification): int
    {
        return $this->inner->countAllEntitiesSpecifiedBy($specification);
    }

    /** {@inheritdoc} */
    public function iterateAllEntitiesSpecifiedBy(ISpecification $specification): iterable
    {
        return $this->inner->iterateAllEntitiesSpecifiedBy($specification);
    }

    /** {@inheritdoc} */
    public function iterateAll(ISpecification $specification): iterable
    {
        return $this->inner->iterateAllEntitiesSpecifiedBy($specification);
    }

    /** {@inheritdoc} */
    public function iterate(ISpecification $specification): iterable
    {
        return $this->inner->iterateAllEntitiesSpecifiedBy($specification);
    }

    /** {@inheritdoc} */
    public function findAllEntitiesSpecifiedBy(ISpecification $specification): array
    {
        return $this->inner->findAllEntitiesSpecifiedBy($specification);
    }

    /** {@inheritdoc} */
    public function findAll(ISpecification $specification): array
    {
        return $this->inner->findAllEntitiesSpecifiedBy($specification);
    }

    /** {@inheritdoc} */
    public function find(ISpecification $specification): array
    {
        return $this->inner->findAllEntitiesSpecifiedBy($specification);
    }

    /** {@inheritdoc} */
    public function findSingleEntitySpecifiedBy(ISpecification $specification): ?IEntity
    {
        return $this->inner->findSingleEntitySpecifiedBy($specification);
    }

    /** {@inheritdoc} */
    public function findSingle(ISpecification $specification): ?IEntity
    {
        return $this->inner->findSingleEntitySpecifiedBy($specification);
    }

    /** {@inheritdoc} */
    public function contains(IEntity $entity): bool
    {
        return $this->inner->contains($entity);
    }

    /** {@inheritdoc} */
    public function isNativelyPartitioned(): bool
    {
        return false;
    }

    /** {@inheritdoc} */
    public function isRecursivelyIndexing(): bool
    {
        return $this->inner->isRecursivelyIndexing();
    }

    /**
     * Refused: a partition of the inner repository would take writes that bypass the triggers.
     * Partition the inner repository and decorate the partitions instead.
     *
     * @throws RepositoryException Always
     */
    public function makePartition(?ISpecification $specification = null): IPartitionRepository
    {
        throw new RepositoryException(
            'A TriggeringRepository cannot be partitioned: its partitions would bypass the triggers. Partition the inner repository and decorate each partition.'
        );
    }

    ///////////////////////////////////////////////////////////////////////////
    // Internals
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Lookup, write, evaluation and publication of one put/update.
     *
     * @param Closure(): void $write The inner write
     */
    private function write(IEntity $entity, Closure $write): void
    {
        $this->atomically(function () use ($entity, $write): void {
            $now = ($this->clock)();
            $stored = $this->findStored($entity);
            $before = $stored === null ? TriggerStates::absent() : $this->recordedOrEvaluated($stored);
            $write();
            $after = $this->evaluator->statesOf($this->triggers, $entity);
            $this->recorded[$entity] = $after;
            $this->emit([$this->evaluator->compare($this->triggers, $before, $after, $entity, $now)]);
        });
    }

    /**
     * The stored entity of the same identity, or null (null id = not stored).
     */
    private function findStored(IEntity $entity): ?IEntity
    {
        $id = $entity->getEntityId();
        if ($id === null) {
            return null;
        }

        return $this->inner->findSingleEntitySpecifiedBy(($this->identitySpecification)($id, $entity));
    }

    /**
     * States recorded at the last write of this very object through the decorator, or its
     * current states. Only the in-memory case (the stored object IS the caller's object) hits the
     * record; a hydrated copy is never in it.
     */
    private function recordedOrEvaluated(IEntity $stored): TriggerStates
    {
        return $this->recorded[$stored] ?? $this->evaluator->statesOf($this->triggers, $stored);
    }

    /**
     * Reports the errors, refuses the write in strict mode, publishes the events.
     *
     * @param list<TriggerChanges> $changes
     * @throws DomainEventTriggerException In strict mode, when an evaluation failed
     */
    private function emit(array $changes): void
    {
        $events = [];
        $errors = [];
        foreach ($changes as $change) {
            array_push($events, ...$change->getEvents());
            array_push($errors, ...$change->getErrors());
        }
        foreach ($errors as $error) {
            try {
                $this->errors->report($error);
            } catch (Throwable) {
                // A broken reporter never changes the outcome of the write.
            }
        }
        if ($errors !== [] && $this->failOnEvaluationError) {
            throw new DomainEventTriggerException($errors);
        }
        if ($events !== []) {
            $this->publisher->publish($events);
        }
    }

    /**
     * Runs the work in the transaction manager, when there is one.
     *
     * @template R
     * @param Closure(): R $work
     * @return R
     */
    private function atomically(Closure $work): mixed
    {
        return $this->transactions === null ? $work() : $this->transactions->transactional($work);
    }

    /** Ids compared by text form when both have one, with == otherwise. */
    private static function sameId(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }
        $ta = TriggerEvaluator::textOf($a);
        $tb = TriggerEvaluator::textOf($b);
        if ($ta !== null && $tb !== null) {
            return $ta === $tb;
        }

        return $a == $b;
    }
}
