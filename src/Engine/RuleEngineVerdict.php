<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Engine\Exceptions\LegacyVocabularyException;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
use Countable;

/**
 * RuleEngineVerdict - Structured Operational Verdict from Dynamic Rule Engine
 *
 * Aggregates the verdict emitted by business rule and document evaluations,
 * partitioning failures according to their operational actions (HTTP 403 blocks, warnings, and audit logs).
 *
 * Features:
 * - Automated categorization of violations by severity (Blocking, Warning, Log)
 * - Global approval query (isSatisfied) and impediment check (hasBlockingErrors)
 * - Direct extraction of failure codes, user-facing reasons, and statutory legal bases
 * - Transparent encapsulation of underlying SpecificationResult
 *
 * Semantics (read before using it as a gate):
 * - `isSatisfied()` is true only when NO failure of any action was recorded. A verdict with
 *   warnings or log-only violations is NOT satisfied, even though the operation may proceed.
 * - `hasBlockingErrors()` is the operational gate: it is the only question that decides whether
 *   the transition is forbidden. `canProceed()` is its negation.
 * - An evaluation error (a rule that could not be evaluated: missing property, throwing getter,
 *   incompatible type) is ALWAYS a blocking failure, regardless of the rule's configured action.
 * - The action is read from the failure metadata by RuleAction::fromMetadata() (`action`, then
 *   `violation_action`, default BLOCK). Since 2.0.0 a Portuguese key (`acao`, `acao_ao_violar`) or value
 *   (`bloquear`, `alertar`, `apenas_log`) is refused with LegacyVocabularyException instead of triaged;
 *   so is the legal basis under `fundamento_legal` (read as `legal_basis` only).
 * - `isPending()` (2.5.0, forward 048, D25): the evaluation error comes from data an asynchronous source
 *   has not produced yet (SpecificationResult::isPending()). It still blocks (it is an evaluation error):
 *   the verdict is "not decided yet", to evaluate again later, never an approval nor a refusal.
 *
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class RuleEngineVerdict implements Countable
{
    /**
     * Portuguese metadata key read by getLegalBases() up to 1.14.x (legacy => canonical); a failure
     * carrying it is refused since 2.0.0 (the action keys are in RuleAction::LEGACY_METADATA_KEYS).
     */
    public const LEGACY_METADATA_KEYS = ['fundamento_legal' => 'legal_basis'];

    /**
     * @param bool $isSatisfied True if no failure was registered
     * @param list<SpecificationFailure> $blockingFailures Violations with the 'block' action
     * @param list<SpecificationFailure> $warningFailures Violations with the 'warn' action
     * @param list<SpecificationFailure> $logFailures Violations with the 'log' action
     * @param SpecificationResult $specificationResult Original result from Notification Pattern
     */
    public function __construct(
        public bool $isSatisfied,
        public array $blockingFailures,
        public array $warningFailures,
        public array $logFailures,
        public SpecificationResult $specificationResult
    ) {
    }

    /**
     * Creates an approved verdict without violations.
     *
     * @return self
     */
    public static function satisfied(): self
    {
        $specResult = SpecificationResult::satisfied();
        return new self(
            isSatisfied: true,
            blockingFailures: [],
            warningFailures: [],
            logFailures: [],
            specificationResult: $specResult
        );
    }

    /**
     * Constructs the operational verdict by partitioning SpecificationResult failures by action.
     *
     * @param SpecificationResult $result
     * @return self
     */
    public static function fromSpecificationResult(SpecificationResult $result): self
    {
        if ($result->isSatisfied) {
            return self::satisfied();
        }

        $blocking = [];
        $warning = [];
        $log = [];

        foreach ($result->failures as $failure) {
            // Portuguese metadata keys the verdict used to read (1.7.0 to 1.14.x) are refused (2.0.0).
            LegacyVocabularyException::assertNoLegacyKeys($failure->metadata, self::LEGACY_METADATA_KEYS, 'failure metadata key');

            // A failure produced by an evaluation error is never WARN/LOG: the rule was not evaluated.
            if (isset($failure->metadata['evaluation_error'])) {
                $blocking[] = $failure;
                continue;
            }

            $action = RuleAction::fromMetadata($failure->metadata);

            match ($action) {
                RuleAction::BLOCK => $blocking[] = $failure,
                RuleAction::WARN => $warning[] = $failure,
                RuleAction::LOG => $log[] = $failure,
            };
        }

        return new self(
            isSatisfied: $result->isSatisfied,
            blockingFailures: $blocking,
            warningFailures: $warning,
            logFailures: $log,
            specificationResult: $result
        );
    }

    /**
     * Checks whether all rules were successfully satisfied: no failure of ANY action was recorded.
     *
     * This is not the "may proceed" question. A verdict carrying only warnings or log-only
     * violations returns false here while hasBlockingErrors() returns false too. Use
     * hasBlockingErrors() (or canProceed()) as the operational gate.
     *
     * @return bool
     */
    public function isSatisfied(): bool
    {
        return $this->isSatisfied;
    }

    /**
     * Checks if there are blocking violations preventing the operational transition.
     * This is the operational gate; evaluation errors always count as blocking.
     *
     * @return bool
     */
    public function hasBlockingErrors(): bool
    {
        return count($this->blockingFailures) > 0;
    }

    /**
     * Checks whether the operation may proceed: no blocking violation and no evaluation error.
     * Warnings and log-only violations do not prevent proceeding.
     *
     * @return bool
     */
    public function canProceed(): bool
    {
        return !$this->hasBlockingErrors() && !$this->hasEvaluationErrors();
    }

    /**
     * Checks whether at least one rule could not be evaluated (missing property, throwing
     * accessor, incompatible candidate type). Such failures are always blocking.
     *
     * @return bool
     */
    public function hasEvaluationErrors(): bool
    {
        return $this->specificationResult->isError;
    }

    /**
     * Whether the evaluation could not finish because external data is not ready yet (2.5.0): an
     * asynchronous source accepted the request and its result is pending. The verdict blocks, like any
     * evaluation error; ask again later (SpecificationResult::isPending()).
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->specificationResult->isPending();
    }

    /**
     * Checks if there are warning violations requiring user awareness.
     *
     * @return bool
     */
    public function hasWarnings(): bool
    {
        return count($this->warningFailures) > 0;
    }

    /**
     * Checks if there are audit/log-only violations.
     *
     * @return bool
     */
    public function hasLogs(): bool
    {
        return count($this->logFailures) > 0;
    }

    /**
     * Returns all failures with the 'block' action.
     *
     * @return list<SpecificationFailure>
     */
    public function getBlockingFailures(): array
    {
        return $this->blockingFailures;
    }

    /**
     * Returns all failures with the 'warn' action.
     *
     * @return list<SpecificationFailure>
     */
    public function getWarningFailures(): array
    {
        return $this->warningFailures;
    }

    /**
     * Returns all failures with the 'log' action.
     *
     * @return list<SpecificationFailure>
     */
    public function getLogFailures(): array
    {
        return $this->logFailures;
    }

    /**
     * Returns all recorded failures regardless of action.
     *
     * @return list<SpecificationFailure>
     */
    public function getAllFailures(): array
    {
        return $this->specificationResult->failures;
    }

    /**
     * Returns the business or regulatory code of every recorded failure, in the same order and
     * with the same length as getAllFailures(). A failure without code yields an explicit null,
     * so index i of this list always refers to failure i (rules compiled by the engine always
     * carry the catalog rule code).
     *
     * @return list<string|null>
     */
    public function getFailureCodes(): array
    {
        return array_map(
            static fn(SpecificationFailure $failure): ?string => $failure->code,
            $this->specificationResult->failures
        );
    }

    /**
     * Returns explanatory reason messages for all recorded failures.
     *
     * @return list<string>
     */
    public function getMessages(): array
    {
        return $this->specificationResult->getReasons();
    }

    /**
     * Returns the list of statutory legal bases extracted from failure metadata.
     *
     * @return list<string>
     */
    public function getLegalBases(): array
    {
        $bases = [];
        foreach ($this->specificationResult->failures as $failure) {
            $base = $failure->metadata['legal_basis'] ?? null;

            if (is_string($base) && trim($base) !== '' && !in_array($base, $bases, true)) {
                $bases[] = $base;
            }
        }
        return $bases;
    }

    /**
     * Returns total count of recorded violations.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->specificationResult);
    }
}
