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
 * @version    1.8.0
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

    /** @var list<TakenTransition> */
    private array $transitions;

    /** @var list<Effect> */
    private array $effects;

    /** @var array<string, SpecificationResult> */
    private array $rejections;

    /** Satisfied when accepted; the blocking failures otherwise. */
    public readonly SpecificationResult $reason;

    /**
     * @param TransitionStatus $status Single status of the result
     * @param Event|null $event Event processed (null for the start result)
     * @param MachineSnapshot $snapshot Snapshot after the event (unchanged when not accepted)
     * @param list<TakenTransition> $transitions Transitions taken, in region order
     * @param list<Effect> $effects Effects to dispatch, in SCXML order
     * @param SpecificationResult $reason Satisfied when accepted; the blocking failures otherwise
     * @param array<string, SpecificationResult> $rejections Reason per rejecting region
     */
    public function __construct(
        public readonly TransitionStatus $status,
        public readonly ?Event $event,
        public readonly MachineSnapshot $snapshot,
        array $transitions = [],
        array $effects = [],
        ?SpecificationResult $reason = null,
        array $rejections = []
    ) {
        $this->transitions = array_values($transitions);
        $this->effects = array_values($effects);
        $this->rejections = $rejections;
        $this->reason = $reason ?? SpecificationResult::satisfied();
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
