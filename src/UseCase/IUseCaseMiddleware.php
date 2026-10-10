<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\UseCase;

/**
 * IUseCaseMiddleware - Link of the UseCaseRunner Pipeline
 *
 * Middlewares run in a chain, in registration order (forward 024, D7): the first registered is
 * the outermost. The code before `$next($request)` is the inbound part (it runs before the request
 * validation), the code after it is the outbound part. A middleware may short-circuit by returning
 * a result without calling `$next`. An exception thrown by a middleware or by anything inside it
 * reaches the runner, which turns it into failed('UNHANDLED_EXCEPTION', ...).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage UseCase
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IUseCaseMiddleware
{
    /**
     * Processes the request.
     *
     * @param object $request Request object of the caller
     * @param callable(object): UseCaseResult $next Rest of the pipeline
     * @return UseCaseResult
     */
    public function process(object $request, callable $next): UseCaseResult;
}
