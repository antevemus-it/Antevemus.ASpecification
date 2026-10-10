<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Results;

/**
 * IOutcome - Common Shape of an Operation Outcome
 *
 * The shared contract of the state machine's TransitionResult and the use case's UseCaseResult
 * (forward 023, D20; forward 024, D4): success is derived from a single status, and the reason is a
 * SpecificationResult whose failures carry code, message, property and severity, where only
 * FailureSeverity::ERROR blocks. No outcome ever carries an exception or a stack trace.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Results
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IOutcome
{
    /** True when the outcome is a success; derived from the outcome's single status only. */
    public function isSuccess(): bool;

    /**
     * Reason of the outcome: the failures that blocked it, or the warnings and informational
     * failures that came back together with a success.
     */
    public function getReason(): SpecificationResult;
}
