<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\Pdo;

use Antevemus\ASpecification\StateMachine\Persistence\ITransactionManager;
use Closure;
use PDO;
use Throwable;

/**
 * PdoTransactionManager - Unit of Work over One PDO Connection
 *
 * Begins a transaction, runs the work, commits; rolls back and rethrows when the work throws
 * (forward 023, D13). When the connection is already in a transaction (the caller wrapped the
 * runner in its own business transaction), the work joins it: nothing is begun nor committed
 * here, and a failure is rethrown for the owner to roll back.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\Pdo
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoTransactionManager implements ITransactionManager
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** {@inheritdoc} */
    public function transactional(Closure $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }

        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $result;
    }
}
