<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Results;

/**
 * FailureSeverity - Severity Classification of a Specification Failure
 *
 * Optional classification carried by a SpecificationFailure. Plain specifications leave it null;
 * the dynamic rule engine fills it from the catalog rule action that produced the failure
 * (`bloquear` → ERROR, `alertar` → WARNING, `apenas_log` → INFO), and an evaluation error is
 * always ERROR, mirroring the RuleEngineVerdict triage.
 *
 * Features:
 * - Three-level typed enumeration (ERROR, WARNING, INFO)
 * - String-backed for logging, telemetry and JSON transport
 *
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Results
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum FailureSeverity: string
{
    /** Impeding violation: the operation must not proceed. */
    case ERROR = 'error';

    /** Advisory violation: the operation may proceed, the user should be made aware. */
    case WARNING = 'warning';

    /** Audit-only violation: recorded for traceability, no user-facing effect. */
    case INFO = 'info';
}
