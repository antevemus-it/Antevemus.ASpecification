<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\Results\FailureSeverity;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\StateMachine\Calendar\DeadlineCalendarRegistry;
use Antevemus\ASpecification\StateMachine\Calendar\IDeadlineCalendar;
use Antevemus\ASpecification\StateMachine\Exceptions\InvalidSnapshotException;
use Antevemus\ASpecification\StateMachine\Exceptions\StateMachineException;
use Antevemus\ASpecification\StateMachine\Exceptions\UnknownDeadlineCalendarException;
use Antevemus\ASpecification\StateMachine\Exceptions\UnknownGuardException;
use Closure;
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
 * - Deadline sources (forward 025, D7, D8): entering a state whose deadline reads its source from
 *   the context (`afterFrom`, `atFrom`) computes `dueAt` from the context given to this call; a
 *   missing or invalid source rejects the event (CODE_DEADLINE_SOURCE_INVALID, ERROR, with the
 *   path, never the value).
 * - Deadline pause (forward 025, D9, D10): after the transitions of an accepted event (and at the
 *   start), every region whose current state's deadline declares `pausedWhile` evaluates it against
 *   the NEW snapshot (context and event of the call). Starting to hold pauses: the remaining whole
 *   seconds (`dueAt - occurredAt`, floored, never negative) are kept and `dueAt` becomes null.
 *   Ceasing to hold resumes: `dueAt = occurredAt + remaining`. A state entered by this event starts
 *   a fresh deadline (the balance is dropped) and pauses at once if the condition holds. A pause
 *   condition that throws rejects the event (ERROR, `evaluation_error`).
 * - Business time (2.7.0): a deadline that names a calendar gets it from the DeadlineCalendarRegistry
 *   given to the constructor, with the context of the call, each time it needs it (entering the
 *   state, pausing, resuming). Its duration counts working seconds of that calendar; the balance of a
 *   pause is `workSecondsBetween(occurredAt, dueAt)` and resuming places it with `advance()`. A
 *   calendar factory that throws rejects the event (ERROR, `evaluation_error`); a duration the
 *   calendar cannot place within its limit of days rejects it with CODE_DEADLINE_SOURCE_INVALID.
 *   A deadline without calendar computes exactly as before.
 * - Effects in SCXML order: every exit, then every transition action, then every entry; within a
 *   phase, regions then actions in declaration order. A transition to the same state reenters
 *   (exit + entry, deadline recomputed); a targetless transition runs only its actions.
 * - The snapshot is `done` when every region that declares a final state is in one; a region
 *   without final states (an orthogonal flag such as "pending none/open") is auxiliary and does
 *   not hold the machine open. A machine with no final state anywhere is never done. Once done,
 *   the machine is inert: every event is unhandled (CODE_MACHINE_DONE) and nextEvents() is empty.
 * - Primary reason (2.10.0): a rejected result also names the failures to show
 *   (TransitionResult::getPrimaryReason()) and keeps those of every other refusing candidate as
 *   details. Within the first rejecting region, the candidate that came closest wins: one whose
 *   guard could not be evaluated; otherwise the candidates neither refused by a role guard
 *   (GuardRegistry::markRoleGuards()) nor internal-only; among them the one with the fewest
 *   failures; then priority order (the state's transitions before the region's, in declaration
 *   order: the most specific first).
 * - Classification (2.10.0): nextEvents() classifies each listed event (CandidateReason) and treats
 *   `payloadHas` as "requires": an event refused only because the test payload is empty is
 *   MISSING_PAYLOAD, with the fields to ask for; its status and reason stay as transition() gives them.
 * - Import (2.10.0): snapshotAt() builds the snapshot of an instance already in given states (a
 *   process brought from another system), with no entry action and no guard.
 *
 * @version    2.10.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class StateMachine
{
    /**
     * Type of the internal event the pause conditions see at the start (the same reserved type the
     * runner records for the start rows of the history).
     */
    public const START_EVENT = 'machine.start';

    /**
     * Type of the internal event the pause conditions see when a snapshot is imported (2.10.0; the same
     * reserved type the runner records for the history rows of startAt()).
     */
    public const IMPORT_EVENT = 'machine.import';

    private readonly GuardRegistry $guards;

    private readonly DeadlineCalendarRegistry $calendars;

    /**
     * @param GuardRegistry|null $guards Guard factories (default: built-ins only)
     * @param DeadlineCalendarRegistry|null $calendars Calendars of the deadlines that name one (2.7.0; default: none)
     */
    public function __construct(?GuardRegistry $guards = null, ?DeadlineCalendarRegistry $calendars = null)
    {
        $this->guards = $guards ?? new GuardRegistry();
        $this->calendars = $calendars ?? new DeadlineCalendarRegistry();
    }

    /** The guard registry used to compile guards. */
    public function getGuardRegistry(): GuardRegistry
    {
        return $this->guards;
    }

    /** The calendars of the deadlines (2.7.0); empty unless given to the constructor. */
    public function getCalendarRegistry(): DeadlineCalendarRegistry
    {
        return $this->calendars;
    }

    /**
     * Computes the start of an instance: every region in its initial state, deadlines computed
     * from $occurredAt (and from the context for `afterFrom`/`atFrom` sources), paused when their
     * `pausedWhile` holds on the initial snapshot, and the entry actions of the initial states as
     * effects (one TakenTransition per region with `from` and `transitionId` null).
     *
     * The definition is expected to be validated (MachineDefinitionValidator) beforehand.
     *
     * When a deadline source is missing or invalid (or a pause condition throws), the start is
     * REJECTED (event null, no transition, no effect; reason CODE_DEADLINE_SOURCE_INVALID for a
     * source, an ERROR marked `evaluation_error` for a pause condition that throws); its snapshot is
     * informative only (the failing regions without due instant) and must not be stored.
     *
     * @param MachineDefinition $definition Machine definition
     * @param object|array<mixed>|null $context Domain context: read by deadline sources and pause
     *                                          conditions (no transition guard runs at start)
     * @param DateTimeImmutable $occurredAt Instant of the start
     * @return TransitionResult Accepted result whose snapshot is the initial snapshot (or rejected, see above)
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
        /** @var array<string, SpecificationResult> $rejections */
        $rejections = [];

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
            $dueAt[$region->name] = null;
            if ($initial->deadline !== null) {
                $due = $this->dueOf($region, $initial, null, null, $occurredAt, $context);
                if ($due instanceof SpecificationResult) {
                    $rejections[$region->name] = $due;
                } else {
                    $dueAt[$region->name] = $due;
                }
            }
            $taken[] = new TakenTransition($region->name, null, $initial->name, null, null);
            foreach ($initial->entry as $action) {
                $effects[] = new Effect($action->name, $action->params, $region->name, EffectPhase::ENTRY, $initial->name, null);
            }
            $allFinal = $allFinal && $initial->final;
        }

        $snapshot = new MachineSnapshot($definition->key, $definition->version, $states, $dueAt, $allFinal);
        if ($rejections === []) {
            [$snapshot, $rejections] = $this->applyPauses(
                $definition,
                $snapshot,
                Event::internal(self::START_EVENT, $occurredAt),
                $context
            );
        }
        if ($rejections !== []) {
            return new TransitionResult(
                TransitionStatus::REJECTED,
                null,
                $snapshot,
                reason: SpecificationResult::combine(...array_values($rejections)),
                rejections: $rejections
            );
        }

        return new TransitionResult(TransitionStatus::ACCEPTED, null, $snapshot, $taken, $effects);
    }

    /**
     * Computes the snapshot of an instance imported in given states (2.10.0): each region in the state
     * given for it (a region left out starts in its initial state), with NO entry action and NO guard
     * (the states are a fact brought from elsewhere, not a transition). Deadlines are computed as at the
     * start, counting from $at (and from the context for `afterFrom`/`atFrom` sources), and paused when
     * their `pausedWhile` holds on the imported snapshot (seen with the internal event IMPORT_EVENT). One
     * TakenTransition per region (`from` and `transitionId` null), no effect. The snapshot is done when
     * the done rule says so, which needs $allowFinal.
     *
     * When a deadline source is missing or invalid (or a pause condition throws), the import is REJECTED
     * exactly as a start is (reason CODE_DEADLINE_SOURCE_INVALID or `evaluation_error`); its snapshot is
     * informative only.
     *
     * @param MachineDefinition $definition Machine definition (validated beforehand)
     * @param array<string, string> $statesByRegion Region name => state name
     * @param object|array<mixed>|null $context Domain context read by deadline sources and pause conditions
     * @param DateTimeImmutable $at Instant the deadlines count from
     * @param bool $allowFinal Whether a final state may be given (an instance imported already finished)
     * @return TransitionResult Accepted result whose snapshot is the imported snapshot (or rejected, see above)
     * @throws InvalidSnapshotException When a region is unknown, a state is not declared in its region, or a
     *                                  final state is given without $allowFinal
     */
    public function snapshotAt(
        MachineDefinition $definition,
        array $statesByRegion,
        object|array|null $context,
        DateTimeImmutable $at,
        bool $allowFinal = false
    ): TransitionResult {
        foreach ($statesByRegion as $name => $state) {
            if ($definition->getRegion((string) $name) === null) {
                throw new InvalidSnapshotException(sprintf(
                    'Machine "%s" v%d has no region "%s"; the regions are [%s].',
                    $definition->key,
                    $definition->version,
                    (string) $name,
                    implode(', ', $definition->regionNames())
                ));
            }
            if (!is_string($state)) {
                throw new InvalidSnapshotException(sprintf('The state given for region "%s" must be a state name (got %s).', (string) $name, get_debug_type($state)));
            }
        }

        $states = [];
        $dueAt = [];
        $taken = [];
        /** @var array<string, SpecificationResult> $rejections */
        $rejections = [];
        foreach ($definition->regions as $region) {
            $name = $statesByRegion[$region->name] ?? $region->initial;
            $state = $name !== null ? $region->getState($name) : null;
            if ($state === null) {
                throw new InvalidSnapshotException(sprintf(
                    'Region "%s" of machine "%s" v%d declares no state "%s"; its states are [%s].',
                    $region->name,
                    $definition->key,
                    $definition->version,
                    (string) $name,
                    implode(', ', $region->stateNames())
                ));
            }
            if ($state->final && !$allowFinal) {
                throw new InvalidSnapshotException(sprintf(
                    'State "%s" of region "%s" is final; importing an instance in a final state needs allowFinal.',
                    $state->name,
                    $region->name
                ));
            }
            $states[$region->name] = $state->name;
            $dueAt[$region->name] = null;
            if ($state->deadline !== null) {
                $due = $this->dueOf($region, $state, null, null, $at, $context);
                if ($due instanceof SpecificationResult) {
                    $rejections[$region->name] = $due;
                } else {
                    $dueAt[$region->name] = $due;
                }
            }
            $taken[] = new TakenTransition($region->name, null, $state->name, null, null);
        }

        $snapshot = new MachineSnapshot($definition->key, $definition->version, $states, $dueAt);
        $snapshot = $snapshot->withDone(self::isDone($definition, $snapshot));
        if ($rejections === []) {
            [$snapshot, $rejections] = $this->applyPauses($definition, $snapshot, Event::internal(self::IMPORT_EVENT, $at), $context);
        }
        if ($rejections !== []) {
            return new TransitionResult(
                TransitionStatus::REJECTED,
                null,
                $snapshot,
                reason: SpecificationResult::combine(...$rejections),
                rejections: $rejections
            );
        }

        return new TransitionResult(TransitionStatus::ACCEPTED, null, $snapshot, $taken);
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
        /** @var array<string, list<array{transition: TransitionDefinition, state: StateDefinition, verdict: SpecificationResult}>> $refused */
        $refused = [];

        foreach ($definition->regions as $region) {
            $state = $region->getState($snapshot->stateOf($region->name));
            \assert($state !== null);
            $candidates = $region->candidatesFor($state, $event->type);
            if ($candidates === []) {
                continue;
            }

            $failures = [];
            $tried = [];
            $chosen = null;
            foreach ($candidates as $transition) {
                $verdict = $this->checkCandidate($region, $state, $transition, $candidate);
                if ($verdict->isSatisfied) {
                    $chosen = $transition;
                    break;
                }
                $failures[] = $verdict;
                $tried[] = ['transition' => $transition, 'state' => $state, 'verdict' => $verdict];
                if ($verdict->isError) {
                    break;
                }
            }

            if ($chosen !== null) {
                $selected[$region->name] = ['region' => $region, 'from' => $state, 'transition' => $chosen];
            } else {
                $rejections[$region->name] = SpecificationResult::combine(...$failures);
                $refused[$region->name] = $tried;
            }
        }

        if ($rejections !== []) {
            [$primary, $details] = $this->primaryOf($refused, $candidate);

            return new TransitionResult(
                TransitionStatus::REJECTED,
                $event,
                $snapshot,
                reason: SpecificationResult::combine(...array_values($rejections)),
                rejections: $rejections,
                primaryReason: $primary,
                details: $details
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

        return $this->apply($definition, $snapshot, $event, $selected, $context);
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
     * Since 2.10.0 each result also carries its classification (TransitionResult::getCandidateReason(),
     * see CandidateReason) and, for MISSING_PAYLOAD, the payload fields to ask for
     * (getMissingPayload()): `payloadHas` is read as "requires" (a region whose candidate passes once
     * those fields are given is not counted as refusing), so the panel keeps the button and asks for the
     * data. The status and the reason of each result are those of transition(), unchanged.
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
            $event = Event::external((string) $type, $occurredAt);
            $results[$type] = $this->classify($definition, $snapshot, $event, $context, $this->transition($definition, $snapshot, $event, $context));
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
        $implicit = $this->implicitRefusal($region, $state, $transition, $candidate);
        if ($implicit !== null) {
            return $implicit;
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

        return $result->isSatisfied ? $result : $this->stamp($result, $region, $transition, $candidate->event);
    }

    /**
     * The failure of the implicit guards of a candidate (internal-only transition reached by an external
     * event, deadline event not due), or null when they pass.
     */
    private function implicitRefusal(
        RegionDefinition $region,
        StateDefinition $state,
        TransitionDefinition $transition,
        TransitionCandidate $candidate
    ): ?SpecificationResult {
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
                    message: $candidate->snapshot->isPaused($region->name)
                        ? sprintf("The deadline of state '%s' in region '%s' is paused.", $state->name, $region->name)
                        : sprintf(
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

        return null;
    }

    /**
     * The primary reason of a rejection and the details (2.10.0): within the first rejecting region, the
     * candidate that came closest (see the class description); every other refusing candidate of every
     * rejecting region goes to the details, in the order tried.
     *
     * @param array<string, list<array{transition: TransitionDefinition, state: StateDefinition, verdict: SpecificationResult}>> $refused
     * @return array{0: SpecificationResult|null, 1: list<SpecificationResult>} Primary (null = the reason) and details
     */
    private function primaryOf(array $refused, TransitionCandidate $candidate): array
    {
        $all = array_merge(...array_values($refused));
        if (count($all) === 1) {
            return [null, []];
        }
        $first = $refused[array_key_first($refused)];
        $best = null;
        $bestRank = null;
        foreach ($first as $tried) {
            $verdict = $tried['verdict'];
            // Compared in order, false before true: an evaluation error first, then a candidate the caller may
            // send (neither internal-only nor refused by a role guard), then the fewest failures; on a tie the
            // earlier candidate (the most specific) stays.
            $rank = [
                !$verdict->isError,
                ($this->isInternalOnly($tried['state'], $tried['transition']) && !$candidate->event->isInternal())
                    || $this->refusedByRole($tried['transition'], $candidate),
                count($verdict->failures),
            ];
            if ($bestRank === null || $rank < $bestRank) {
                $best = $verdict;
                $bestRank = $rank;
            }
        }
        $details = [];
        foreach ($all as $tried) {
            if ($tried['verdict'] !== $best) {
                $details[] = $tried['verdict'];
            }
        }

        return [$best, $details];
    }

    /**
     * True when a role guard (GuardRegistry::markRoleGuards()) of the transition refuses the candidate:
     * its guard, with every leaf that is not a role guard assumed out of the way, fails (2.10.0).
     */
    private function refusedByRole(TransitionDefinition $transition, TransitionCandidate $candidate): bool
    {
        $guard = $transition->guard;
        if ($guard === null) {
            return false;
        }
        try {
            return !$this->holdsAssuming($guard, $candidate, fn(GuardRef $ref): bool => !$this->guards->isRoleGuard($ref->name));
        } catch (\Throwable) {
            // A role guard that throws is an evaluation error, already reported as such: not a refusal by role.
            return false;
        }
    }

    /**
     * Evaluates a guard expression with some leaves assumed out of the way (2.10.0): an assumed leaf is
     * true where the expression needs it true and false under a `not`, so it never causes a refusal.
     *
     * @param Closure(GuardRef): bool $assume Whether a leaf is assumed
     */
    private function holdsAssuming(GuardExpression $expression, TransitionCandidate $candidate, Closure $assume, bool $positive = true): bool
    {
        if ($expression instanceof GuardRef) {
            return $assume($expression) ? $positive : $this->guards->build($expression->name, $expression->params)->isSatisfiedBy($candidate);
        }
        if ($expression instanceof GuardNot) {
            return !$this->holdsAssuming($expression->operand, $candidate, $assume, !$positive);
        }
        if ($expression instanceof GuardAllOf) {
            foreach ($expression->operands as $operand) {
                if (!$this->holdsAssuming($operand, $candidate, $assume, $positive)) {
                    return false;
                }
            }

            return true;
        }
        if ($expression instanceof GuardAnyOf) {
            foreach ($expression->operands as $operand) {
                if ($this->holdsAssuming($operand, $candidate, $assume, $positive)) {
                    return true;
                }
            }

            return false;
        }

        throw new StateMachineException('Unsupported guard expression node: ' . get_class($expression));
    }

    /**
     * The `payloadHas` fields of a guard (where the guard needs them present) that the event payload does
     * not provide, in the order of the guard (2.10.0; repeated when the guard repeats them).
     *
     * @return list<string>
     */
    private function missingPayloadFields(GuardExpression $expression, TransitionCandidate $candidate, bool $positive = true): array
    {
        if ($expression instanceof GuardRef) {
            $field = $expression->params['field'] ?? null;
            if (!$positive || $expression->name !== GuardRegistry::PAYLOAD_HAS || !is_string($field)) {
                return [];
            }

            return (new PayloadHasSpecification($field))->isSatisfiedBy($candidate) ? [] : [$field];
        }
        if ($expression instanceof GuardNot) {
            return $this->missingPayloadFields($expression->operand, $candidate, !$positive);
        }
        /** @var GuardAllOf|GuardAnyOf $expression the only other nodes */
        $fields = [];
        foreach ($expression->operands as $operand) {
            $fields = [...$fields, ...$this->missingPayloadFields($operand, $candidate, $positive)];
        }

        return $fields;
    }

    /**
     * Classifies a result of nextEvents() (2.10.0; see CandidateReason). Status and reason unchanged.
     *
     * @param object|array<mixed>|null $context
     */
    private function classify(
        MachineDefinition $definition,
        MachineSnapshot $snapshot,
        Event $event,
        object|array|null $context,
        TransitionResult $result
    ): TransitionResult {
        if ($result->isUnhandled()) {
            return $result;
        }
        $missing = [];
        $reason = CandidateReason::ALLOWED;
        if (!$result->isAccepted()) {
            $candidate = new TransitionCandidate($context, $event, $snapshot);
            /** @var list<CandidateReason> $refusals */
            $refusals = [];
            foreach ($definition->regions as $region) {
                $state = $region->getState($snapshot->stateOf($region->name));
                \assert($state !== null);
                $byCandidate = [];
                $passes = false;
                foreach ($region->candidatesFor($state, $event->type) as $transition) {
                    $verdict = $this->checkCandidate($region, $state, $transition, $candidate);
                    if ($verdict->isSatisfied) {
                        $passes = true;
                        break;
                    }
                    if ($verdict->isError || $this->implicitRefusal($region, $state, $transition, $candidate) !== null) {
                        $byCandidate[] = CandidateReason::GUARD_REFUSED;
                        if ($verdict->isError) {
                            break;
                        }
                        continue;
                    }
                    \assert($transition->guard !== null);
                    if ($this->refusedByRole($transition, $candidate)) {
                        $byCandidate[] = CandidateReason::ROLE_REFUSED;
                        continue;
                    }
                    $fields = $this->missingPayloadFields($transition->guard, $candidate);
                    if ($fields !== [] && $this->requiresOnly($transition->guard, $candidate)) {
                        $missing = [...$missing, ...$fields];
                        $passes = true;
                        break;
                    }
                    $byCandidate[] = CandidateReason::GUARD_REFUSED;
                }
                if (!$passes && $byCandidate !== []) {
                    $refusals[] = in_array(CandidateReason::GUARD_REFUSED, $byCandidate, true)
                        ? CandidateReason::GUARD_REFUSED
                        : CandidateReason::ROLE_REFUSED;
                }
            }
            $reason = match (true) {
                in_array(CandidateReason::ROLE_REFUSED, $refusals, true) => CandidateReason::ROLE_REFUSED,
                $refusals !== [] || $missing === [] => CandidateReason::GUARD_REFUSED,
                default => CandidateReason::MISSING_PAYLOAD,
            };
            $missing = $reason === CandidateReason::MISSING_PAYLOAD ? array_unique($missing) : [];
        }

        return new TransitionResult(
            $result->status,
            $result->event,
            $result->snapshot,
            $result->getTransitions(),
            $result->getEffects(),
            $result->reason,
            $result->getRejections(),
            $result->primaryReason,
            $result->getDetails(),
            $reason,
            $missing
        );
    }

    /**
     * True when the guard passes once its `payloadHas` leaves are assumed provided (2.10.0). Called only for
     * a guard that was evaluated without error, so its leaves evaluate.
     */
    private function requiresOnly(GuardExpression $guard, TransitionCandidate $candidate): bool
    {
        return $this->holdsAssuming($guard, $candidate, static fn(GuardRef $ref): bool => $ref->name === GuardRegistry::PAYLOAD_HAS);
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
     * @param object|array<mixed>|null $context Domain context (deadline sources and pause conditions)
     */
    private function apply(
        MachineDefinition $definition,
        MachineSnapshot $snapshot,
        Event $event,
        array $selected,
        object|array|null $context
    ): TransitionResult {
        $exits = [];
        $actions = [];
        $entries = [];
        $taken = [];
        $next = $snapshot;
        /** @var array<string, SpecificationResult> $rejections */
        $rejections = [];

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
                $due = null;
                if ($target->deadline !== null) {
                    $due = $this->dueOf($region, $target, $transition, $event, $event->occurredAt, $context);
                    if ($due instanceof SpecificationResult) {
                        $rejections[$region->name] = $due;
                        $due = null;
                    }
                }
                $next = $next->withState($region->name, $target->name, $due);
                $taken[] = new TakenTransition($region->name, $from->name, $target->name, $transition->id, $event->type);
            } else {
                $taken[] = new TakenTransition($region->name, $from->name, $from->name, $transition->id, $event->type, true);
            }
        }

        if ($rejections === []) {
            [$next, $rejections] = $this->applyPauses($definition, $next, $event, $context);
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
     * Pauses and resumes the deadlines whose `pausedWhile` condition changed on the new snapshot
     * (forward 025, D9). A region whose state was entered by this step has no balance (withState()
     * dropped it), so it pauses at once with its full deadline when the condition holds.
     *
     * @return array{0: MachineSnapshot, 1: array<string, SpecificationResult>} New snapshot, and the
     *         ERROR failure of each region whose pause condition could not be evaluated
     */
    private function applyPauses(
        MachineDefinition $definition,
        MachineSnapshot $next,
        Event $event,
        object|array|null $context
    ): array {
        $candidate = new TransitionCandidate($context, $event, $next);
        $result = $next;
        $errors = [];
        foreach ($definition->regions as $region) {
            $state = $region->getState($next->stateOf($region->name));
            $condition = $state?->deadline?->pausedWhile;
            if ($state === null || $condition === null) {
                continue;
            }
            try {
                $holds = $this->guards->compile($condition)->isSatisfiedBy($candidate);
            } catch (UnknownGuardException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $errors[$region->name] = $this->locate(
                    SpecificationResult::error($e, 'Deadline:pausedWhile', code: $condition->getCode()),
                    ['region' => $region->name, 'state' => $state->name, 'event' => $event->type]
                );
                continue;
            }

            $remaining = $next->pausedRemaining($region->name);
            $due = $next->dueAt($region->name);
            $pauses = $holds && $remaining === null && $due !== null;
            $resumes = !$holds && $remaining !== null;
            if (!$pauses && !$resumes) {
                continue;
            }
            $calendar = $this->calendarOf($region, $state, $event, $context);
            if ($calendar instanceof SpecificationResult) {
                $errors[$region->name] = $calendar;
                continue;
            }
            if ($pauses) {
                \assert($due !== null);
                $result = $result->withPaused(
                    $region->name,
                    $calendar === null ? self::remainingSeconds($due, $event->occurredAt) : $calendar->workSecondsBetween($event->occurredAt, $due)
                );
                continue;
            }
            \assert($remaining !== null);
            $resumed = $calendar === null ? self::resumeAt($event->occurredAt, $remaining) : $calendar->advance($event->occurredAt, $remaining);
            if ($resumed === null) {
                $errors[$region->name] = $this->sourceInvalid($region, $state, null, $event, true);
                continue;
            }
            $result = $result->withDueAt($region->name, $resumed);
        }

        return [$result, $errors];
    }

    /**
     * The due instant of the deadline of $state entered at $at, or the failure that rejects the
     * step (invalid source, a calendar factory that throws, a duration the calendar cannot place).
     *
     * @param object|array<mixed>|null $context
     */
    private function dueOf(
        RegionDefinition $region,
        StateDefinition $state,
        ?TransitionDefinition $transition,
        ?Event $event,
        DateTimeImmutable $at,
        object|array|null $context
    ): DateTimeImmutable|SpecificationResult {
        \assert($state->deadline !== null);
        $calendar = $this->calendarOf($region, $state, $event, $context);
        if ($calendar instanceof SpecificationResult) {
            return $calendar;
        }

        return $state->deadline->resolveDue($at, $context, $calendar)
            ?? $this->sourceInvalid($region, $state, $transition, $event);
    }

    /**
     * The calendar of the deadline of $state for this call: null when it names none, the ERROR
     * failure (`evaluation_error`) when its factory throws.
     *
     * @param object|array<mixed>|null $context
     * @throws UnknownDeadlineCalendarException When the name is not registered (definition not validated)
     */
    private function calendarOf(
        RegionDefinition $region,
        StateDefinition $state,
        ?Event $event,
        object|array|null $context
    ): IDeadlineCalendar|SpecificationResult|null {
        \assert($state->deadline !== null);
        $name = $state->deadline->calendar;
        if ($name === null) {
            return null;
        }
        try {
            return $this->calendars->resolve($name, $context);
        } catch (UnknownDeadlineCalendarException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->locate(
                SpecificationResult::error($e, 'Deadline:calendar'),
                ['region' => $region->name, 'state' => $state->name, 'calendar' => $name, 'event' => $event?->type ?? self::START_EVENT]
            );
        }
    }

    /**
     * Whole seconds from $now to $due, floored, never negative (pausing an overdue deadline keeps
     * it overdue: it resumes already due).
     */
    private static function remainingSeconds(DateTimeImmutable $due, DateTimeImmutable $now): int
    {
        $micros = ((int) $due->format('U') - (int) $now->format('U')) * 1_000_000
            + (int) $due->format('u') - (int) $now->format('u');

        return $micros <= 0 ? 0 : intdiv($micros, 1_000_000);
    }

    /**
     * $now plus $seconds of elapsed time, in the time zone of $now (no wall-clock DST surprise).
     */
    private static function resumeAt(DateTimeImmutable $now, int $seconds): DateTimeImmutable
    {
        $instant = DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%s', (int) $now->format('U') + $seconds, $now->format('u'))
        );
        \assert($instant instanceof DateTimeImmutable);

        return $instant->setTimezone($now->getTimezone());
    }

    /**
     * The ERROR failure of a deadline whose context source is missing or invalid (forward 025, D8),
     * or, with a calendar (2.7.0), whose duration the calendar cannot place within its limit of
     * days ($beyondCalendar, or a fixed `after` duration). The message names the path, never the
     * value read from the context.
     */
    private function sourceInvalid(
        RegionDefinition $region,
        StateDefinition $state,
        ?TransitionDefinition $transition,
        ?Event $event,
        bool $beyondCalendar = false
    ): SpecificationResult {
        $deadline = $state->deadline;
        \assert($deadline !== null);
        $path = $deadline->sourcePath();
        $metadata = ['region' => $region->name, 'state' => $state->name];
        if ($path !== null) {
            $metadata['path'] = $path;
        }
        $metadata['source'] = $deadline->sourceKind();
        if ($deadline->calendar !== null) {
            $metadata['calendar'] = $deadline->calendar;
        }
        if ($transition !== null) {
            $metadata['transition_id'] = $transition->id;
        }
        $metadata['event'] = $event?->type ?? self::START_EVENT;

        if ($beyondCalendar || $path === null) {
            $message = sprintf(
                "The deadline of state '%s' in region '%s' cannot be computed: calendar '%s' cannot place it within its limit of days.",
                $state->name,
                $region->name,
                (string) $deadline->calendar
            );
        } else {
            $expected = match (true) {
                $deadline->atFrom !== null => 'a DateTimeInterface or an ISO-8601 instant with date, time and offset',
                $deadline->calendar !== null => sprintf("an ISO-8601 duration without years or months that calendar '%s' can place", $deadline->calendar),
                default => 'an ISO-8601 duration',
            };
            $message = sprintf(
                "The deadline of state '%s' in region '%s' cannot be computed: the context value at '%s' is missing or invalid (expected %s).",
                $state->name,
                $region->name,
                $path,
                $expected
            );
        }

        return SpecificationResult::failure(
            message: $message,
            code: TransitionResult::CODE_DEADLINE_SOURCE_INVALID,
            ruleName: 'StateMachine',
            property: $path,
            metadata: $metadata,
            severity: FailureSeverity::ERROR
        );
    }

    /**
     * Adds location metadata to every failure and forces the ERROR severity.
     *
     * @param array<string, string> $location
     */
    private function locate(SpecificationResult $result, array $location): SpecificationResult
    {
        $failures = array_map(
            static fn(SpecificationFailure $f): SpecificationFailure => new SpecificationFailure(
                message: $f->message,
                code: $f->code,
                ruleName: $f->ruleName,
                property: $f->property,
                metadata: $f->metadata + $location,
                severity: FailureSeverity::ERROR
            ),
            $result->failures
        );

        return new SpecificationResult($result->isSatisfied, $failures, $result->isError, $result->exception);
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
