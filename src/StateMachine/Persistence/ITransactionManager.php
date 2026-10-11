<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use Antevemus\ASpecification\Persistence\ITransactionManager as SharedTransactionManager;
use Closure;

/**
 * ITransactionManager - Unit-of-Work Boundary Shared by the Runner and the Stores
 *
 * Runs a closure atomically (forward 023, D13): everything the closure writes through the stores
 * is committed when it returns and rolled back when it throws (the exception is rethrown). When a
 * transaction is already open on the same resource (the caller wrapped the runner in its own
 * business transaction), the closure joins it: the outermost owner commits or rolls back.
 *
 * Since 1.11.0 (forward 028, D8) this interface extends the shared port
 * Persistence\ITransactionManager without adding anything: every implementation of this type is
 * also accepted by the PDO repository and the other persistence components, so they can all write
 * in the same transaction. Code written against this type keeps compiling and behaving as before.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ITransactionManager extends SharedTransactionManager
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
