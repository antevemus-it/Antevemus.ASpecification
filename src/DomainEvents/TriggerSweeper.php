<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IRepository;
use Antevemus\ASpecification\DomainEvents\Outbox\DomainEventSupport;
use Antevemus\ASpecification\DomainEvents\State\ITriggerStateStore;
use Antevemus\ASpecification\DomainEvents\State\TriggerState;
use Antevemus\ASpecification\Persistence\ITransactionManager;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Repositories\Pdo\PdoRepository;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * TriggerSweeper - Finds the Crossings Caused by the Passage of Time
 *
 * A specification such as "unpaid and due more than 30 days ago" turns true while nobody writes the
 * entity: only the clock moved. TriggeringRepository sees crossings on writes; the sweeper, run by
 * a cron with the instant injected, finds the others (forward 030, D3). It works on the time-based
 * triggers of its set (SpecificationTrigger::timed()) and on one repository whose entities are of
 * one concrete class, and compares the truth at $now with the last known state of each trigger for
 * each entity, kept in an ITriggerStateStore that TriggeringRepository keeps up to date too (D6).
 *
 * sweep($now, $limit), per time-based trigger, in declaration order:
 * 1. Exits. The satisfied states are read from the store in keyset pages of `batchSize` rows. For
 *    each page, one `count(spec(now) AND id IN page)` on the repository: when it equals the page
 *    size, every entity of the page is still inside and nothing is loaded. Otherwise the entities
 *    still inside are listed, the others are read by identity (absent = removed) and each one that
 *    does not satisfy spec(now) in PHP, or is gone, is an exit.
 * 2. Entries. When the number of entities confirmed inside equals `count(spec(now))`, every
 *    entity satisfying the specification is already recorded inside: nothing can enter and the
 *    pass is skipped without loading an entity. Otherwise the entities satisfying spec(now) are
 *    streamed (iterate(), one at a time; SQL for a PdoRepository), their states read from the store
 *    per batch, and each one that satisfies it in PHP and is not recorded inside (false or no
 *    state, D4) is an entry. Only the ids of the entries are kept until the stream ends.
 * 3. Each crossing re-reads the entity by identity, confirms it, then runs in ONE transaction of
 *    the ITransactionManager: a compare-and-set of the state on the version read during the scan
 *    (an exit of a removed entity deletes the state) and, when it won, the publication of the
 *    event. A lost compare-and-set publishes nothing (another sweeper, or a write, recorded that
 *    crossing): it is counted as a conflict. Give the store, the outbox and the transaction
 *    manager the same connection, so that the state and the event commit together; the outbox's
 *    UNIQUE idempotency key is the second barrier.
 *
 * An entry or exit is stamped with $now, or with the trigger's crossing instant (D5) when its
 * calculator returns an instant after the evaluation recorded in the state and not after $now: the
 * same crossing then has the same idempotency key in every sweep and every process.
 *
 * The SQL evaluation of a PdoRepository only narrows: a crossing is decided by the PHP evaluation
 * of the loaded entity, as on a write (the in-memory evaluation is the reference). "Still inside"
 * is decided by the count, without loading the entity.
 *
 * $limit bounds the crossings handled by one call (entries and exits); limitReached() asks the cron
 * to call again. Every statement of a sweep is closed before a crossing is written; the entry pass
 * reads the states of each batch while the repository's cursor is open, and baseline() records each
 * batch while it is open: on MySQL with unbuffered queries give the sweeper a repository on its own
 * connection. The repository may be the TriggeringRepository itself (reads are delegated; the
 * default identities specification looks through it).
 *
 * baseline($now) records the current state of every trigger (plain and time-based) for every
 * entity, and deletes the satisfied states of entities that no longer exist, publishing nothing
 * (D4). Run it once when a trigger is introduced over existing data: without it, the first sweep
 * publishes an enter for every entity already inside (they are inside and nobody was told), which
 * is the truth, but rarely what a mailing wants.
 *
 * Out of scope (D7): no embedded scheduler (the cron is the caller's) and no partitioning of the
 * work between processes: two sweepers are correct together (compare-and-set), not faster.
 *
 * @version    1.12.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class TriggerSweeper
{
    /** Default page size of the state reads and batch size of the state lookups. */
    public const DEFAULT_BATCH_SIZE = 500;

    private readonly IDomainEventErrorReporter $errors;

    private readonly TriggerEvaluator $evaluator;

    /** @var Closure(list<string>): ISpecification */
    private readonly Closure $identities;

    /** @var list<DomainEvent> */
    private array $events = [];

    private int $entered = 0;

    private int $exited = 0;

    private int $recorded = 0;

    private int $conflicts = 0;

    /** @var list<TriggerEvaluationError> */
    private array $failures = [];

    private int $loaded = 0;

    private int $statesRead = 0;

    /**
     * @param IRepository<\Antevemus\ASpecification\Contracts\Entities\IEntity> $repository Where the entities live
     * @param class-string $entityClass Concrete class of the entities (the states are keyed by it)
     * @param TriggerSet $triggers Triggers (the time-based ones are swept; all are recorded by baseline())
     * @param ITriggerStateStore $states Last known states (shared with TriggeringRepository)
     * @param IDomainEventPublisher $publisher Where the events go (an outbox in production)
     * @param ITransactionManager|null $transactions Unit of work around each crossing; null = none
     * @param IDomainEventErrorReporter|null $errors Reporter of evaluation errors; null = discard
     * @param TriggerEvaluator|null $evaluator Evaluator (custom identity reader); null = default
     * @param (Closure(list<string>): ISpecification)|null $identitiesSpecification Specification
     *        selecting the entities of several ids (text form); null = `entityId IN (...)` for a
     *        PdoRepository (also behind a TriggeringRepository), a PHP predicate on the evaluator's
     *        identity otherwise
     * @param int $batchSize Page and batch size (>= 1)
     * @throws InvalidArgumentException When the class is empty or too long, or the batch size is below 1
     */
    public function __construct(
        private readonly IRepository $repository,
        private readonly string $entityClass,
        private readonly TriggerSet $triggers,
        private readonly ITriggerStateStore $states,
        private readonly IDomainEventPublisher $publisher,
        private readonly ?ITransactionManager $transactions = null,
        ?IDomainEventErrorReporter $errors = null,
        ?TriggerEvaluator $evaluator = null,
        ?Closure $identitiesSpecification = null,
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE
    ) {
        if ($entityClass === '' || strlen($entityClass) > DomainEventSupport::MAX_ENTITY_CLASS_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The entity class must be a non-empty string of at most %d characters.',
                DomainEventSupport::MAX_ENTITY_CLASS_LENGTH
            ));
        }
        if ($batchSize < 1) {
            throw new InvalidArgumentException(sprintf('The batch size must be at least 1, %d given.', $batchSize));
        }
        $this->errors = $errors ?? new NullDomainEventErrorReporter();
        $this->evaluator = $evaluator ?? new TriggerEvaluator();
        $this->identities = $identitiesSpecification ?? $this->defaultIdentities();
    }

    /**
     * Identities specification on a mapped id property, translatable to SQL:
     * `Spec::property($property, Spec::in(...$ids))`, each id passed through $cast when given.
     *
     * @param string $property Id property name (as the repository's mapping knows it)
     * @param (Closure(string): mixed)|null $cast Converts the text form of an id to the stored value
     * @return Closure(list<string>): ISpecification
     */
    public static function byIdPropertyIn(string $property = 'entityId', ?Closure $cast = null): Closure
    {
        if ($property === '') {
            throw new InvalidArgumentException('The id property name cannot be empty.');
        }

        return static fn(array $ids): ISpecification => Spec::property(
            $property,
            Spec::in(...array_map($cast ?? static fn(string $id): string => $id, array_values($ids)))
        );
    }

    /** The repository. */
    public function getRepository(): IRepository
    {
        return $this->repository;
    }

    /** The concrete class of the entities. */
    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    /** The triggers. */
    public function getTriggers(): TriggerSet
    {
        return $this->triggers;
    }

    /** The trigger state store. */
    public function getStates(): ITriggerStateStore
    {
        return $this->states;
    }

    /**
     * Publishes the crossings of the time-based triggers since their last recorded state.
     *
     * @param DateTimeImmutable $now Instant of the sweep (the cron's "now")
     * @param int|null $limit Maximum number of crossings handled by this call; null = all
     * @throws InvalidArgumentException When the limit is below 1
     * @throws Throwable Failures of the repository, the store or the publisher propagate
     */
    public function sweep(DateTimeImmutable $now, ?int $limit = null): SweepReport
    {
        if ($limit !== null && $limit < 1) {
            throw new InvalidArgumentException(sprintf('The limit must be at least 1, %d given.', $limit));
        }
        $this->reset();
        $budget = $limit;
        $limitReached = false;

        foreach ($this->triggers->timedTriggers() as $trigger) {
            try {
                $specification = $trigger->specificationAt($now);
            } catch (Throwable $e) {
                $this->fail($trigger, null, $e);
                continue;
            }
            $inside = $this->sweepExits($trigger, $specification, $now, $budget);
            if ($inside === null) {
                $limitReached = true;
                break;
            }
            // Every entity satisfying the specification is recorded inside: nothing can enter
            if ($inside === $this->repository->count($specification)) {
                continue;
            }
            if (!$this->sweepEntries($trigger, $specification, $now, $budget)) {
                $limitReached = true;
                break;
            }
        }

        return $this->report($limitReached);
    }

    /**
     * Records the current state of every trigger for every entity, and forgets the satisfied
     * states of the entities that no longer exist, without publishing anything (D4).
     *
     * @param DateTimeImmutable $now Instant of the evaluation
     * @throws Throwable Failures of the repository or the store propagate
     */
    public function baseline(DateTimeImmutable $now): SweepReport
    {
        $this->reset();
        /** @var array<string, ISpecification> $specifications */
        $specifications = [];
        foreach ($this->triggers->all() as $trigger) {
            try {
                $specifications[$trigger->name] = $trigger->specificationAt($now);
            } catch (Throwable $e) {
                $this->fail($trigger, null, $e);
            }
        }
        if ($specifications === []) {
            return $this->report(false);
        }

        /** @var array<string, array<string, bool|Throwable>> $batch entity id => trigger => state */
        $batch = [];
        foreach ($this->repository->iterate(Spec::alwaysTrue()) as $entity) {
            $this->loaded++;
            $id = $this->keyOf((string) array_key_first($specifications), $entity);
            if ($id === null) {
                continue;
            }
            foreach ($specifications as $name => $specification) {
                try {
                    $batch[$id][$name] = $specification->isSatisfiedBy($entity) === true;
                } catch (Throwable $e) {
                    $batch[$id][$name] = $e;
                }
            }
            if (count($batch) >= $this->batchSize) {
                $this->recordBatch($batch, $now);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->recordBatch($batch, $now);
        }

        foreach (array_keys($specifications) as $name) {
            $this->forgetRemoved((string) $name);
        }

        return $this->report(false);
    }

    ///////////////////////////////////////////////////////////////////////////
    // Sweep
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Exit pass. Returns the number of recorded-inside entities confirmed inside, or null when the
     * limit stopped it.
     */
    private function sweepExits(SpecificationTrigger $trigger, ISpecification $specification, DateTimeImmutable $now, ?int &$budget): ?int
    {
        $inside = 0;
        $after = null;
        while (true) {
            $rows = $this->states->findSatisfied($trigger->name, $this->entityClass, $this->batchSize, $after);
            if ($rows === []) {
                return $inside;
            }
            $this->statesRead += count($rows);
            $after = $rows[count($rows) - 1]->entityId;
            $ids = array_map(static fn(TriggerState $row): string => $row->entityId, $rows);
            $narrowed = $specification->and(($this->identities)($ids));
            if ($this->repository->count($narrowed) === count($ids)) {
                $inside += count($ids);
                continue;
            }

            $still = [];
            foreach ($this->repository->iterate($narrowed) as $entity) {
                $this->loaded++;
                $id = $this->textOf($entity);
                if ($id !== null) {
                    $still[$id] = true;
                }
            }
            $left = [];
            foreach ($rows as $row) {
                if (isset($still[$row->entityId])) {
                    $inside++;
                } else {
                    $left[] = $row;
                }
            }
            $present = $this->load(array_map(static fn(TriggerState $row): string => $row->entityId, $left));

            foreach ($left as $row) {
                $entity = $present[$row->entityId] ?? null;
                // Present and still satisfying in PHP (or not evaluable): no exit
                if ($entity !== null && $this->evaluate($trigger, $specification, $entity) !== false) {
                    continue;
                }
                if ($budget === 0) {
                    return null;
                }
                $this->cross($trigger, DomainEventKind::EXIT, $row->entityId, $row, $entity, $now);
                if ($budget !== null) {
                    $budget--;
                }
            }
        }
    }

    /**
     * Entry pass. Returns false when the limit stopped it.
     */
    private function sweepEntries(SpecificationTrigger $trigger, ISpecification $specification, DateTimeImmutable $now, ?int &$budget): bool
    {
        /** @var list<array{0: string, 1: ?TriggerState}> $pending */
        $pending = [];
        /** @var list<string> $buffer */
        $buffer = [];
        $complete = true;
        foreach ($this->repository->iterate($specification) as $entity) {
            $this->loaded++;
            $id = $this->keyOf($trigger->name, $entity);
            if ($id === null || $this->evaluate($trigger, $specification, $entity) !== true) {
                continue;
            }
            $buffer[] = $id;
            if (count($buffer) >= $this->batchSize) {
                $this->collectEntries($trigger, $buffer, $pending);
                $buffer = [];
                if ($budget !== null && count($pending) >= $budget) {
                    $complete = false;
                    break;
                }
            }
        }
        if ($buffer !== []) {
            $this->collectEntries($trigger, $buffer, $pending);
        }
        if ($budget !== null && count($pending) > $budget) {
            $pending = array_slice($pending, 0, $budget);
            $complete = false;
        }

        // The cursor is closed: one transaction per crossing
        foreach ($pending as [$id, $row]) {
            $entity = $this->load([$id])[$id] ?? null;
            if ($entity === null || $this->evaluate($trigger, $specification, $entity) !== true) {
                continue;   // removed or changed since the scan
            }
            $this->cross($trigger, DomainEventKind::ENTER, $id, $row, $entity, $now);
            if ($budget !== null) {
                $budget--;
            }
        }

        return $complete;
    }

    /**
     * Adds to $pending the ids of the batch that are not recorded inside.
     *
     * @param list<string> $ids
     * @param list<array{0: string, 1: ?TriggerState}> $pending
     */
    private function collectEntries(SpecificationTrigger $trigger, array $ids, array &$pending): void
    {
        $rows = $this->states->findMany($trigger->name, $this->entityClass, $ids);
        $this->statesRead += count($rows);
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            if ($row === null || !$row->satisfied) {
                $pending[] = [$id, $row];
            }
        }
    }

    /**
     * One crossing: compare-and-set of the state and publication of the event, in one transaction.
     *
     * @param TriggerState|null $row State read during the scan (null = none recorded)
     * @param object|null $entity The entity (null = removed)
     */
    private function cross(
        SpecificationTrigger $trigger,
        DomainEventKind $kind,
        string $id,
        ?TriggerState $row,
        ?object $entity,
        DateTimeImmutable $now
    ): void {
        $event = null;
        $name = $trigger->eventFor($kind);
        if ($name !== null) {
            try {
                $event = new DomainEvent($name, $trigger->name, $kind, $this->entityClass, $id, $this->instantOf($trigger, $kind, $row, $entity, $now));
            } catch (InvalidArgumentException $e) {
                $this->fail($trigger, $id, $e);

                return;
            }
        }

        $won = $this->atomically(function () use ($trigger, $kind, $id, $row, $entity, $now, $event): bool {
            $written = ($kind === DomainEventKind::EXIT && $entity === null && $row !== null)
                ? $this->states->compareAndDelete($trigger->name, $this->entityClass, $id, $row->version)
                : $this->states->compareAndSet($trigger->name, $this->entityClass, $id, $row?->version, $kind === DomainEventKind::ENTER, $now);
            if (!$written) {
                return false;
            }
            if ($event !== null) {
                $this->publisher->publish([$event]);
            }

            return true;
        });

        if (!$won) {
            $this->conflicts++;

            return;
        }
        $kind === DomainEventKind::ENTER ? $this->entered++ : $this->exited++;
        if ($event !== null) {
            $this->events[] = $event;
        }
    }

    /**
     * Instant of a crossing: the trigger's crossing instant when it lies after the recorded
     * evaluation and not after $now, $now otherwise (D5).
     */
    private function instantOf(SpecificationTrigger $trigger, DomainEventKind $kind, ?TriggerState $row, ?object $entity, DateTimeImmutable $now): DateTimeImmutable
    {
        if ($entity === null || !$trigger->hasCrossingInstant()) {
            return $now;
        }
        try {
            $instant = $trigger->crossingInstant($entity, $now, $kind);
        } catch (Throwable $e) {
            $this->fail($trigger, $this->textOf($entity), $e);

            return $now;
        }
        // Before the recorded evaluation it cannot be this crossing (a later re-entry, a stale
        // calculator); after $now it has not happened yet
        if ($instant === null || $instant > $now || ($row !== null && $instant <= $row->evaluatedAt)) {
            return $now;
        }

        return $instant;
    }

    ///////////////////////////////////////////////////////////////////////////
    // Baseline
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Records the states of one batch of entities (one transaction per batch).
     *
     * @param array<string, array<string, bool|Throwable>> $batch entity id => trigger => state
     */
    private function recordBatch(array $batch, DateTimeImmutable $now): void
    {
        $ids = array_map('strval', array_keys($batch));
        $this->atomically(function () use ($batch, $ids, $now): void {
            foreach ($this->triggers->all() as $trigger) {
                $rows = null;
                foreach ($ids as $id) {
                    $state = $batch[$id][$trigger->name] ?? null;
                    if ($state === null) {
                        continue;   // the trigger's specification could not be built (reported)
                    }
                    if ($state instanceof Throwable) {
                        $this->fail($trigger, $id, $state);
                        continue;
                    }
                    if ($rows === null) {
                        $rows = $this->states->findMany($trigger->name, $this->entityClass, $ids);
                        $this->statesRead += count($rows);
                    }
                    $row = $rows[$id] ?? null;
                    if ($row !== null && $row->satisfied === $state) {
                        continue;
                    }
                    if ($this->states->compareAndSet($trigger->name, $this->entityClass, $id, $row?->version, $state, $now)) {
                        $this->recorded++;
                    } else {
                        $this->conflicts++;
                    }
                }
            }
        });
    }

    /**
     * Deletes the satisfied states of one trigger whose entities no longer exist.
     */
    private function forgetRemoved(string $triggerName): void
    {
        $after = null;
        while (true) {
            $rows = $this->states->findSatisfied($triggerName, $this->entityClass, $this->batchSize, $after);
            if ($rows === []) {
                return;
            }
            $this->statesRead += count($rows);
            $after = $rows[count($rows) - 1]->entityId;
            $ids = array_map(static fn(TriggerState $row): string => $row->entityId, $rows);
            if ($this->repository->count(($this->identities)($ids)) === count($ids)) {
                continue;
            }
            $present = $this->load($ids);
            $this->atomically(function () use ($rows, $present, $triggerName): void {
                foreach ($rows as $row) {
                    if (isset($present[$row->entityId])) {
                        continue;
                    }
                    if ($this->states->compareAndDelete($triggerName, $this->entityClass, $row->entityId, $row->version)) {
                        $this->recorded++;
                    } else {
                        $this->conflicts++;
                    }
                }
            });
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // Internals
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Loads the entities of several ids, by id (text form).
     *
     * @param list<string> $ids
     * @return array<string, object>
     */
    private function load(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $found = [];
        foreach ($this->repository->iterate(($this->identities)($ids)) as $entity) {
            $this->loaded++;
            $id = $this->textOf($entity);
            if ($id !== null) {
                $found[$id] = $entity;
            }
        }

        return $found;
    }

    /**
     * Whether the entity satisfies the trigger's specification at the sweep instant (PHP), or null
     * when it cannot be evaluated (reported).
     */
    private function evaluate(SpecificationTrigger $trigger, ISpecification $specification, object $entity): ?bool
    {
        try {
            return $specification->isSatisfiedBy($entity) === true;
        } catch (Throwable $e) {
            $this->fail($trigger, $this->textOf($entity), $e);

            return null;
        }
    }

    /**
     * The id under which the entity's states are keyed, or null (reported) when the entity is not
     * of the sweeper's class or its id cannot be read.
     */
    private function keyOf(?string $triggerName, object $entity): ?string
    {
        if (get_class($entity) !== $this->entityClass) {
            $this->failByName($triggerName, null, new LogicException(sprintf(
                'The repository returned a %s to a sweeper of %s: the states are keyed by the concrete class, give each class its own sweeper.',
                get_class($entity),
                $this->entityClass
            )));

            return null;
        }
        try {
            return $this->evaluator->identityOf($entity);
        } catch (InvalidArgumentException $e) {
            $this->failByName($triggerName, null, $e);

            return null;
        }
    }

    /** Text form of the entity id, or null. */
    private function textOf(object $entity): ?string
    {
        try {
            return $this->evaluator->identityOf($entity);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function fail(SpecificationTrigger $trigger, ?string $entityId, Throwable $error): void
    {
        $this->failByName($trigger->name, $entityId, $error);
    }

    private function failByName(?string $triggerName, ?string $entityId, Throwable $error): void
    {
        $failure = new TriggerEvaluationError(
            $triggerName ?? '(baseline)',
            TriggerEvaluationError::SIDE_SWEEP,
            $this->entityClass,
            $entityId,
            $error
        );
        $this->failures[] = $failure;
        try {
            $this->errors->report($failure);
        } catch (Throwable) {
            // A broken reporter never changes the outcome of the sweep.
        }
    }

    /**
     * Default identities specification: SQL for a PdoRepository (`entityId` always maps to the
     * identifier column; integer identifiers are compared as integers), a PHP predicate on the
     * evaluator's identity otherwise.
     *
     * @return Closure(list<string>): ISpecification
     */
    private function defaultIdentities(): Closure
    {
        $repository = $this->repository instanceof TriggeringRepository ? $this->repository->getInner() : $this->repository;
        if ($repository instanceof PdoRepository) {
            $integer = $repository->getMapping()->getId()->type === ColumnType::Integer;

            return self::byIdPropertyIn(
                'entityId',
                $integer ? static fn(string $id): int|string => preg_match('/^-?[0-9]+$/', $id) === 1 ? (int) $id : $id : null
            );
        }

        return function (array $ids): ISpecification {
            $wanted = array_fill_keys(array_map('strval', $ids), true);

            return new PredicateSpecification(function (mixed $candidate) use ($wanted): bool {
                return is_object($candidate) && ($id = $this->textOf($candidate)) !== null && isset($wanted[$id]);
            });
        };
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

    private function reset(): void
    {
        $this->events = [];
        $this->entered = 0;
        $this->exited = 0;
        $this->recorded = 0;
        $this->conflicts = 0;
        $this->failures = [];
        $this->loaded = 0;
        $this->statesRead = 0;
    }

    private function report(bool $limitReached): SweepReport
    {
        return new SweepReport(
            $this->events,
            $this->entered,
            $this->exited,
            $this->recorded,
            $this->conflicts,
            $this->failures,
            $this->loaded,
            $this->statesRead,
            $limitReached
        );
    }
}
