<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Persistence;

use Closure;
use PDO;
use Throwable;

/**
 * PdoTransactionManager - Unit of Work over One PDO Connection
 *
 * Begins a transaction, runs the work, commits; rolls back and rethrows when the work throws.
 * When the connection is already in a transaction (the caller wrapped the work in its own business
 * transaction, or an outer transactional() call is running), the work joins it: nothing is begun
 * nor committed here, and a failure is rethrown for the owner to roll back.
 *
 * Shared implementation since 1.11.0 (forward 028, D8), with the behaviour of the state machine
 * PdoTransactionManager of 1.8.0, which now extends this class. Use the state machine class when the
 * same manager must also be handed to MachinePersistence (it is both types).
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class PdoTransactionManager implements ITransactionManager
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * The connection this manager opens transactions on.
     */
    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    /**
     * True while the connection is in a transaction (opened here or by the caller).
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
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
