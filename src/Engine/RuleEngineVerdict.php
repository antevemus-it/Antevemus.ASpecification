<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

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
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class RuleEngineVerdict implements Countable
{
    /**
     * @param bool $isSatisfied True if no failure was registered
     * @param list<SpecificationFailure> $blockingFailures Violations with 'bloquear' (block) action
     * @param list<SpecificationFailure> $warningFailures Violations with 'alertar' (warn) action
     * @param list<SpecificationFailure> $logFailures Violations with 'apenas_log' (log) action
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
            $actionRaw = $failure->metadata['acao']
                ?? $failure->metadata['acao_ao_violar']
                ?? $failure->metadata['action']
                ?? 'bloquear';

            $action = $actionRaw instanceof RuleAction
                ? $actionRaw
                : RuleAction::fromOrDefault(is_string($actionRaw) ? $actionRaw : null);

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
     * Checks whether all rules were successfully satisfied.
     *
     * @return bool
     */
    public function isSatisfied(): bool
    {
        return $this->isSatisfied;
    }

    /**
     * Checks if there are blocking violations preventing the operational transition.
     *
     * @return bool
     */
    public function hasBlockingErrors(): bool
    {
        return count($this->blockingFailures) > 0;
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
     * Returns all failures with 'bloquear' (block) action.
     *
     * @return list<SpecificationFailure>
     */
    public function getBlockingFailures(): array
    {
        return $this->blockingFailures;
    }

    /**
     * Returns all failures with 'alertar' (warn) action.
     *
     * @return list<SpecificationFailure>
     */
    public function getWarningFailures(): array
    {
        return $this->warningFailures;
    }

    /**
     * Returns all failures with 'apenas_log' (log) action.
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
     * Returns the business or regulatory codes of all recorded failures.
     *
     * @return list<string>
     */
    public function getFailureCodes(): array
    {
        return $this->specificationResult->getCodes();
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
            $base = $failure->metadata['fundamento_legal']
                ?? $failure->metadata['legal_basis']
                ?? null;

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
