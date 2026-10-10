<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\UseCase;

use Throwable;

/**
 * IUseCaseErrorReporter - Where Unhandled Exceptions Go
 *
 * The UseCaseRunner never puts an exception in a result (forward 024, RN-02): the result is
 * failed('UNHANDLED_EXCEPTION', generic message) and the exception goes here (D6). Plug a logger
 * (a PSR-3 adapter belongs to the application: the library has zero dependencies). The runner also
 * reports the exception behind an evaluation error of the request validation, whose message the
 * result replaces with a generic one. An exception thrown by the reporter itself is ignored, so a
 * broken logger never changes the outcome.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage UseCase
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IUseCaseErrorReporter
{
    /**
     * Reports an exception raised while running a use case.
     *
     * @param Throwable $error The exception (full detail, for the log only)
     * @param IUseCase $useCase Use case being run
     * @param object $request Request object
     */
    public function report(Throwable $error, IUseCase $useCase, object $request): void;
}
