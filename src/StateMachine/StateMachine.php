<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\Results\FailureSeverity;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\StateMachine\Exceptions\InvalidSnapshotException;
use Antevemus\ASpecification\StateMachine\Exceptions\UnknownGuardException;
use DateTimeImmutable;

/**
 * StateMachine - Pure Interpreter of a Spec-Driven State Machine
 *
 * Computes what an event does to a snapshot without doing it (forward 023, D5): no I/O, no
 * clock, no randomness, no mutation of its inputs (RN-02). The same inputs always produce the
 * same TransitionResult. Persisting the snapshot, the history and the effects is the job of the
 * MachineRunner, the single write port.
 *
 * Semantics (RN-03 to RN-06, D9 to D11, D18, D19):
 * - Event first: per region, in declaration order, the candidates are the current state's
 *   transitions for the event type (in order), then the region-level ones; the first candidate
 *   whose guard passes wins. A final state has no candidates (inert).
 * - Guards read the snapshot BEFORE the event, for every region (single-step semantics).
 * - All or nothing: when any region rejects (had candidates, none passed) the whole event is
 *   rejected and nothing changes; unhandled when no region had a candidate; accepted otherwise.
 * - A guard that throws becomes an ERROR failure marked `evaluation_error`; it stops the
 *   selection in its region (a lower-priority transition is never taken because a higher one
 *   could not be evaluated).
 * - Implicit guards: a transition declared `internal`, or answering the deadline event of the
 *   current state, only accepts internal events (D19); a deadline event is only accepted when the
 *   region's dueAt is set and `dueAt <= occurredAt` (D18).
 * - Effects in SCXML order: every exit, then every transition action, then every entry; within a
 *   phase, regions then actions in declaration order. A transition to the same state reenters
 *   (exit + entry, deadline recomputed); a targetless transition runs only its actions.
 * - The snapshot is `done` when every region that declares a final state is in one; a region
 *   without final states (an orthogonal flag such as "pending none/open") is auxiliary and does
 *   not hold the machine open. A machine with no final state anywhere is never done. Once done,
 *   the machine is inert: every event is unhandled (CODE_MACHINE_DONE) and nextEvents() is empty.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class StateMachine
{
    private readonly GuardRegistry $guards;

    /**
     * @param GuardRegistry|null $guards Guard factories (default: built-ins only)
     */
    public function __construct(?GuardRegistry $guards = null)
    {
        $this->guards = $guards ?? new GuardRegistry();
    }

    /** The guard registry used to compile guards. */
    public function getGuardRegistry(): GuardRegistry
    {
        return $this->guards;
    }

    /**
     * Computes the start of an instance: every region in its initial state, deadlines computed
     * from $occurredAt, and the entry actions of the initial states as effects (one TakenTransition
     * per region with `from` and `transitionId` null).
     *
     * The definition is expected to be validated (MachineDefinitionValidator) beforehand.
     *
     * @param MachineDefinition $definition Machine definition
     * @param object|array<mixed>|null $context Domain context (reserved: no guard runs at start)
     * @param DateTimeImmutable $occurredAt Instant of the start
     * @return TransitionResult Accepted result whose snapshot is the initial snapshot
     * @throws InvalidSnapshotException When a region has no valid initial state
     */
    public function initialSnapshot(
        MachineDefinition $definition,
        object|array|null $context,
        DateTimeImmutable $occurredAt
    ): TransitionResult {
        $states = [];
        $dueAt = [];
        $taken = [];
        $effects = [];
        $allFinal = true;

        foreach ($definition->regions as $region) {
            $initial = $region->initial !== null ? $region->getState($region->initial) : null;
            if ($initial === null) {
                throw new InvalidSnapshotException(sprintf(
                    'Region "%s" of machine "%s" has no valid initial state; validate the definition first.',
                    $region->name,
                    $definition->key
                ));
            }
            $states[$region->name] = $initial->name;
            $dueAt[$region->name] = $initial->deadline?->dueFrom($occurredAt);
            $taken[] = new TakenTransition($region->name, null, $initial->name, null, null);
            foreach ($initial->entry as $action) {
                $effects[] = new Effect($action->name, $action->params, $region->name, EffectPhase::ENTRY, $initial->name, null);
            }
            $allFinal = $allFinal && $initial->final;
        }

        $snapshot = new MachineSnapshot($definition->key, $definition->version, $states, $dueAt, $allFinal);

        return new TransitionResult(TransitionStatus::ACCEPTED, null, $snapshot, $taken, $effects);
    }

    /**
     * Computes what the event does to the snapshot. Pure: nothing is executed nor persisted.
     *
     * @param MachineDefinition $definition Machine definition (the version the snapshot is pinned to)
     * @param MachineSnapshot $snapshot Current snapshot
     * @param Event $event Event to process
     * @param object|array<mixed>|null $context Domain context read by the guards
     * @return TransitionResult
     * @throws InvalidSnapshotException When the snapshot does not belong to the definition
     * @throws UnknownGuardException When a guard name has no factory (definition not validated)
     */
    public function transition(
        MachineDefinition $definition,
        MachineSnapshot $snapshot,
        Event $event,
        object|array|null $context = null
    ): TransitionResult {
        $this->assertSnapshotMatches($definition, $snapshot);
        if ($snapshot->isDone()) {
            return new TransitionResult(
                TransitionStatus::UNHANDLED,
                $event,
                $snapshot,
                reason: SpecificationResult::failure(
                    message: sprintf("Event '%s' is not handled: the machine is done.", $event->type),
                    code: TransitionResult::CODE_MACHINE_DONE,
                    ruleName: 'StateMachine',
                    metadata: ['event' => $event->type],
                    severity: FailureSeverity::INFO
                )
            );
        }
        $candidate = new TransitionCandidate($context, $event, $snapshot);

        /** @var array<string, array{region: RegionDefinition, from: StateDefinition, transition: TransitionDefinition}> $selected */
        $selected = [];
        /** @var array<string, SpecificationResult> $rejections */
        $rejections = [];

        foreach ($definition->regions as $region) {
            $state = $region->getState($snapshot->stateOf($region->name));
            \assert($state !== null);
            $candidates = $region->candidatesFor($state, $event->type);
            if ($candidates === []) {
                continue;
            }

            $failures = [];
            $chosen = null;
            foreach ($candidates as $transition) {
                $verdict = $this->checkCandidate($region, $state, $transition, $candidate);
                if ($verdict->isSatisfied) {
                    $chosen = $transition;
                    break;
                }
                $failures[] = $verdict;
                if ($verdict->isError) {
                    break;
                }
            }

            if ($chosen !== null) {
                $selected[$region->name] = ['region' => $region, 'from' => $state, 'transition' => $chosen];
            } else {
                $rejections[$region->name] = SpecificationResult::combine(...$failures);
            }
        }

        if ($rejections !== []) {
            return new TransitionResult(
                TransitionStatus::REJECTED,
                $event,
                $snapshot,
                reason: SpecificationResult::combine(...array_values($rejections)),
                rejections: $rejections
            );
        }

        if ($selected === []) {
            return new TransitionResult(
                TransitionStatus::UNHANDLED,
                $event,
                $snapshot,
                reason: SpecificationResult::failure(
                    message: sprintf(
                        "Event '%s' is not handled by the current state(s): %s.",
                        $event->type,
                        $this->describeStates($snapshot)
                    ),
                    code: TransitionResult::CODE_UNHANDLED,
                    ruleName: 'StateMachine',
                    metadata: ['event' => $event->type],
                    severity: FailureSeverity::INFO
                )
            );
        }

        return $this->apply($definition, $snapshot, $event, $selected);
    }

    /**
     * Tells whether the event would be accepted, and why not. It is transition() itself: pure,
     * with no side effect, returning the same TransitionResult (forward 023, D7).
     *
     * @param MachineDefinition $definition Machine definition
     * @param MachineSnapshot $snapshot Current snapshot
     * @param Event $event Event to test
     * @param object|array<mixed>|null $context Domain context read by the guards
     * @return TransitionResult
     */
    public function can(
        MachineDefinition $definition,
        MachineSnapshot $snapshot,
        Event $event,
        object|array|null $context = null
    ): TransitionResult {
        return $this->transition($definition, $snapshot, $event, $context);
    }

    /**
     * Lists the event types answerable from the current states with the result of each (forward
     * 023, D7), so an action panel can show a disabled button WITH its reason. Each event is tested
     * as an external event with an empty payload at $occurredAt (guards requiring payload fields
     * therefore report what is missing).
     *
     * Order: regions in declaration order; per region, the current state's transitions then the
     * region-level ones; each event type once, at its first occurrence. Event types whose every
     * candidate is internal-only (declared internal, or the current state's deadline event) are
     * omitted unless $includeInternal is true.
     *
     * @param MachineDefinition $definition Machine definition
     * @param MachineSnapshot $snapshot Current snapshot
     * @param object|array<mixed>|null $context Domain context read by the guards
     * @param DateTimeImmutable $occurredAt Instant used for the test events
     * @param bool $includeInternal Whether to list internal-only event types
     * @return array<string, TransitionResult> Result per event type
     */
    public function nextEvents(
        MachineDefinition $definition,
        MachineSnapshot $snapshot,
        object|array|null $context,
        DateTimeImmutable $occurredAt,
        bool $includeInternal = false
    ): array {
        $this->assertSnapshotMatches($definition, $snapshot);
        if ($snapshot->isDone()) {
            return [];
        }

        /** @var array<string, bool> $types event type => has an externally reachable candidate */
        $types = [];
        foreach ($definition->regions as $region) {
            $state = $region->getState($snapshot->stateOf($region->name));
            \assert($state !== null);
            if ($state->final) {
                continue;
            }
            foreach ([...$state->transitions, ...$region->transitions] as $transition) {
                $external = !$this->isInternalOnly($state, $transition);
                $types[$transition->event] = ($types[$transition->event] ?? false) || $external;
            }
        }

        $results = [];
        foreach ($types as $type => $hasExternal) {
            if (!$hasExternal && !$includeInternal) {
                continue;
            }
            $results[$type] = $this->transition(
                $definition,
                $snapshot,
                Event::external((string) $type, $occurredAt),
                $context
            );
        }

        return $results;
    }

    /**
     * The done rule (forward 023, D10): every region that declares at least one final state is in
     * a final state, and at least one such region exists. Regions without final states are
     * auxiliary (orthogonal flags) and never hold the machine open.
     */
    public static function isDone(MachineDefinition $definition, MachineSnapshot $snapshot): bool
    {
        $governing = 0;
        foreach ($definition->regions as $region) {
            $hasFinal = false;
            foreach ($region->states as $state) {
                if ($state->final) {
                    $hasFinal = true;
                    break;
                }
            }
            if (!$hasFinal) {
                continue;
            }
            $governing++;
            if (!($region->getState($snapshot->stateOf($region->name))?->final ?? false)) {
                return false;
            }
        }

        return $governing > 0;
    }

    /**
     * Checks one candidate transition: implicit guards (internal origin, deadline due) then the
     * declared guard. Failures are stamped with region, transition id and event.
     */
    private function checkCandidate(
        RegionDefinition $region,
        StateDefinition $state,
        TransitionDefinition $transition,
        TransitionCandidate $candidate
    ): SpecificationResult {
        $event = $candidate->event;
        $isDeadlineEvent = $state->deadline !== null && $state->deadline->event === $event->type;

        if ($this->isInternalOnly($state, $transition) && !$event->isInternal()) {
            return $this->stamp(SpecificationResult::failure(
                message: sprintf(
                    "Event '%s' is internal to the machine (transition '%s') and cannot be sent from outside.",
                    $event->type,
                    $transition->id
                ),
                code: TransitionResult::CODE_INTERNAL_EVENT,
                ruleName: 'StateMachine'
            ), $region, $transition, $event);
        }

        if ($isDeadlineEvent) {
            $due = $candidate->snapshot->dueAt($region->name);
            if ($due === null || $due > $event->occurredAt) {
                return $this->stamp(SpecificationResult::failure(
                    message: sprintf(
                        "The deadline of state '%s' in region '%s' is not due yet (due at %s).",
                        $state->name,
                        $region->name,
                        $due?->format(MachineSnapshot::DATE_FORMAT) ?? 'never'
                    ),
                    code: TransitionResult::CODE_DEADLINE_NOT_DUE,
                    ruleName: 'StateMachine'
                ), $region, $transition, $event);
            }
        }

        if ($transition->guard === null) {
            return SpecificationResult::satisfied();
        }

        try {
            $specification = $this->guards->compile($transition->guard);
            $result = $specification->evaluate($candidate);
        } catch (UnknownGuardException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $result = SpecificationResult::error($e, 'Guard:' . $transition->id, code: $transition->guard->getCode());
        }

        return $result->isSatisfied ? $result : $this->stamp($result, $region, $transition, $event);
    }

    /**
     * True when the transition only accepts internal events from this state: declared internal,
     * or answering the state's deadline event.
     */
    private function isInternalOnly(StateDefinition $state, TransitionDefinition $transition): bool
    {
        return $transition->internal
            || ($state->deadline !== null && $state->deadline->event === $transition->event);
    }

    /**
     * Adds location metadata to every failure (message, code, rule and property intact) and fills
     * the severity: ERROR for evaluation errors, ERROR when the guard did not classify it.
     */
    private function stamp(
        SpecificationResult $result,
        RegionDefinition $region,
        TransitionDefinition $transition,
        Event $event
    ): SpecificationResult {
        $failures = array_map(
            static function (SpecificationFailure $f) use ($result, $region, $transition, $event): SpecificationFailure {
                $severity = ($result->isError || isset($f->metadata['evaluation_error']))
                    ? FailureSeverity::ERROR
                    : ($f->severity ?? FailureSeverity::ERROR);

                return new SpecificationFailure(
                    message: $f->message,
                    code: $f->code,
                    ruleName: $f->ruleName,
                    property: $f->property,
                    metadata: $f->metadata + [
                        'region' => $region->name,
                        'transition_id' => $transition->id,
                        'event' => $event->type,
                    ],
                    severity: $severity
                );
            },
            $result->failures
        );

        return new SpecificationResult($result->isSatisfied, $failures, $result->isError, $result->exception);
    }

    /**
     * Applies the selected transitions: new snapshot, taken transitions and effects.
     *
     * @param array<string, array{region: RegionDefinition, from: StateDefinition, transition: TransitionDefinition}> $selected
     */
    private function apply(
        MachineDefinition $definition,
        MachineSnapshot $snapshot,
        Event $event,
        array $selected
    ): TransitionResult {
        $exits = [];
        $actions = [];
        $entries = [];
        $taken = [];
        $next = $snapshot;

        foreach ($definition->regions as $region) {
            if (!isset($selected[$region->name])) {
                continue;
            }
            $from = $selected[$region->name]['from'];
            $transition = $selected[$region->name]['transition'];

            if ($transition->target !== null) {
                foreach ($from->exit as $action) {
                    $exits[] = new Effect($action->name, $action->params, $region->name, EffectPhase::EXIT, $from->name, $transition->id);
                }
            }
            foreach ($transition->actions as $action) {
                $actions[] = new Effect($action->name, $action->params, $region->name, EffectPhase::TRANSITION, $from->name, $transition->id);
            }
            if ($transition->target !== null) {
                $target = $region->getState($transition->target);
                if ($target === null) {
                    throw new InvalidSnapshotException(sprintf(
                        'Transition "%s" targets unknown state "%s" in region "%s"; validate the definition first.',
                        $transition->id,
                        $transition->target,
                        $region->name
                    ));
                }
                foreach ($target->entry as $action) {
                    $entries[] = new Effect($action->name, $action->params, $region->name, EffectPhase::ENTRY, $target->name, $transition->id);
                }
                $next = $next->withState($region->name, $target->name, $target->deadline?->dueFrom($event->occurredAt));
                $taken[] = new TakenTransition($region->name, $from->name, $target->name, $transition->id, $event->type);
            } else {
                $taken[] = new TakenTransition($region->name, $from->name, $from->name, $transition->id, $event->type, true);
            }
        }

        $done = self::isDone($definition, $next);
        if ($done !== $next->done) {
            $next = $next->withDone($done);
        }

        return new TransitionResult(
            TransitionStatus::ACCEPTED,
            $event,
            $next,
            $taken,
            [...$exits, ...$actions, ...$entries]
        );
    }

    /**
     * @throws InvalidSnapshotException
     */
    private function assertSnapshotMatches(MachineDefinition $definition, MachineSnapshot $snapshot): void
    {
        if ($snapshot->machineKey !== $definition->key || $snapshot->machineVersion !== $definition->version) {
            throw new InvalidSnapshotException(sprintf(
                'The snapshot belongs to machine "%s" v%d, not to "%s" v%d.',
                $snapshot->machineKey,
                $snapshot->machineVersion,
                $definition->key,
                $definition->version
            ));
        }
        $snapshotRegions = $snapshot->regions();
        $definitionRegions = $definition->regionNames();
        $sortedSnapshot = $snapshotRegions;
        $sortedDefinition = $definitionRegions;
        sort($sortedSnapshot);
        sort($sortedDefinition);
        if ($sortedSnapshot !== $sortedDefinition) {
            throw new InvalidSnapshotException(sprintf(
                'The snapshot regions [%s] do not match the definition regions [%s].',
                implode(', ', $snapshotRegions),
                implode(', ', $definitionRegions)
            ));
        }
        foreach ($definition->regions as $region) {
            $state = $snapshot->stateOf($region->name);
            if (!$region->hasState($state)) {
                throw new InvalidSnapshotException(sprintf(
                    'The snapshot has region "%s" in unknown state "%s".',
                    $region->name,
                    $state
                ));
            }
        }
    }

    private function describeStates(MachineSnapshot $snapshot): string
    {
        $parts = [];
        foreach ($snapshot->getStates() as $region => $state) {
            $parts[] = "{$region}={$state}";
        }

        return implode(', ', $parts);
    }
}
