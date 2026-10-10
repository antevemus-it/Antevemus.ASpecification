<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\UseCase;

use Antevemus\ASpecification\Results\FailureSeverity;
use Antevemus\ASpecification\Results\IOutcome;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\StateMachine\Exceptions\ConcurrencyConflictException;
use Antevemus\ASpecification\StateMachine\TransitionResult;
use Antevemus\ASpecification\StateMachine\TransitionStatus;
use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;
use JsonSerializable;
use Throwable;
use UnitEnum;

/**
 * UseCaseResult - Final, Immutable Outcome of a Use Case
 *
 * The same shape as the state machine's TransitionResult (IOutcome; forward 023, D20; forward 024,
 * D3/D4): a single UseCaseStatus from which success is derived, a payload (any value), a reason
 * (SpecificationResult) and an optional correlation id.
 *
 * Rules enforced at construction:
 * - Coherence (RN-04): a success status cannot carry an ERROR failure nor an evaluation error; a
 *   failure status needs at least one failure. Both raise InvalidArgumentException.
 * - Severity: every failure of the reason carries an explicit FailureSeverity (severityOf()): its
 *   own severity, else the `severity` metadata written by the attribute validator, else ERROR;
 *   an evaluation error is always ERROR. Only ERROR blocks; WARNING and INFO come back with a
 *   success.
 * - No leak (RN-02): the result never holds a Throwable. The exception of the reason is dropped,
 *   Throwable values are removed from the failure metadata, and a failure marked
 *   `evaluation_error` gets a generic message and the marker `true` instead of the exception
 *   message and class. failed() takes a code and a message, never a Throwable.
 *
 * The reason is satisfied only when it has no failure at all (the convention of SpecificationResult
 * and RuleEngineVerdict): a success with warnings has an unsatisfied reason, and isSuccess() is
 * still true, because success comes from the status only.
 *
 * @template TPayload
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage UseCase
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class UseCaseResult implements IOutcome, JsonSerializable
{
    /** Code of the FAILED result the runner returns for any unhandled Throwable (D6). */
    public const CODE_UNHANDLED_EXCEPTION = 'UNHANDLED_EXCEPTION';

    /** Code of the CONFLICT result of a lost optimistic lock of the state machine runner (D4). */
    public const CODE_CONCURRENCY_CONFLICT = 'SM_CONCURRENCY_CONFLICT';

    /** Generic message of the FAILED result of an unhandled Throwable (the exception never leaks). */
    public const UNHANDLED_EXCEPTION_MESSAGE = 'An unexpected error occurred while executing the use case.';

    /** Generic message that replaces the exception message of an evaluation-error failure. */
    public const EVALUATION_ERROR_MESSAGE = 'The rule could not be evaluated.';

    /** Rule name of the failures built by the code/message factories. */
    public const RULE_NAME = 'UseCase';

    /** Single status of the result. */
    public readonly UseCaseStatus $status;

    /** @var TPayload|null Payload (any value; null when there is none) */
    public readonly mixed $payload;

    /** Reason: the blocking failures, or the warnings returned with a success. */
    public readonly SpecificationResult $reason;

    /** Optional correlation id. */
    public readonly ?string $correlationId;

    /**
     * @param UseCaseStatus $status Single status
     * @param mixed $payload Payload
     * @param SpecificationResult $reason Reason (normalized and checked)
     * @param string|null $correlationId Optional correlation id
     * @throws InvalidArgumentException When status and reason are incoherent (RN-04)
     */
    private function __construct(UseCaseStatus $status, mixed $payload, SpecificationResult $reason, ?string $correlationId)
    {
        if ($correlationId !== null && trim($correlationId) === '') {
            throw new InvalidArgumentException('The correlation id must be null or a non-blank string.');
        }
        $reason = self::normalize($reason);
        if ($status->isSuccess()) {
            if ($reason->isError || self::errorsOf($reason->failures) !== []) {
                throw new InvalidArgumentException(sprintf(
                    'A use case result with the success status "%s" cannot carry an ERROR failure or an evaluation error.',
                    $status->value
                ));
            }
        } elseif ($reason->failures === []) {
            throw new InvalidArgumentException(sprintf(
                'A use case result with the failure status "%s" needs at least one failure in its reason.',
                $status->value
            ));
        }

        $this->status = $status;
        $this->payload = $payload;
        $this->reason = $reason;
        $this->correlationId = $correlationId;
    }

    // ==========================================
    // Factories
    // ==========================================

    /**
     * Generic factory, checked like every other one (RN-04).
     *
     * @param UseCaseStatus $status Single status
     * @param mixed $payload Payload
     * @param SpecificationResult|null $reason Reason (null = no failure)
     * @param string|null $correlationId Optional correlation id
     * @return self
     * @throws InvalidArgumentException When status and reason are incoherent
     */
    public static function of(
        UseCaseStatus $status,
        mixed $payload = null,
        ?SpecificationResult $reason = null,
        ?string $correlationId = null
    ): self {
        return new self($status, $payload, $reason ?? SpecificationResult::satisfied(), $correlationId);
    }

    /**
     * SUCCESS, optionally with non-blocking failures (WARNING or INFO).
     *
     * @param mixed $payload Payload
     * @param SpecificationResult|list<SpecificationFailure>|null $warnings Non-blocking failures
     * @return self
     * @throws InvalidArgumentException When a warning is an ERROR
     */
    public static function success(mixed $payload = null, SpecificationResult|array|null $warnings = null): self
    {
        return new self(UseCaseStatus::SUCCESS, $payload, self::warningsReason($warnings), null);
    }

    /**
     * CREATED, optionally with non-blocking failures.
     *
     * @param mixed $payload Payload (usually what was created)
     * @param SpecificationResult|list<SpecificationFailure>|null $warnings Non-blocking failures
     * @return self
     */
    public static function created(mixed $payload = null, SpecificationResult|array|null $warnings = null): self
    {
        return new self(UseCaseStatus::CREATED, $payload, self::warningsReason($warnings), null);
    }

    /**
     * ACCEPTED (for later processing), optionally with non-blocking failures.
     *
     * @param mixed $payload Payload (e.g. a ticket)
     * @param SpecificationResult|list<SpecificationFailure>|null $warnings Non-blocking failures
     * @return self
     */
    public static function accepted(mixed $payload = null, SpecificationResult|array|null $warnings = null): self
    {
        return new self(UseCaseStatus::ACCEPTED, $payload, self::warningsReason($warnings), null);
    }

    /**
     * NO_CHANGE (nothing to do, idempotent), optionally with non-blocking failures.
     *
     * @param mixed $payload Payload (e.g. the current state)
     * @param SpecificationResult|list<SpecificationFailure>|null $warnings Non-blocking failures
     * @return self
     */
    public static function noChange(mixed $payload = null, SpecificationResult|array|null $warnings = null): self
    {
        return new self(UseCaseStatus::NO_CHANGE, $payload, self::warningsReason($warnings), null);
    }

    /**
     * INVALID: the request did not pass validation.
     *
     * @param SpecificationResult $reason Validation failures (at least one)
     * @return self
     */
    public static function invalid(SpecificationResult $reason): self
    {
        return new self(UseCaseStatus::INVALID, null, $reason, null);
    }

    /**
     * REJECTED: a business rule refused the request.
     *
     * @param SpecificationResult $reason Failures of the rule (at least one)
     * @return self
     */
    public static function rejected(SpecificationResult $reason): self
    {
        return new self(UseCaseStatus::REJECTED, null, $reason, null);
    }

    /**
     * NOT_FOUND with one ERROR failure.
     *
     * @param string $code Failure code
     * @param string $message Failure message
     * @param array<string, mixed> $metadata Failure metadata
     * @return self
     */
    public static function notFound(string $code, string $message, array $metadata = []): self
    {
        return self::single(UseCaseStatus::NOT_FOUND, $code, $message, $metadata);
    }

    /**
     * CONFLICT with one ERROR failure (state or version incompatible with the request).
     *
     * @param string $code Failure code
     * @param string $message Failure message
     * @param array<string, mixed> $metadata Failure metadata
     * @return self
     */
    public static function conflict(string $code, string $message, array $metadata = []): self
    {
        return self::single(UseCaseStatus::CONFLICT, $code, $message, $metadata);
    }

    /**
     * FORBIDDEN with one ERROR failure.
     *
     * @param string $code Failure code
     * @param string $message Failure message
     * @param array<string, mixed> $metadata Failure metadata
     * @return self
     */
    public static function forbidden(string $code, string $message, array $metadata = []): self
    {
        return self::single(UseCaseStatus::FORBIDDEN, $code, $message, $metadata);
    }

    /**
     * FAILED with one ERROR failure. Deliberately takes no Throwable: the exception goes to the
     * error reporter, never into the result (RN-02).
     *
     * @param string $code Failure code
     * @param string $message Failure message (write it for the end user, not for the log)
     * @param array<string, mixed> $metadata Failure metadata
     * @return self
     */
    public static function failed(string $code, string $message, array $metadata = []): self
    {
        return self::single(UseCaseStatus::FAILED, $code, $message, $metadata);
    }

    /**
     * Converts a state machine TransitionResult (D4): accepted → SUCCESS (payload = the snapshot
     * after the event when none is given); rejected → REJECTED with the same reason; unhandled →
     * CONFLICT (the event does not fit the current state) with the same reason.
     *
     * @param TransitionResult $transition Result of the pure core or of the runner
     * @param mixed $payload Payload of the success (null = the snapshot)
     * @return self
     */
    public static function fromTransition(TransitionResult $transition, mixed $payload = null): self
    {
        return match ($transition->getStatus()) {
            TransitionStatus::ACCEPTED => new self(UseCaseStatus::SUCCESS, $payload ?? $transition->getSnapshot(), $transition->getReason(), null),
            TransitionStatus::REJECTED => new self(UseCaseStatus::REJECTED, null, $transition->getReason(), null),
            TransitionStatus::UNHANDLED => new self(UseCaseStatus::CONFLICT, null, $transition->getReason(), null),
        };
    }

    /**
     * Converts a lost optimistic lock of the state machine runner (D4): CONFLICT with the code
     * SM_CONCURRENCY_CONFLICT. Only the instance id and the versions are kept; the exception
     * itself is not.
     *
     * @param ConcurrencyConflictException $conflict The runner's exception
     * @return self
     */
    public static function fromConcurrencyConflict(ConcurrencyConflictException $conflict): self
    {
        return self::conflict(
            self::CODE_CONCURRENCY_CONFLICT,
            sprintf('Machine instance "%s" was changed by someone else: reload it and try again.', $conflict->getInstanceId()),
            [
                'instance_id' => $conflict->getInstanceId(),
                'expected_version' => $conflict->getExpectedVersion(),
                'actual_version' => $conflict->getActualVersion(),
            ]
        );
    }

    // ==========================================
    // Accessors
    // ==========================================

    /** Single status of the result. */
    public function getStatus(): UseCaseStatus
    {
        return $this->status;
    }

    /** True for SUCCESS, CREATED, ACCEPTED and NO_CHANGE (derived from the status only). */
    public function isSuccess(): bool
    {
        return $this->status->isSuccess();
    }

    /**
     * Payload (null when there is none).
     *
     * @return TPayload|null
     */
    public function getPayload(): mixed
    {
        return $this->payload;
    }

    /** Reason: the blocking failures, or the warnings returned with a success. */
    public function getReason(): SpecificationResult
    {
        return $this->reason;
    }

    /** Optional correlation id. */
    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    /**
     * Distinct non-empty failure codes of the reason, in order.
     *
     * @return list<string>
     */
    public function getCodes(): array
    {
        return array_values(array_unique($this->reason->getCodes()));
    }

    /**
     * Failures of severity ERROR.
     *
     * @return list<SpecificationFailure>
     */
    public function getErrors(): array
    {
        return self::errorsOf($this->reason->failures);
    }

    /**
     * Failures of severity WARNING or INFO.
     *
     * @return list<SpecificationFailure>
     */
    public function getWarnings(): array
    {
        return array_values(array_filter(
            $this->reason->failures,
            static fn(SpecificationFailure $f): bool => $f->severity !== FailureSeverity::ERROR
        ));
    }

    /**
     * Copy with non-blocking failures appended to the reason (the runner attaches the validation
     * warnings this way, D6).
     *
     * @param SpecificationResult|list<SpecificationFailure> $warnings WARNING or INFO failures
     * @return self
     * @throws InvalidArgumentException When one of them is an ERROR
     */
    public function withWarnings(SpecificationResult|array $warnings): self
    {
        $extra = self::warningsReason($warnings);
        if ($extra->failures === []) {
            return $this;
        }

        return new self(
            $this->status,
            $this->payload,
            new SpecificationResult(false, array_merge($this->reason->failures, $extra->failures), $this->reason->isError),
            $this->correlationId
        );
    }

    /**
     * Copy with the given correlation id.
     *
     * @param string|null $correlationId Correlation id (null removes it)
     * @return self
     */
    public function withCorrelationId(?string $correlationId): self
    {
        return new self($this->status, $this->payload, $this->reason, $correlationId);
    }

    /**
     * JSON-friendly array form. The payload is converted when it is an enum, a date, an object
     * with a public toArray() or a JsonSerializable; the failure metadata reduces other objects
     * to their class. No exception, file, line or trace can appear (none is held).
     *
     * @return array{status: string, success: bool, payload: mixed, reason: array<string, mixed>, correlation_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'success' => $this->isSuccess(),
            'payload' => self::plainPayload($this->payload),
            'reason' => [
                'satisfied' => $this->reason->isSatisfied,
                'error' => $this->reason->isError,
                'failures' => array_map(self::failureToArray(...), $this->reason->failures),
            ],
            'correlation_id' => $this->correlationId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // ==========================================
    // Severity and sanitization
    // ==========================================

    /**
     * Effective severity of a failure: ERROR for an evaluation error; otherwise its own severity;
     * otherwise the `severity` metadata written by #[AssertSpec]/#[ValidateRule] ('ERROR',
     * 'WARNING', 'INFO', any case); otherwise ERROR (an unclassified failure blocks, as in the
     * state machine).
     *
     * @param SpecificationFailure $failure Failure to classify
     * @return FailureSeverity
     */
    public static function severityOf(SpecificationFailure $failure): FailureSeverity
    {
        if (array_key_exists('evaluation_error', $failure->metadata)) {
            return FailureSeverity::ERROR;
        }
        if ($failure->severity !== null) {
            return $failure->severity;
        }
        $declared = $failure->metadata['severity'] ?? null;
        if ($declared instanceof FailureSeverity) {
            return $declared;
        }
        if (is_string($declared)) {
            return FailureSeverity::tryFrom(strtolower(trim($declared))) ?? FailureSeverity::ERROR;
        }

        return FailureSeverity::ERROR;
    }

    /**
     * Normalizes a reason: explicit severities, no Throwable, generic evaluation-error messages,
     * satisfied only without failures.
     */
    private static function normalize(SpecificationResult $reason): SpecificationResult
    {
        $failures = array_map(self::sanitizeFailure(...), array_values($reason->failures));

        return new SpecificationResult($failures === [] && !$reason->isError, $failures, $reason->isError, null);
    }

    private static function sanitizeFailure(SpecificationFailure $failure): SpecificationFailure
    {
        $severity = self::severityOf($failure);
        $metadata = self::scrub($failure->metadata);
        $message = $failure->message;
        if (array_key_exists('evaluation_error', $failure->metadata)) {
            $message = self::EVALUATION_ERROR_MESSAGE;
            $metadata['evaluation_error'] = true;
        }

        return new SpecificationFailure($message, $failure->code, $failure->ruleName, $failure->property, $metadata, $severity);
    }

    /**
     * Removes every Throwable (recursively) from metadata; nested failures are sanitized too.
     *
     * @param array<mixed> $metadata
     * @return array<mixed>
     */
    private static function scrub(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            if ($value instanceof Throwable) {
                continue;
            }
            if ($value instanceof SpecificationFailure) {
                $value = self::sanitizeFailure($value);
            } elseif (is_array($value)) {
                $value = self::scrub($value);
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * @param list<SpecificationFailure> $failures
     * @return list<SpecificationFailure>
     */
    private static function errorsOf(array $failures): array
    {
        return array_values(array_filter(
            $failures,
            static fn(SpecificationFailure $f): bool => self::severityOf($f) === FailureSeverity::ERROR
        ));
    }

    /**
     * @param SpecificationResult|list<SpecificationFailure>|null $warnings
     * @throws InvalidArgumentException When an element is not a failure, or is an ERROR
     */
    private static function warningsReason(SpecificationResult|array|null $warnings): SpecificationResult
    {
        if ($warnings === null) {
            return SpecificationResult::satisfied();
        }
        if ($warnings instanceof SpecificationResult) {
            if ($warnings->isError) {
                throw new InvalidArgumentException('An evaluation error is never a warning.');
            }
            $warnings = $warnings->failures;
        }
        foreach ($warnings as $warning) {
            if (!$warning instanceof SpecificationFailure) {
                throw new InvalidArgumentException('Warnings must be SpecificationFailure instances.');
            }
            if (self::severityOf($warning) === FailureSeverity::ERROR) {
                throw new InvalidArgumentException(sprintf(
                    'The failure "%s" is an ERROR, not a warning: only WARNING and INFO come back with a success.',
                    $warning->code ?? $warning->message
                ));
            }
        }

        return new SpecificationResult($warnings === [], array_values($warnings));
    }

    /**
     * @param array<string, mixed> $metadata
     * @throws InvalidArgumentException When the code or the message is blank
     */
    private static function single(UseCaseStatus $status, string $code, string $message, array $metadata): self
    {
        if (trim($code) === '' || trim($message) === '') {
            throw new InvalidArgumentException('A use case failure needs a non-blank code and message.');
        }

        return new self($status, null, SpecificationResult::failure(
            message: $message,
            code: $code,
            ruleName: self::RULE_NAME,
            metadata: $metadata,
            severity: FailureSeverity::ERROR
        ), null);
    }

    // ==========================================
    // Serialization helpers
    // ==========================================

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
            'metadata' => self::plainMetadata($failure->metadata),
        ];
    }

    private static function plainMetadata(mixed $value): mixed
    {
        if ($value instanceof SpecificationFailure) {
            return self::failureToArray($value);
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof UnitEnum) {
            return $value->name;
        }
        if (is_object($value)) {
            return get_class($value);
        }
        if (is_array($value)) {
            return array_map(self::plainMetadata(...), $value);
        }

        return $value;
    }

    private static function plainPayload(mixed $value): mixed
    {
        if ($value instanceof Throwable) {
            return null;
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof UnitEnum) {
            return $value->name;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }
        if ($value instanceof JsonSerializable) {
            return $value->jsonSerialize();
        }
        if (is_object($value) && method_exists($value, 'toArray') && (new \ReflectionMethod($value, 'toArray'))->isPublic()) {
            return $value->toArray();
        }
        if (is_array($value)) {
            return array_map(self::plainPayload(...), $value);
        }

        return $value;
    }
}
