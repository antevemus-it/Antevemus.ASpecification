<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Persistence;

use Closure;

/**
 * ITransactionManager - Unit-of-Work Boundary Shared by Every Persistence Component
 *
 * Runs a closure atomically: everything the closure writes through the participating components
 * (the PDO repository, the state machine stores, an outbox) is committed when it returns and
 * rolled back when it throws (the exception is rethrown). When a transaction is already open on
 * the same resource (the caller wrapped the work in its own business transaction), the closure
 * joins it: the outermost owner commits or rolls back.
 *
 * Shared port since 1.11.0 (forward 028, D8). The state machine port
 * (StateMachine\Persistence\ITransactionManager, 1.8.0) extends this interface, so every existing
 * implementation is also accepted wherever this type is expected, and the repository and the state
 * machine stores can write in the same transaction.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Persistence
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
