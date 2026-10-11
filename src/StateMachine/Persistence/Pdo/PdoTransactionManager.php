<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\Pdo;

use Antevemus\ASpecification\Persistence\PdoTransactionManager as SharedPdoTransactionManager;
use Antevemus\ASpecification\StateMachine\Persistence\ITransactionManager;

/**
 * PdoTransactionManager - Unit of Work over One PDO Connection
 *
 * Begins a transaction, runs the work, commits; rolls back and rethrows when the work throws
 * (forward 023, D13). When the connection is already in a transaction (the caller wrapped the
 * runner in its own business transaction), the work joins it: nothing is begun nor committed
 * here, and a failure is rethrown for the owner to roll back.
 *
 * Since 1.11.0 (forward 028, D8) the behaviour lives in the shared Persistence\PdoTransactionManager,
 * which this class extends unchanged: it is both a state machine ITransactionManager (accepted by
 * MachinePersistence) and a shared Persistence\ITransactionManager (accepted by the PDO repository),
 * so one instance can give the repository and the state machine stores the same transaction.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\Pdo
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoTransactionManager extends SharedPdoTransactionManager implements ITransactionManager
{
}
