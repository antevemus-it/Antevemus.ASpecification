<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\UseCase;

/**
 * IUseCase - Contract of an Application Use Case
 *
 * One method, execute() (forward 024, D1): the request is the caller's object (a DTO), validated
 * by the UseCaseRunner through its #[ValidateRule]/#[AssertSpec] attributes and the specifications
 * registered for its class before execute() is called. The outcome is always a UseCaseResult: a
 * refusal is a result, never an exception. No mediator, container or handler discovery (D9).
 *
 * @template TRequest of object
 * @template TPayload
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage UseCase
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IUseCase
{
    /**
     * Executes the use case.
     *
     * @param TRequest $request Request object of the caller
     * @return UseCaseResult<TPayload>
     */
    public function execute(object $request): UseCaseResult;
}
