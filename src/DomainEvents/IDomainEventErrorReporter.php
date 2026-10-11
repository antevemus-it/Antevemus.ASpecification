<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

/**
 * IDomainEventErrorReporter - Where Trigger Evaluation Errors Go
 *
 * TriggeringRepository lets a write go on when a trigger cannot be evaluated and hands the error
 * here (forward 029, D3): the safe message in TriggerEvaluationError::getMessage(), the exception
 * in its $exception, for the log. Plug a logger (a PSR-3 adapter belongs to the application: the
 * library has zero dependencies). An exception thrown by the reporter itself is ignored, so a
 * broken logger never changes the outcome of the write.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IDomainEventErrorReporter
{
    /**
     * Reports a trigger that could not be evaluated on a write.
     */
    public function report(TriggerEvaluationError $error): void;
}
