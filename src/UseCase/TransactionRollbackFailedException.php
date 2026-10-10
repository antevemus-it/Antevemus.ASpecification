<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\UseCase;

use RuntimeException;
use Throwable;

/**
 * TransactionRollbackFailedException - Rollback Failed After an Exception
 *
 * Thrown by the TransactionMiddleware when the rollback that follows an exception (of the use
 * case, of the rest of the pipeline or of the commit) throws too. Neither exception is lost: the
 * original one is getOriginal(), the rollback failure is getPrevious(). Like any exception, it
 * reaches the UseCaseRunner, which returns failed('UNHANDLED_EXCEPTION', ...) and reports it.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage UseCase
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class TransactionRollbackFailedException extends RuntimeException
{
    /**
     * @param Throwable $original Exception that triggered the rollback
     * @param Throwable $rollbackFailure Exception thrown by the rollback
     */
    public function __construct(private readonly Throwable $original, Throwable $rollbackFailure)
    {
        parent::__construct('The transaction rollback failed after an exception; see getOriginal() and getPrevious().', 0, $rollbackFailure);
    }

    /** Exception that triggered the rollback. */
    public function getOriginal(): Throwable
    {
        return $this->original;
    }
}
