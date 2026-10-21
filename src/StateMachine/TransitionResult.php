<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\Results\IOutcome;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;

/**
 * TransitionResult - Outcome of Sending an Event to the Pure Core
 *
 * A denied transition is a result, never an exception (forward 023, D6). The result carries a
 * single status (accepted | rejected | unhandled) from which success is derived (D20), the
 * snapshot after the event (the unchanged input snapshot when rejected or unhandled), the
 * transitions taken per region, the effects to dispatch (D11) and the reason: a
 * SpecificationResult whose failures are those of the guards that blocked, with their
 * because()/withCode() message and code intact, stamped with region, transition id and event in
 * the metadata, and severity ERROR when the guard did not set one. An evaluation error of a guard
 * keeps its `evaluation_error` marker and is always ERROR. No stack trace is ever serialized.
 *
 * It implements IOutcome, the shape shared with the use case result (forward 023, D20; forward
 * 024, D4): UseCaseResult::fromTransition() converts it without manual mapping.
 *
 * Since 2.10.0, besides the reason (unchanged):
 * - primaryReason: when several candidate transitions answer the same event (an approval by
 *   ranges) and all refuse, the reason lists the failures of all of them; primaryReason is the one
 *   to show: the failures of the candidate that came closest (see StateMachine::transition()), and
 *   details() the failures of each other candidate, in the order they were tried. When only one
 *   candidate refused, primaryReason equals the reason and details() is empty; when accepted or
 *   unhandled, primaryReason is the reason;
 * - candidateReason and missingPayload: set by nextEvents() only (null and empty elsewhere): the
 *   classification of the listed event (CandidateReason) and, for MISSING_PAYLOAD, the payload
 *   fields the `payloadHas` guards require.
 * None of them enters toArray(), whose shape is unchanged.
 *
 * @version    2.10.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class TransitionResult implements IOutcome
{
    /** Failure code of an unhandled event (no region had a candidate transition). */
    public const CODE_UNHANDLED = 'SM_UNHANDLED_EVENT';

    /** Failure code of any event sent to a machine that is already done (D10). */
    public const CODE_MACHINE_DONE = 'SM_MACHINE_DONE';

    /** Failure code of an internal-only transition reached by an external event (D19). */
    public const CODE_INTERNAL_EVENT = 'SM_INTERNAL_EVENT';

    /** Failure code of a deadline event delivered before the region's deadline is due (D18). */
    public const CODE_DEADLINE_NOT_DUE = 'SM_DEADLINE_NOT_DUE';

    /**
     * Failure code of an event (or start) entering a state whose deadline source, read from the
     * context (`afterFrom`, `atFrom`), is missing or invalid (forward 025, D8).
     */
    public const CODE_DEADLINE_SOURCE_INVALID = 'SM_DEADLINE_SOURCE_INVALID';

    /** @var list<TakenTransition> */
    private array $transitions;

    /** @var list<Effect> */
    private array $effects;

    /** @var array<string, SpecificationResult> */
    private array $rejections;

    /** Satisfied when accepted; the blocking failures otherwise. */
    public readonly SpecificationResult $reason;

    /** The failures to show (2.10.0): those of the candidate that came closest; the reason when only one refused. */
    public readonly SpecificationResult $primaryReason;

    /** @var list<SpecificationResult> */
    private array $details;

    /** @var list<string> */
    private array $missingPayload;

    /**
     * @param TransitionStatus $status Single status of the result
     * @param Event|null $event Event processed (null for the start result)
     * @param MachineSnapshot $snapshot Snapshot after the event (unchanged when not accepted)
     * @param list<TakenTransition> $transitions Transitions taken, in region order
     * @param list<Effect> $effects Effects to dispatch, in SCXML order
     * @param SpecificationResult $reason Satisfied when accepted; the blocking failures otherwise
     * @param array<string, SpecificationResult> $rejections Reason per rejecting region
     * @param SpecificationResult|null $primaryReason Failures to show (2.10.0); null = the reason
     * @param list<SpecificationResult> $details Failures of the other refusing candidates (2.10.0)
     * @param CandidateReason|null $candidateReason Classification given by nextEvents() (2.10.0)
     * @param list<string> $missingPayload Payload fields required, for MISSING_PAYLOAD (2.10.0)
     */
    public function __construct(
        public readonly TransitionStatus $status,
        public readonly ?Event $event,
        public readonly MachineSnapshot $snapshot,
        array $transitions = [],
        array $effects = [],
        ?SpecificationResult $reason = null,
        array $rejections = [],
        ?SpecificationResult $primaryReason = null,
        array $details = [],
        public readonly ?CandidateReason $candidateReason = null,
        array $missingPayload = []
    ) {
        $this->transitions = array_values($transitions);
        $this->effects = array_values($effects);
        $this->rejections = $rejections;
        $this->reason = $reason ?? SpecificationResult::satisfied();
        $this->primaryReason = $primaryReason ?? $this->reason;
        $this->details = array_values($details);
        $this->missingPayload = array_values($missingPayload);
    }

    /** Single status of the result. */
    public function getStatus(): TransitionStatus
    {
        return $this->status;
    }

    /** True when the event was accepted (the only success status). */
    public function isAccepted(): bool
    {
        return $this->status === TransitionStatus::ACCEPTED;
    }

    /** Alias of isAccepted(): success is derived from the status only. */
    public function isSuccess(): bool
    {
        return $this->isAccepted();
    }

    /** True when some region rejected the event (nothing changed). */
    public function isRejected(): bool
    {
        return $this->status === TransitionStatus::REJECTED;
    }

    /** True when no region had a candidate transition (nothing changed). */
    public function isUnhandled(): bool
    {
        return $this->status === TransitionStatus::UNHANDLED;
    }

    /** Event processed (null for the start result). */
    public function getEvent(): ?Event
    {
        return $this->event;
    }

    /** Snapshot after the event (the input snapshot when not accepted). */
    public function getSnapshot(): MachineSnapshot
    {
        return $this->snapshot;
    }

    /**
     * Transitions taken, one per region that moved or ran a targetless transition.
     *
     * @return list<TakenTransition>
     */
    public function getTransitions(): array
    {
        return $this->transitions;
    }

    /**
     * Effects to dispatch, in SCXML order (exit → transition → entry; regions, then actions, in order).
     *
     * @return list<Effect>
     */
    public function getEffects(): array
    {
        return $this->effects;
    }

    /**
     * Reason of the outcome: satisfied when accepted; the blocking failures when rejected; a single
     * informational failure (CODE_UNHANDLED) when unhandled.
     */
    public function getReason(): SpecificationResult
    {
        return $this->reason;
    }

    /**
     * Reason per rejecting region (empty unless rejected).
     *
     * @return array<string, SpecificationResult>
     */
    public function getRejections(): array
    {
        return $this->rejections;
    }

    /**
     * The failures to show (2.10.0): with several refusing candidates for the event, those of the
     * candidate that came closest; otherwise the reason itself.
     */
    public function getPrimaryReason(): SpecificationResult
    {
        return $this->primaryReason;
    }

    /**
     * The failures of the other refusing candidates (2.10.0), one result per candidate, in the
     * order they were tried (regions in declaration order); empty when only one candidate refused.
     *
     * @return list<SpecificationResult>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * Classification of an event listed by nextEvents() (2.10.0); null for a result that did not
     * come from nextEvents().
     */
    public function getCandidateReason(): ?CandidateReason
    {
        return $this->candidateReason;
    }

    /**
     * Payload fields the `payloadHas` guards require (2.10.0), in the order of the guards; non-empty
     * only for CandidateReason::MISSING_PAYLOAD.
     *
     * @return list<string>
     */
    public function getMissingPayload(): array
    {
        return $this->missingPayload;
    }

    /**
     * Returns a JSON-friendly array form (deterministic; exceptions are reduced to their class in
     * the failure metadata, never serialized with a stack trace).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'event' => $this->event?->toArray(),
            'snapshot' => $this->snapshot->toArray(),
            'transitions' => array_map(static fn(TakenTransition $t): array => $t->toArray(), $this->transitions),
            'effects' => array_map(static fn(Effect $e): array => $e->toArray(), $this->effects),
            'reason' => self::resultToArray($this->reason),
            'rejections' => array_map(self::resultToArray(...), $this->rejections),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function resultToArray(SpecificationResult $result): array
    {
        return [
            'satisfied' => $result->isSatisfied,
            'error' => $result->isError,
            'failures' => array_map(self::failureToArray(...), $result->failures),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function failureToArray(SpecificationFailure $failure): array
    {
        return [
            'message' => $failure->message,
            'code' => $failure->code,
            'rule' => $failure->ruleName,
            'property' => $failure->property,
            'severity' => $failure->severity?->value,
            'metadata' => self::plain($failure->metadata),
        ];
    }

    private static function plain(mixed $value): mixed
    {
        if ($value instanceof SpecificationFailure) {
            return self::failureToArray($value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(MachineSnapshot::DATE_FORMAT);
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (is_object($value)) {
            return get_class($value);
        }
        if (is_array($value)) {
            return array_map(self::plain(...), $value);
        }

        return $value;
    }
}
