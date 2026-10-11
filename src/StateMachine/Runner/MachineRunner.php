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
use Antevemus\ASpecification\StateMachine\Exceptions\SyncEffectFailedException;
use Antevemus\ASpecification\StateMachine\Exceptions\UnknownMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\MachineDefinition;
use Antevemus\ASpecification\StateMachine\MachineDefinitionValidator;
use Antevemus\ASpecification\StateMachine\MachineSnapshot;
use Antevemus\ASpecification\StateMachine\Persistence\DueDeadline;
use Antevemus\ASpecification\StateMachine\Persistence\HistoryEntry;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineDefinitionRepository;
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
 * @version    1.9.0
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
     * @return TransitionResult Accepted start result (event null); rejected, with nothing written,
     *                          when an initial deadline cannot be computed (SM_DEADLINE_SOURCE_INVALID)
     * @throws MachineInstanceAlreadyExistsException When the id is taken
     * @throws UnknownMachineDefinitionException When the definition is not registered
     * @throws InvalidMachineDefinitionException When the definition is refused by the validator
     * @throws SyncEffectFailedException When a synchronous entry effect throws (nothing written)
     */
    public function start(
        string $instanceId,
        string $machineKey,
        ?int $version,
        object|array|null $context,
        DateTimeImmutable $occurredAt,
        ?string $actor = null
    ): TransitionResult {
        PersistenceSupport::assertInstanceId($instanceId);
        $version ??= $this->definitions->latestVersion($machineKey)
            ?? throw new UnknownMachineDefinitionException(sprintf('Machine "%s" has no registered version.', $machineKey));
        $definition = $this->definitionFor($machineKey, $version);

        return $this->persistence->transactions->transactional(function () use ($instanceId, $definition, $context, $occurredAt, $actor): TransitionResult {
            if ($this->persistence->instances->load($instanceId) !== null) {
                throw new MachineInstanceAlreadyExistsException(sprintf('Machine instance "%s" already exists.', $instanceId));
            }
            $result = $this->machine->initialSnapshot($definition, $context, $occurredAt);
            if (!$result->isAccepted()) {
                return $result;
            }
            $instance = new MachineInstance($instanceId, $result->snapshot, 1, $occurredAt, $occurredAt);
            $this->persistence->instances->insert($instance);

            $rows = [];
            foreach ($result->getTransitions() as $order => $taken) {
                $rows[] = new HistoryEntry(
                    $instanceId, 1, $order, $definition->key, $definition->version, $taken->region,
                    null, $taken->to, self::START_EVENT, null, [], $actor, null, EventOrigin::INTERNAL, $occurredAt
                );
            }
            $this->persistence->history->append($rows);
            $this->writeEffects($instance, $result->getEffects(), $occurredAt);

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
     * @return TransitionResult The result (the original one for a repeated idempotency key)
     * @throws MachineInstanceNotFoundException When the id is unknown
     * @throws ConcurrencyConflictException When the version moved (nothing written)
     * @throws SyncEffectFailedException When a synchronous effect throws (nothing written)
     * @throws InvalidArgumentException When the event type is reserved by the runner
     */
    public function send(
        string $instanceId,
        Event $event,
        object|array|null $context = null,
        ?string $actor = null,
        ?int $expectedVersion = null
    ): TransitionResult {
        if ($event->type === self::START_EVENT || $event->type === self::MIGRATE_EVENT) {
            throw new InvalidArgumentException(sprintf('Event type "%s" is reserved by the machine runner.', $event->type));
        }

        return $this->persistence->transactions->transactional(function () use ($instanceId, $event, $context, $actor, $expectedVersion): TransitionResult {
            $instance = $this->mustLoad($instanceId);

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
            $this->writeEffects($next, $result->getEffects(), $event->occurredAt);

            if ($event->idempotencyKey !== null) {
                $this->persistence->idempotency->record($instanceId, $event->idempotencyKey, $next->version, $result, $event->occurredAt);
            }

            return $result;
        });
    }

    /**
     * Loads a persisted instance.
     *
     * @throws MachineInstanceNotFoundException When the id is unknown
     */
    public function load(string $instanceId): MachineInstance
    {
        return $this->mustLoad($instanceId);
    }

    /**
     * Tells what the event would do to the persisted instance, without writing anything (D7).
     *
     * @param string $instanceId Instance id
     * @param Event $event Event to test
     * @param object|array<mixed>|null $context Domain context read by the guards
     * @return TransitionResult
     * @throws MachineInstanceNotFoundException When the id is unknown
     */
    public function can(string $instanceId, Event $event, object|array|null $context = null): TransitionResult
    {
        $instance = $this->mustLoad($instanceId);

        return $this->machine->can($this->definitionFor($instance->getMachineKey(), $instance->getMachineVersion()), $instance->snapshot, $event, $context);
    }

    /**
     * Events answerable by the persisted instance, with the result of each (D7).
     *
     * @param string $instanceId Instance id
     * @param object|array<mixed>|null $context Domain context read by the guards
     * @param DateTimeImmutable $occurredAt Instant used for the test events
     * @return array<string, TransitionResult>
     * @throws MachineInstanceNotFoundException When the id is unknown
     */
    public function nextEvents(string $instanceId, object|array|null $context, DateTimeImmutable $occurredAt): array
    {
        $instance = $this->mustLoad($instanceId);

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
     * @return MachineInstance The migrated instance
     * @throws MachineMigrationException When no migration is registered or its result does not conform
     * @throws ConcurrencyConflictException When the version moved
     */
    public function migrate(
        string $instanceId,
        int $toVersion,
        DateTimeImmutable $occurredAt,
        ?string $actor = null,
        ?int $expectedVersion = null
    ): MachineInstance {
        return $this->persistence->transactions->transactional(function () use ($instanceId, $toVersion, $occurredAt, $actor, $expectedVersion): MachineInstance {
            $instance = $this->mustLoad($instanceId);
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

            $migrated = $migration->migrate($instance->snapshot);
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
     * Due deadlines (D18), as found by the instance store.
     *
     * @param DateTimeImmutable $now Reference instant
     * @param string|null $machineKey Only this machine, when given
     * @param int $limit Maximum number of deadlines
     * @return list<DueDeadline>
     */
    public function findDue(DateTimeImmutable $now, ?string $machineKey = null, int $limit = 100): array
    {
        return $this->persistence->instances->findDue($now, $machineKey, $limit);
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
     * @return list<DeadlineDelivery>
     */
    public function deliverDue(
        DateTimeImmutable $now,
        ?string $machineKey = null,
        int $limit = 100,
        ?string $actor = null,
        ?Closure $contextProvider = null
    ): array {
        $deliveries = [];
        foreach ($this->findDue($now, $machineKey, $limit) as $deadline) {
            $instance = $this->persistence->instances->load($deadline->instanceId);
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
                $deliveries[] = new DeadlineDelivery($deadline, $this->send($deadline->instanceId, $event, $context, $actor));
            } catch (ConcurrencyConflictException | SyncEffectFailedException $e) {
                $deliveries[] = new DeadlineDelivery($deadline, null, $e->getMessage());
            }
        }

        return $deliveries;
    }

    /**
     * The definition of the (key, version) pair, validated once per runner with the core's guard
     * registry (D17: refused definitions never run).
     *
     * @throws UnknownMachineDefinitionException When the pair is not registered
     * @throws InvalidMachineDefinitionException When the validator refuses it
     */
    public function definitionFor(string $machineKey, int $version): MachineDefinition
    {
        $definition = $this->definitions->get($machineKey, $version);
        $cacheKey = $machineKey . '#' . $version;
        if (!isset($this->validated[$cacheKey])) {
            MachineDefinitionValidator::validate($definition, $this->machine->getGuardRegistry());
            $this->validated[$cacheKey] = true;
        }

        return $definition;
    }

    /**
     * Writes the effects of a step to the outbox (synchronous ones already delivered) and runs the
     * synchronous ones, in effect order, inside the transaction.
     *
     * @param list<Effect> $effects
     * @throws SyncEffectFailedException
     */
    private function writeEffects(MachineInstance $instance, array $effects, DateTimeImmutable $occurredAt): void
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
                $sync ? $occurredAt : null
            );
        }
        $this->persistence->outbox->enqueue($entries);

        foreach ($entries as $entry) {
            if (!$this->effects->isSync($entry->effect->action)) {
                continue;
            }
            $handler = $this->effects->get($entry->effect->action);
            \assert($handler !== null);
            try {
                $handler($entry->effect, $entry->effectKey(), $instance->instanceId);
            } catch (Throwable $e) {
                throw new SyncEffectFailedException($instance->instanceId, $entry->effect, $e);
            }
        }
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
     * @throws MachineInstanceNotFoundException
     */
    private function mustLoad(string $instanceId): MachineInstance
    {
        return $this->persistence->instances->load($instanceId)
            ?? throw new MachineInstanceNotFoundException(sprintf('Machine instance "%s" does not exist.', $instanceId));
    }

    private static function migrationKey(string $machineKey, int $fromVersion, int $toVersion): string
    {
        return $machineKey . '#' . $fromVersion . '#' . $toVersion;
    }
}
