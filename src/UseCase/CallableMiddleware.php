<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\UseCase;

use Closure;

/**
 * CallableMiddleware - Closure Adapter of IUseCaseMiddleware
 *
 * Wraps `fn(object $request, callable $next): UseCaseResult` as a middleware (forward 024, D7).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage UseCase
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class CallableMiddleware implements IUseCaseMiddleware
{
    /** @var Closure(object, callable): UseCaseResult */
    private readonly Closure $handler;

    /**
     * @param callable(object, callable): UseCaseResult $handler Middleware body
     */
    public function __construct(callable $handler)
    {
        $this->handler = $handler(...);
    }

    public function process(object $request, callable $next): UseCaseResult
    {
        return ($this->handler)($request, $next);
    }
}
