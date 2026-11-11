<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine\Exceptions;

use Antevemus\ASpecification\Engine\RuleChangeCandidate;
use Antevemus\ASpecification\Results\SpecificationResult;
use Throwable;

/**
 * RuleChangeRefusedException - The Change Guard Refused a Change of a Rule
 *
 * Thrown by RuleCatalogEditor (2.13.0, forward 060, D8) when the specification given to withChangeGuard() is not
 * satisfied by the RuleChangeCandidate, or cannot be evaluated (the exception of the evaluation is the previous
 * one). Nothing is written: no row, no history, no baseline (RN-02). getResult() carries the codes and messages of
 * the guard, for the screen; the message of the exception lists the codes only.
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class RuleChangeRefusedException extends RuleCatalogEditorException
{
    /**
     * @param RuleChangeCandidate $candidate The change the guard evaluated
     * @param SpecificationResult $result The evaluation of the guard
     * @param string $message Human-readable message
     * @param Throwable|null $previous The exception of the evaluation, when it could not be carried out
     */
    public function __construct(
        private readonly RuleChangeCandidate $candidate,
        private readonly SpecificationResult $result,
        string $message,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $candidate->code, $previous);
    }

    /** The change the guard evaluated. */
    public function getCandidate(): RuleChangeCandidate
    {
        return $this->candidate;
    }

    /** The evaluation of the guard: its codes and messages. */
    public function getResult(): SpecificationResult
    {
        return $this->result;
    }
}
