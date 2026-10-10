<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use Closure;

/**
 * ITransactionManager - Unit-of-Work Boundary Shared by the Runner and the Stores
 *
 * Runs a closure atomically (forward 023, D13): everything the closure writes through the stores
 * is committed when it returns and rolled back when it throws (the exception is rethrown). When a
 * transaction is already open on the same resource (the caller wrapped the runner in its own
 * business transaction), the closure joins it: the outermost owner commits or rolls back.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ITransactionManager
{
    /**
     * Runs the work atomically and returns its result.
     *
     * @template T
     * @param Closure(): T $work Unit of work
     * @return T
     * @throws \Throwable Whatever the work threw, after the rollback
     */
    public function transactional(Closure $work): mixed;
}
