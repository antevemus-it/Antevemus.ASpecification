<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Runner;

use Antevemus\ASpecification\StateMachine\Effect;
use Antevemus\ASpecification\StateMachine\Event;
use Antevemus\ASpecification\StateMachine\EventOrigin;
use Antevemus\ASpecification\StateMachine\Exceptions\ConcurrencyConflictException;
use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachineInstanceAlreadyExistsException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachineInstanceNotFoundException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachineMigrationException;
use Antevemus\ASpecification\StateMachine\Exceptions\StateMachineException;
use Antevemus\ASpecification\StateMachine\Exceptions\SyncEffectFailedException;
use Antevemus\ASpecification\StateMachine\Exceptions\UnknownMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\MachineDefinition;
use Antevemus\ASpecification\StateMachine\MachineDefinitionValidator;
use Antevemus\ASpecification\StateMachine\MachineSnapshot;
use Antevemus\ASpecification\StateMachine\Persistence\DueDeadline;
use Antevemus\ASpecification\StateMachine\Persistence\EventSnapshot;
use Antevemus\ASpecification\StateMachine\Persistence\HistoryEntry;
use Antevemus\ASpecification\StateMachine\Persistence\IBatchIdempotencyStore;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineInstanceLister;
use Antevemus\ASpecification\StateMachine\Persistence\ITenantScopedInstanceStore;
use Antevemus\ASpecification\StateMachine\Persistence\MachineInstance;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Persistence\OutboxEntry;
use Antevemus\ASpecification\StateMachine\Persistence\OutboxStatus;
use Antevemus\ASpecification\StateMachine\Persistence\PersistenceSupport;
use Antevemus\ASpecification\StateMachine\Persistence\RejectionEntry;
use Antevemus\ASpecification\StateMachine\StateMachine;
use Antevemus\ASpecification\StateMachine\TransitionResult;
use Antevemus\ASpecification\Util\UlidGenerator;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * MachineRunner - The Single Write Port of the State Machine
 *
 * Applies the pure core's results to persisted instances (forward 023, D5, D12-D16, D18,
 * RN-07 to RN-10). Every write happens in ONE transaction of the persistence's
 * ITransactionManager:
 *
 * send(instanceId, event, context): load the instance and the definition version it is pinned to
 * (validated once per runner) → idempotency check (a key already processed gives the ORIGINAL
 * result back and writes nothing) → optional expected-version check → pure transition() →
 * when accepted: compare-and-set of the snapshot (version + 1), one history row per taken
 * transition, the effects in the outbox, synchronous effects run, idempotency record → commit.
 * Rejected and unhandled events write nothing (except the optional rejection log) and the result
 * is returned as is. A lost compare-and-set throws ConcurrencyConflictException and is never
 * retried here; a synchronous effect that throws rolls everything back
 * (SyncEffectFailedException).
 *
 * start(): creates the instance at the initial snapshot (version 1), with one history row per
 * region (event type START_EVENT) and the initial entry effects in the outbox. migrate(): moves an
 * instance to another definition version through a registered IMachineMigration, checked against
 * the validated target definition, with one history row per target region (event type
 * MIGRATE_EVENT). deliverDue(): sends the deadline event of each due region with internal origin
 * and a deterministic idempotency key, so a repeated delivery returns the original result and an
 * early one is rejected by the core (D18). A start whose initial deadline source is missing or
 * invalid is rejected and writes nothing (forward 025, D8); a paused deadline is never due (D11).
 *
 * The runner reads no clock: every instant comes from the events (or the caller's $now).
 *
 * Since 2.7.0:
 * - tenant: start() takes the tenant the instance belongs to (an opaque text of the application, fixed
 *   for the life of the instance); send(), load(), can(), nextEvents(), migrate(), findDue() and
 *   deliverDue() take it at the end. With a tenant, the instance is loaded through
 *   ITenantScopedInstanceStore::loadForTenant(), so an instance of another tenant is
 *   MachineInstanceNotFoundException, the same error as an unknown id; a tenant asked of a store that
 *   is not tenant-scoped is a StateMachineException. Without a tenant everything works as before
 *   (the operator's routine delivers the deadlines of every tenant);
 * - migrateAll(): the instances of one version moved to another in batches, one transaction per
 *   instance, a report per instance (IMachineInstanceLister);
 * - a registered IDefinitionAwareMachineMigration (MachineMigrations::byStateName()) receives the
 *   validated target definition.
 *
 * Since 2.10.0:
 * - startAt(): creates an instance already in given states (a process brought from another system),
 *   with one history row per region of the reserved type IMPORT_EVENT, no entry action, no guard and
 *   no effect; a final state only with allowFinal; deadlines counted from the given instant;
 * - every outbox entry carries the event of its step (EventSnapshot), and a handler that declares a
 *   fourth parameter receives an EffectContext (synchronous handlers here, the others through the
 *   EffectDispatcher);
 * - sendMany(): many events in one call, one transaction per chunk, the idempotency keys of a chunk
 *   looked up together (IBatchIdempotencyStore); one BatchEventOutcome per event, equal to what send()
 *   would have given (see the method). send() is unchanged.
 *
 * @version    2.10.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Runner
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MachineRunner
{
    /** Reserved event type of the history rows written by start(). */
    public const START_EVENT = 'machine.start';

    /** Reserved event type of the history rows written by migrate(). */
    public const MIGRATE_EVENT = 'machine.migrate';

    /** Reserved event type of the history rows written by startAt() (2.10.0). */
    public const IMPORT_EVENT = 'machine.import';

    /** Event types send() refuses: those of the history rows the runner writes by itself (2.10.0 adds IMPORT_EVENT). */
    public const RESERVED_EVENTS = [self::START_EVENT, self::MIGRATE_EVENT, self::IMPORT_EVENT];

    /** Default number of events per transaction of sendMany() (2.10.0). */
    public const DEFAULT_CHUNK_SIZE = 100;

    /** Prefix of the idempotency key of deadline events: `deadline:{region}:{due instant, UTC}`. */
    public const DEADLINE_KEY_PREFIX = 'deadline:';

    private readonly EffectHandlerRegistry $effects;

    /** @var array<string, true> "key#version" of the definitions already validated */
    private array $validated = [];

    /** @var array<string, IMachineMigration> "key#from#to" => migration */
    private array $migrations = [];

    /**
     * @param StateMachine $machine Pure core (its guard registry validates the definitions)
     * @param IMachineDefinitionRepository $definitions Every version that still has instances
     * @param MachinePersistence $persistence Ports written in one transaction
     * @param EffectHandlerRegistry|null $effects Handlers; only the synchronous ones run here
     */
    public function __construct(
        private readonly StateMachine $machine,
        private readonly IMachineDefinitionRepository $definitions,
        private readonly MachinePersistence $persistence,
        ?EffectHandlerRegistry $effects = null
    ) {
        $this->effects = $effects ?? new EffectHandlerRegistry();
    }

    /** The pure core. */
    public function getStateMachine(): StateMachine
    {
        return $this->machine;
    }

    /** The persistence ports. */
    public function getPersistence(): MachinePersistence
    {
        return $this->persistence;
    }

    /**
     * Creates an instance at the initial snapshot of the definition (D13): version 1, one history
     * row per region, the initial entry effects in the outbox (synchronous ones run).
     *
     * @param string $instanceId Instance id chosen by the caller (at most 120 characters)
     * @param string $machineKey Machine key
     * @param int|null $version Definition version; null = the latest registered
     * @param object|array<mixed>|null $context Domain context: read by deadline sources and pause
     *                                          conditions of the initial states (no guard runs at start)
     * @param DateTimeImmutable $occurredAt Instant of the start (deadlines count from it)
     * @param string|null $actor Who started it, recorded in the history
     * @param string|null $tenant Tenant the instance belongs to (2.7.0), fixed for its life; null = none
     * @return TransitionResult Accepted start result (event null); rejected, with nothing written,
     *                          when an initial deadline cannot be computed (SM_DEADLINE_SOURCE_INVALID)
     * @throws MachineInstanceAlreadyExistsException When the id is taken (by any tenant)
     * @throws UnknownMachineDefinitionException When the definition is not registered
     * @throws InvalidMachineDefinitionException When the definition is refused by the validator
     * @throws SyncEffectFailedException When a synchronous entry effect throws (nothing written)
     * @throws InvalidArgumentException When the tenant is empty, padded or longer than 120 characters
     */
    public function start(
        string $instanceId,
        string $machineKey,
        ?int $version,
        object|array|null $context,
        DateTimeImmutable $occurredAt,
        ?string $actor = null,
        ?string $tenant = null
    ): TransitionResult {
        PersistenceSupport::assertInstanceId($instanceId);
        $version ??= $this->definitions->latestVersion($machineKey)
            ?? throw new UnknownMachineDefinitionException(sprintf('Machine "%s" has no registered version.', $machineKey));
        $definition = $this->definitionFor($machineKey, $version);

        return $this->persistence->transactions->transactional(function () use ($instanceId, $definition, $context, $occurredAt, $actor, $tenant): TransitionResult {
            if ($this->persistence->instances->load($instanceId) !== null) {
                throw new MachineInstanceAlreadyExistsException(sprintf('Machine instance "%s" already exists.', $instanceId));
            }
            $result = $this->machine->initialSnapshot($definition, $context, $occurredAt);
            if (!$result->isAccepted()) {
                return $result;
            }
            $instance = new MachineInstance($instanceId, $result->snapshot, 1, $occurredAt, $occurredAt, $tenant);
            $this->persistence->instances->insert($instance);

            $rows = [];
            foreach ($result->getTransitions() as $order => $taken) {
                $rows[] = new HistoryEntry(
                    $instanceId, 1, $order, $definition->key, $definition->version, $taken->region,
                    null, $taken->to, self::START_EVENT, null, [], $actor, null, EventOrigin::INTERNAL, $occurredAt
                );
            }
            $this->persistence->history->append($rows);
            $this->writeEffects($instance, $result->getEffects(), $occurredAt, new EventSnapshot(self::START_EVENT, [], $actor, EventOrigin::INTERNAL, $occurredAt));

            return $result;
        });
    }

    /**
     * Creates an instance already in the given states (2.10.0): a process brought from another system
     * (a migration of open processes) enters the machine where it stands, not at the initial state.
     *
     * Version 1, one history row per region (event type IMPORT_EVENT, from null, to the state, internal
     * origin, the actor and the payload given here), NO entry action, NO guard, NO effect: the states are
     * a fact, not a transition. A region left out of $statesByRegion starts in its initial state. The
     * deadlines of the given states count from $at (and from the context for `afterFrom`/`atFrom`), and
     * pause when their `pausedWhile` holds, as at the start. A final state is refused unless $allowFinal
     * (an instance imported already finished is then created done).
     *
     * @param string $instanceId Instance id chosen by the caller (at most 120 characters)
     * @param string $machineKey Machine key
     * @param int|null $version Definition version; null = the latest registered
     * @param array<string, string> $statesByRegion Region name => state name
     * @param object|array<mixed>|null $context Domain context read by deadline sources and pause conditions
     * @param DateTimeImmutable $at Instant of the import (deadlines count from it)
     * @param string|null $actor Who imported it, recorded in the history
     * @param array<mixed> $payload Data of the import recorded in the history rows (e.g. the id in the
     *                              other system); empty by default
     * @param bool $allowFinal Whether a final state may be given
     * @param string|null $tenant Tenant the instance belongs to, fixed for its life; null = none
     * @return TransitionResult Accepted result (event null, no effect); rejected, with nothing written,
     *                          when a deadline cannot be computed (SM_DEADLINE_SOURCE_INVALID)
     * @throws \Antevemus\ASpecification\StateMachine\Exceptions\InvalidSnapshotException When a region is
     *         unknown, a state is not declared in its region, or a final state is given without $allowFinal
     * @throws MachineInstanceAlreadyExistsException When the id is taken (by any tenant)
     * @throws UnknownMachineDefinitionException When the definition is not registered
     * @throws InvalidMachineDefinitionException When the definition is refused by the validator
     * @throws InvalidArgumentException When the tenant is empty, padded or longer than 120 characters
     */
    public function startAt(
        string $instanceId,
        string $machineKey,
        ?int $version,
        array $statesByRegion,
        object|array|null $context,
        DateTimeImmutable $at,
        ?string $actor = null,
        array $payload = [],
        bool $allowFinal = false,
        ?string $tenant = null
    ): TransitionResult {
        PersistenceSupport::assertInstanceId($instanceId);
        $version ??= $this->definitions->latestVersion($machineKey)
            ?? throw new UnknownMachineDefinitionException(sprintf('Machine "%s" has no registered version.', $machineKey));
        $definition = $this->definitionFor($machineKey, $version);
        $result = $this->machine->snapshotAt($definition, $statesByRegion, $context, $at, $allowFinal);

        return $this->persistence->transactions->transactional(function () use ($instanceId, $definition, $result, $at, $actor, $payload, $tenant): TransitionResult {
            if ($this->persistence->instances->load($instanceId) !== null) {
                throw new MachineInstanceAlreadyExistsException(sprintf('Machine instance "%s" already exists.', $instanceId));
            }
            if (!$result->isAccepted()) {
                return $result;
            }
            $this->persistence->instances->insert(new MachineInstance($instanceId, $result->snapshot, 1, $at, $at, $tenant));

            $rows = [];
            foreach ($result->getTransitions() as $order => $taken) {
                $rows[] = new HistoryEntry(
                    $instanceId, 1, $order, $definition->key, $definition->version, $taken->region,
                    null, $taken->to, self::IMPORT_EVENT, null, $payload, $actor, null, EventOrigin::INTERNAL, $at
                );
            }
            $this->persistence->history->append($rows);

            return $result;
        });
    }

    /**
     * Sends an event to a persisted instance (D13). See the class description for the steps.
     *
     * @param string $instanceId Instance id
     * @param Event $event Event (its idempotency key, when given, deduplicates per instance)
     * @param object|array<mixed>|null $context Domain context read by the guards
     * @param string|null $actor Who triggered it, recorded in the history
     * @param int|null $expectedVersion Version the caller saw (optimistic lock across a UI round
     *                                  trip); null = no check beyond the internal CAS
     * @param string|null $tenant Tenant the caller acts for (2.7.0); null = no tenant check
     * @return TransitionResult The result (the original one for a repeated idempotency key)
     * @throws MachineInstanceNotFoundException When the id is unknown (or belongs to another tenant)
     * @throws ConcurrencyConflictException When the version moved (nothing written)
     * @throws SyncEffectFailedException When a synchronous effect throws (nothing written)
     * @throws InvalidArgumentException When the event type is reserved by the runner
     * @throws StateMachineException When a tenant is given and the store is not tenant-scoped
     */
    public function send(
        string $instanceId,
        Event $event,
        object|array|null $context = null,
        ?string $actor = null,
        ?int $expectedVersion = null,
        ?string $tenant = null
    ): TransitionResult {
        if (in_array($event->type, self::RESERVED_EVENTS, true)) {
            throw new InvalidArgumentException(sprintf('Event type "%s" is reserved by the machine runner.', $event->type));
        }

        return $this->persistence->transactions->transactional(function () use ($instanceId, $event, $context, $actor, $expectedVersion, $tenant): TransitionResult {
            $instance = $this->mustLoad($instanceId, $tenant);

            if ($event->idempotencyKey !== null) {
                $original = $this->persistence->idempotency->find($instanceId, $event->idempotencyKey);
                if ($original !== null) {
                    return $original;
                }
            }
            if ($expectedVersion !== null && $expectedVersion !== $instance->version) {
                throw new ConcurrencyConflictException($instanceId, $expectedVersion, $instance->version);
            }

            $definition = $this->definitionFor($instance->getMachineKey(), $instance->getMachineVersion());
            $result = $this->machine->transition($definition, $instance->snapshot, $event, $context);

            if (!$result->isAccepted()) {
                $this->persistence->rejections?->record(new RejectionEntry(
                    UlidGenerator::ulid(),
                    $instanceId,
                    $instance->version,
                    $definition->key,
                    $definition->version,
                    $result->status,
                    $event->type,
                    $event->payload,
                    $actor,
                    $event->idempotencyKey,
                    $event->origin,
                    $result->toArray()['reason'],
                    $event->occurredAt
                ));

                return $result;
            }

            $next = $instance->next($result->snapshot, $event->occurredAt);
            $this->persistence->instances->compareAndSet($next, $instance->version);

            $rows = [];
            foreach ($result->getTransitions() as $order => $taken) {
                $rows[] = new HistoryEntry(
                    $instanceId, $next->version, $order, $definition->key, $definition->version, $taken->region,
                    $taken->from, $taken->to, $taken->eventType ?? $event->type, $taken->transitionId,
                    $event->payload, $actor, $event->idempotencyKey, $event->origin, $event->occurredAt
                );
            }
            $this->persistence->history->append($rows);
            $this->writeEffects($next, $result->getEffects(), $event->occurredAt, EventSnapshot::of($event, $actor));

            if ($event->idempotencyKey !== null) {
                $this->persistence->idempotency->record($instanceId, $event->idempotencyKey, $next->version, $result, $event->occurredAt);
            }

            return $result;
        });
    }

    /**
     * Sends many events (2.10.0): the same work as one send() per event, in the given order, with one
     * transaction per chunk of $chunkSize events instead of one per event, and the idempotency keys of a
     * chunk looked up together (one `IN (...)` query with the PDO store; one find() per event with a
     * store that does not implement IBatchIdempotencyStore). send() itself is unchanged.
     *
     * One BatchEventOutcome per event, in order: the result send() would have returned, or the exception
     * send() would have thrown for that event (unknown instance, version moved, synchronous effect
     * veto), with nothing of that event written. The snapshots, history rows, outbox entries and
     * idempotency records are those of the same send() calls in the same order.
     *
     * Failures after the first write of an event (a synchronous effect veto, a key recorded meanwhile, a
     * database error) cannot be undone alone inside the chunk's transaction:
     * - when the runner opened that transaction, it is rolled back and the chunk is sent again one event
     *   at a time through send(), each in its own transaction, so the result is still that of send() per
     *   event (a database error then propagates from the event that causes it, the earlier ones of the
     *   chunk committed, as with a loop of send());
     * - when the caller opened it (the runner joins an open transaction), the exception propagates, as it
     *   would from send(), and the caller rolls its transaction back.
     * The chunks before the failing one stay committed.
     *
     * @param iterable<BatchEvent> $events Events, consumed once, a chunk at a time
     * @param string|null $actor Default actor of the events that do not name one
     * @param int $chunkSize Events per transaction (>= 1; default 100)
     * @param string|null $tenant Tenant the caller acts for; null = no tenant check
     * @return list<BatchEventOutcome>
     * @throws InvalidArgumentException When $chunkSize < 1 or an item is not a BatchEvent
     * @throws StateMachineException When a tenant is given and the store is not tenant-scoped
     */
    public function sendMany(iterable $events, ?string $actor = null, int $chunkSize = self::DEFAULT_CHUNK_SIZE, ?string $tenant = null): array
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException(sprintf('The chunk size must be at least 1, %d given.', $chunkSize));
        }
        $outcomes = [];
        $chunk = [];
        $index = 0;
        foreach ($events as $item) {
            if (!$item instanceof BatchEvent) {
                throw new InvalidArgumentException(sprintf('sendMany() expects BatchEvent items, %s given at position %d.', get_debug_type($item), $index));
            }
            $chunk[$index++] = $item;
            if (count($chunk) === $chunkSize) {
                array_push($outcomes, ...$this->sendChunk($chunk, $actor, $tenant));
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            array_push($outcomes, ...$this->sendChunk($chunk, $actor, $tenant));
        }

        return $outcomes;
    }

    /**
     * Loads a persisted instance (of the tenant, when one is given, 2.7.0).
     *
     * @throws MachineInstanceNotFoundException When the id is unknown (or belongs to another tenant)
     * @throws StateMachineException When a tenant is given and the store is not tenant-scoped
     */
    public function load(string $instanceId, ?string $tenant = null): MachineInstance
    {
        return $this->mustLoad($instanceId, $tenant);
    }

    /**
     * Tells what the event would do to the persisted instance, without writing anything (D7).
     *
     * @param string $instanceId Instance id
     * @param Event $event Event to test
     * @param object|array<mixed>|null $context Domain context read by the guards
     * @param string|null $tenant Tenant the caller acts for (2.7.0); null = no tenant check
     * @return TransitionResult
     * @throws MachineInstanceNotFoundException When the id is unknown (or belongs to another tenant)
     */
    public function can(string $instanceId, Event $event, object|array|null $context = null, ?string $tenant = null): TransitionResult
    {
        $instance = $this->mustLoad($instanceId, $tenant);

        return $this->machine->can($this->definitionFor($instance->getMachineKey(), $instance->getMachineVersion()), $instance->snapshot, $event, $context);
    }

    /**
     * Events answerable by the persisted instance, with the result of each (D7).
     *
     * @param string $instanceId Instance id
     * @param object|array<mixed>|null $context Domain context read by the guards
     * @param DateTimeImmutable $occurredAt Instant used for the test events
     * @param string|null $tenant Tenant the caller acts for (2.7.0); null = no tenant check
     * @return array<string, TransitionResult>
     * @throws MachineInstanceNotFoundException When the id is unknown (or belongs to another tenant)
     */
    public function nextEvents(string $instanceId, object|array|null $context, DateTimeImmutable $occurredAt, ?string $tenant = null): array
    {
        $instance = $this->mustLoad($instanceId, $tenant);

        return $this->machine->nextEvents(
            $this->definitionFor($instance->getMachineKey(), $instance->getMachineVersion()),
            $instance->snapshot,
            $context,
            $occurredAt
        );
    }

    /**
     * Registers the migration of a machine from one version to another (D16).
     *
     * @param string $machineKey Machine key
     * @param int $fromVersion Source version
     * @param int $toVersion Target version
     * @param IMachineMigration|Closure(MachineSnapshot): MachineSnapshot $migration Snapshot mapping
     * @return self
     */
    public function registerMigration(string $machineKey, int $fromVersion, int $toVersion, IMachineMigration|Closure $migration): self
    {
        if ($fromVersion === $toVersion) {
            throw new InvalidArgumentException('A migration needs two different versions.');
        }
        $this->migrations[self::migrationKey($machineKey, $fromVersion, $toVersion)] = $migration instanceof Closure
            ? new CallbackMachineMigration($migration)
            : $migration;

        return $this;
    }

    /**
     * Migrates an instance to another version of its definition (D16): the registered migration
     * maps the snapshot, the result is checked against the validated target definition, saved by
     * compare-and-set (version + 1) and recorded in the history (one row per target region, event
     * type MIGRATE_EVENT, payload {from_version, to_version}). No effect runs.
     *
     * @param string $instanceId Instance id
     * @param int $toVersion Target version
     * @param DateTimeImmutable $occurredAt Instant of the migration
     * @param string|null $actor Who migrated it
     * @param int|null $expectedVersion Version the caller saw; null = no check beyond the CAS
     * @param string|null $tenant Tenant the caller acts for (2.7.0); null = no tenant check
     * @return MachineInstance The migrated instance (its tenant kept)
     * @throws MachineMigrationException When no migration is registered or its result does not conform
     * @throws ConcurrencyConflictException When the version moved
     * @throws MachineInstanceNotFoundException When the id is unknown (or belongs to another tenant)
     */
    public function migrate(
        string $instanceId,
        int $toVersion,
        DateTimeImmutable $occurredAt,
        ?string $actor = null,
        ?int $expectedVersion = null,
        ?string $tenant = null
    ): MachineInstance {
        return $this->persistence->transactions->transactional(function () use ($instanceId, $toVersion, $occurredAt, $actor, $expectedVersion, $tenant): MachineInstance {
            $instance = $this->mustLoad($instanceId, $tenant);
            if ($expectedVersion !== null && $expectedVersion !== $instance->version) {
                throw new ConcurrencyConflictException($instanceId, $expectedVersion, $instance->version);
            }
            $key = $instance->getMachineKey();
            $fromVersion = $instance->getMachineVersion();
            $migration = $this->migrations[self::migrationKey($key, $fromVersion, $toVersion)]
                ?? throw new MachineMigrationException(sprintf(
                    'No migration is registered for machine "%s" from v%d to v%d.',
                    $key,
                    $fromVersion,
                    $toVersion
                ));
            $target = $this->definitionFor($key, $toVersion);

            $migrated = $migration instanceof IDefinitionAwareMachineMigration
                ? $migration->migrateTo($instance->snapshot, $target)
                : $migration->migrate($instance->snapshot);
            $this->assertConforms($target, $migrated);
            $done = StateMachine::isDone($target, $migrated);
            if ($done !== $migrated->isDone()) {
                $migrated = $migrated->withDone($done);
            }

            $next = $instance->next($migrated, $occurredAt);
            $this->persistence->instances->compareAndSet($next, $instance->version);

            $previous = $instance->snapshot->getStates();
            $rows = [];
            $order = 0;
            foreach ($migrated->getStates() as $region => $state) {
                $rows[] = new HistoryEntry(
                    $instanceId, $next->version, $order++, $key, $toVersion, $region,
                    $previous[$region] ?? null, $state, self::MIGRATE_EVENT, null,
                    ['from_version' => $fromVersion, 'to_version' => $toVersion],
                    $actor, null, EventOrigin::INTERNAL, $occurredAt
                );
            }
            $this->persistence->history->append($rows);

            return $next;
        });
    }

    /**
     * Migrates the instances of one version to another in a batch (2.7.0): the ids of at most $limit
     * instances pinned to $fromVersion (after $after, in the store's order of the id), each migrated by
     * migrate() in its own transaction through the migration registered for (key, from, to). A
     * concurrency conflict, a snapshot the target refuses or an instance gone or moved since the listing
     * is skipped and reported; the next run retries it. Call it again (with the last id as $after when
     * skipped instances stay) until it returns an empty list.
     *
     * @param string $machineKey Machine key
     * @param int $fromVersion Version the instances are pinned to
     * @param int $toVersion Target version
     * @param DateTimeImmutable $occurredAt Instant of the migrations
     * @param string|null $actor Who migrated them
     * @param int $limit Maximum number of instances in this batch (>= 1)
     * @param string|null $after List after this id (keyset paging); null = from the start
     * @return list<InstanceMigration>
     * @throws MachineMigrationException When no migration is registered for (key, from, to)
     * @throws StateMachineException When the instance store cannot list instances (IMachineInstanceLister)
     * @throws UnknownMachineDefinitionException|InvalidMachineDefinitionException When the target cannot run
     */
    public function migrateAll(
        string $machineKey,
        int $fromVersion,
        int $toVersion,
        DateTimeImmutable $occurredAt,
        ?string $actor = null,
        int $limit = 100,
        ?string $after = null
    ): array {
        $lister = $this->persistence->instances;
        if (!$lister instanceof IMachineInstanceLister) {
            throw new StateMachineException(sprintf(
                'migrateAll() needs an instance store that lists instances (IMachineInstanceLister); %s does not.',
                get_debug_type($lister)
            ));
        }
        if (!isset($this->migrations[self::migrationKey($machineKey, $fromVersion, $toVersion)])) {
            throw new MachineMigrationException(sprintf(
                'No migration is registered for machine "%s" from v%d to v%d.',
                $machineKey,
                $fromVersion,
                $toVersion
            ));
        }
        $this->definitionFor($machineKey, $toVersion);

        $outcomes = [];
        foreach ($lister->idsByMachineVersion($machineKey, $fromVersion, $limit, $after) as $instanceId) {
            try {
                $outcomes[] = new InstanceMigration($instanceId, $this->migrate($instanceId, $toVersion, $occurredAt, $actor));
            } catch (ConcurrencyConflictException | MachineMigrationException | MachineInstanceNotFoundException $e) {
                $outcomes[] = new InstanceMigration($instanceId, null, $e->getMessage());
            }
        }

        return $outcomes;
    }

    /**
     * Due deadlines (D18), as found by the instance store (of one tenant, when given, 2.7.0).
     *
     * @param DateTimeImmutable $now Reference instant
     * @param string|null $machineKey Only this machine, when given
     * @param int $limit Maximum number of deadlines
     * @param string|null $tenant Only this tenant's instances (2.7.0); null = every instance
     * @return list<DueDeadline>
     * @throws StateMachineException When a tenant is given and the store is not tenant-scoped
     */
    public function findDue(DateTimeImmutable $now, ?string $machineKey = null, int $limit = 100, ?string $tenant = null): array
    {
        if ($tenant === null) {
            return $this->persistence->instances->findDue($now, $machineKey, $limit);
        }

        return $this->tenantScopedStore()->findDueForTenant($now, $tenant, $machineKey, $limit);
    }

    /**
     * Delivers the due deadlines (D18): for each one, the deadline event of the region's CURRENT
     * state is sent through send() with internal origin, occurredAt = $now, payload
     * {region, due_at} and the idempotency key `deadline:{region}:{due instant}`. Each delivery is
     * its own transaction. Delivering twice gives the original result back; delivering after the
     * instance moved on is unhandled or rejected, never harmful. A concurrency conflict or a
     * synchronous effect veto skips that deadline (the next run retries it); other errors
     * propagate.
     *
     * The event is sent with a null context unless $contextProvider is given (since 1.9.0): a
     * deadline transition entering a state whose deadline reads the context (`afterFrom`,
     * `atFrom`), or a pause condition that reads it, needs the domain context of the instance.
     *
     * @param DateTimeImmutable $now Reference instant ("now" of the cron)
     * @param string|null $machineKey Only this machine, when given
     * @param int $limit Maximum number of deadlines
     * @param string|null $actor Recorded in the history (e.g. "scheduler")
     * @param (Closure(DueDeadline, MachineInstance): (object|array<mixed>|null))|null $contextProvider
     *        Domain context of each delivery; null sends a null context (1.8.0 behavior)
     * @param string|null $tenant Only this tenant's deadlines (2.7.0); null = every instance
     * @return list<DeadlineDelivery>
     * @throws StateMachineException When a tenant is given and the store is not tenant-scoped
     */
    public function deliverDue(
        DateTimeImmutable $now,
        ?string $machineKey = null,
        int $limit = 100,
        ?string $actor = null,
        ?Closure $contextProvider = null,
        ?string $tenant = null
    ): array {
        $deliveries = [];
        foreach ($this->findDue($now, $machineKey, $limit, $tenant) as $deadline) {
            $instance = $tenant === null
                ? $this->persistence->instances->load($deadline->instanceId)
                : $this->tenantScopedStore()->loadForTenant($deadline->instanceId, $tenant);
            if ($instance === null) {
                $deliveries[] = new DeadlineDelivery($deadline, null, 'The instance no longer exists.');
                continue;
            }
            $definition = $this->definitionFor($instance->getMachineKey(), $instance->getMachineVersion());
            $state = $definition->getRegion($deadline->region)?->getState($instance->snapshot->stateOf($deadline->region));
            $dueAt = $instance->snapshot->dueAt($deadline->region);
            if ($state?->deadline === null || $dueAt === null) {
                $deliveries[] = new DeadlineDelivery($deadline, null, 'The region left the state with the deadline since the search.');
                continue;
            }
            $event = Event::internal(
                $state->deadline->event,
                $now,
                ['region' => $deadline->region, 'due_at' => $dueAt->format(MachineSnapshot::DATE_FORMAT)],
                self::DEADLINE_KEY_PREFIX . $deadline->region . ':' . PersistenceSupport::toText($dueAt)
            );
            try {
                $context = $contextProvider !== null ? $contextProvider($deadline, $instance) : null;
                $deliveries[] = new DeadlineDelivery($deadline, $this->send($deadline->instanceId, $event, $context, $actor, null, $tenant));
            } catch (ConcurrencyConflictException | SyncEffectFailedException $e) {
                $deliveries[] = new DeadlineDelivery($deadline, null, $e->getMessage());
            }
        }

        return $deliveries;
    }

    /**
     * The definition of the (key, version) pair, validated once per runner with the core's guard
     * registry and, since 2.7.0, its calendar registry (D17: refused definitions never run).
     *
     * @throws UnknownMachineDefinitionException When the pair is not registered
     * @throws InvalidMachineDefinitionException When the validator refuses it
     */
    public function definitionFor(string $machineKey, int $version): MachineDefinition
    {
        $definition = $this->definitions->get($machineKey, $version);
        $cacheKey = $machineKey . '#' . $version;
        if (!isset($this->validated[$cacheKey])) {
            MachineDefinitionValidator::validate($definition, $this->machine->getGuardRegistry(), null, $this->machine->getCalendarRegistry());
            $this->validated[$cacheKey] = true;
        }

        return $definition;
    }

    /**
     * Writes the effects of a step to the outbox (synchronous ones already delivered) and runs the
     * synchronous ones, in effect order, inside the transaction.
     *
     * @param list<Effect> $effects
     * @param EventSnapshot $event The event of the step, kept with each entry (2.10.0)
     * @throws SyncEffectFailedException
     */
    private function writeEffects(MachineInstance $instance, array $effects, DateTimeImmutable $occurredAt, EventSnapshot $event): void
    {
        if ($effects === []) {
            return;
        }
        $entries = [];
        foreach ($effects as $order => $effect) {
            $sync = $this->effects->isSync($effect->action);
            $entries[] = new OutboxEntry(
                $instance->instanceId,
                $instance->version,
                $order,
                $instance->getMachineKey(),
                $instance->getMachineVersion(),
                $effect,
                $sync ? OutboxStatus::DELIVERED : OutboxStatus::PENDING,
                $sync ? 1 : 0,
                null,
                $occurredAt,
                $sync ? $occurredAt : null,
                $sync ? $occurredAt : null,
                $event
            );
        }
        $this->persistence->outbox->enqueue($entries);

        foreach ($entries as $entry) {
            if (!$this->effects->isSync($entry->effect->action)) {
                continue;
            }
            try {
                $this->effects->invoke(EffectContext::of($entry));
            } catch (Throwable $e) {
                throw new SyncEffectFailedException($instance->instanceId, $entry->effect, $e);
            }
        }
    }

    /**
     * One chunk of sendMany(): in one transaction; replayed through send() when an event fails after its
     * first write and the runner owns the transaction (see sendMany()).
     *
     * @param array<int, BatchEvent> $chunk Position in the batch => event
     * @return list<BatchEventOutcome>
     */
    private function sendChunk(array $chunk, ?string $actor, ?string $tenant): array
    {
        $transactions = $this->persistence->transactions;
        $owned = method_exists($transactions, 'inTransaction') && !$transactions->inTransaction();
        try {
            return $transactions->transactional(fn(): array => $this->sendChunkInTransaction($chunk, $actor, $tenant));
        } catch (Throwable $e) {
            if (!$owned) {
                throw $e;
            }
        }

        $outcomes = [];
        foreach ($chunk as $index => $item) {
            try {
                $result = $this->send($item->instanceId, $item->event, $item->context, $item->actor ?? $actor, $item->expectedVersion, $tenant);
                $outcomes[] = new BatchEventOutcome($index, $item, $result);
            } catch (MachineInstanceNotFoundException | ConcurrencyConflictException | SyncEffectFailedException $e) {
                $outcomes[] = new BatchEventOutcome($index, $item, null, $e);
            }
        }

        return $outcomes;
    }

    /**
     * The steps of send() for every event of a chunk, inside the chunk's transaction. A failure before
     * the first write of an event becomes its outcome; one after it propagates (see sendChunk()).
     *
     * @param array<int, BatchEvent> $chunk
     * @return list<BatchEventOutcome>
     */
    private function sendChunkInTransaction(array $chunk, ?string $actor, ?string $tenant): array
    {
        $keys = [];
        foreach ($chunk as $item) {
            if ($item->event->idempotencyKey !== null) {
                $keys[$item->instanceId][] = $item->event->idempotencyKey;
            }
        }
        $store = $this->persistence->idempotency;
        $known = $keys !== [] && $store instanceof IBatchIdempotencyStore ? $store->findMany($keys) : null;
        /** @var array<string, array<string, true>> $recorded keys recorded by this chunk */
        $recorded = [];

        $outcomes = [];
        foreach ($chunk as $index => $item) {
            $event = $item->event;
            $instanceId = $item->instanceId;
            $who = $item->actor ?? $actor;
            try {
                $instance = $this->mustLoad($instanceId, $tenant);
            } catch (MachineInstanceNotFoundException $e) {
                $outcomes[] = new BatchEventOutcome($index, $item, null, $e);
                continue;
            }
            $key = $event->idempotencyKey;
            if ($key !== null) {
                $original = ($known === null || ($recorded[$instanceId][$key] ?? false))
                    ? $store->find($instanceId, $key)
                    : ($known[$instanceId][$key] ?? null);
                if ($original !== null) {
                    $outcomes[] = new BatchEventOutcome($index, $item, $original);
                    continue;
                }
            }
            if ($item->expectedVersion !== null && $item->expectedVersion !== $instance->version) {
                $outcomes[] = new BatchEventOutcome($index, $item, null, new ConcurrencyConflictException($instanceId, $item->expectedVersion, $instance->version));
                continue;
            }

            $definition = $this->definitionFor($instance->getMachineKey(), $instance->getMachineVersion());
            $result = $this->machine->transition($definition, $instance->snapshot, $event, $item->context);
            if (!$result->isAccepted()) {
                $this->persistence->rejections?->record(new RejectionEntry(
                    UlidGenerator::ulid(),
                    $instanceId,
                    $instance->version,
                    $definition->key,
                    $definition->version,
                    $result->status,
                    $event->type,
                    $event->payload,
                    $who,
                    $key,
                    $event->origin,
                    $result->toArray()['reason'],
                    $event->occurredAt
                ));
                $outcomes[] = new BatchEventOutcome($index, $item, $result);
                continue;
            }

            $next = $instance->next($result->snapshot, $event->occurredAt);
            try {
                $this->persistence->instances->compareAndSet($next, $instance->version);
            } catch (ConcurrencyConflictException $e) {
                // A lost compare-and-set writes nothing (IMachineInstanceStore::compareAndSet()).
                $outcomes[] = new BatchEventOutcome($index, $item, null, $e);
                continue;
            }

            $rows = [];
            foreach ($result->getTransitions() as $order => $taken) {
                $rows[] = new HistoryEntry(
                    $instanceId, $next->version, $order, $definition->key, $definition->version, $taken->region,
                    $taken->from, $taken->to, $taken->eventType ?? $event->type, $taken->transitionId,
                    $event->payload, $who, $key, $event->origin, $event->occurredAt
                );
            }
            $this->persistence->history->append($rows);
            $this->writeEffects($next, $result->getEffects(), $event->occurredAt, EventSnapshot::of($event, $who));
            if ($key !== null) {
                $this->persistence->idempotency->record($instanceId, $key, $next->version, $result, $event->occurredAt);
                $recorded[$instanceId][$key] = true;
            }
            $outcomes[] = new BatchEventOutcome($index, $item, $result);
        }

        return $outcomes;
    }

    /**
     * @throws MachineMigrationException When the snapshot does not conform to the target definition
     */
    private function assertConforms(MachineDefinition $target, MachineSnapshot $snapshot): void
    {
        $problems = [];
        if ($snapshot->machineKey !== $target->key || $snapshot->machineVersion !== $target->version) {
            $problems[] = sprintf(
                'the migrated snapshot belongs to "%s" v%d, not to "%s" v%d',
                $snapshot->machineKey,
                $snapshot->machineVersion,
                $target->key,
                $target->version
            );
        }
        $regions = $snapshot->regions();
        $expected = $target->regionNames();
        $sortedRegions = $regions;
        $sortedExpected = $expected;
        sort($sortedRegions);
        sort($sortedExpected);
        if ($sortedRegions !== $sortedExpected) {
            $problems[] = sprintf('its regions [%s] differ from the target regions [%s]', implode(', ', $regions), implode(', ', $expected));
        } else {
            foreach ($target->regions as $region) {
                $state = $snapshot->stateOf($region->name);
                if (!$region->hasState($state)) {
                    $problems[] = sprintf('region "%s" is in state "%s", which the target does not declare', $region->name, $state);
                } elseif ($snapshot->isPaused($region->name) && $region->getState($state)?->deadline?->pausedWhile === null) {
                    // A paused balance in a state that can never resume would leave the region without a deadline forever.
                    $problems[] = sprintf('region "%s" is paused in state "%s", whose deadline declares no pausedWhile (the migration must resume the deadline or drop the balance)', $region->name, $state);
                }
            }
        }
        if ($problems !== []) {
            throw new MachineMigrationException(sprintf(
                'Migration of machine "%s" to v%d refused: %s.',
                $target->key,
                $target->version,
                implode('; ', $problems)
            ));
        }
    }

    /**
     * Loads the instance, confined to the tenant when one is given: another tenant's instance is the
     * same "does not exist" as an unknown id.
     *
     * @throws MachineInstanceNotFoundException
     * @throws StateMachineException When a tenant is given and the store is not tenant-scoped
     */
    private function mustLoad(string $instanceId, ?string $tenant = null): MachineInstance
    {
        $instance = $tenant === null
            ? $this->persistence->instances->load($instanceId)
            : $this->tenantScopedStore()->loadForTenant($instanceId, $tenant);

        return $instance ?? throw new MachineInstanceNotFoundException(sprintf('Machine instance "%s" does not exist.', $instanceId));
    }

    /**
     * @throws StateMachineException When the instance store is not tenant-scoped
     */
    private function tenantScopedStore(): ITenantScopedInstanceStore
    {
        $store = $this->persistence->instances;
        if (!$store instanceof ITenantScopedInstanceStore) {
            throw new StateMachineException(sprintf(
                'A tenant was given, but the instance store %s does not keep tenants (ITenantScopedInstanceStore).',
                get_debug_type($store)
            ));
        }

        return $store;
    }

    private static function migrationKey(string $machineKey, int $fromVersion, int $toVersion): string
    {
        return $machineKey . '#' . $fromVersion . '#' . $toVersion;
    }
}
