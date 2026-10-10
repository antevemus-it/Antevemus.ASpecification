<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\UseCase;

use Closure;
use Throwable;

/**
 * TransactionMiddleware - Unit of Work Around the Use Case
 *
 * Receives three callables (begin, commit, rollback) and wraps the rest of the pipeline
 * (forward 024, D7, RN-05):
 * - begin before calling `$next`;
 * - commit only when the result is a success (UseCaseStatus::isSuccess());
 * - rollback when the result is a failure, and when anything inside (or the commit) throws; the
 *   exception is then rethrown untouched, never swallowed, so the runner turns it into
 *   failed('UNHANDLED_EXCEPTION', ...) and reports it. If the rollback throws too, a
 *   TransactionRollbackFailedException carrying both is thrown instead.
 *
 * Example with PDO: `new TransactionMiddleware($pdo->beginTransaction(...), $pdo->commit(...), $pdo->rollBack(...))`.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage UseCase
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class TransactionMiddleware implements IUseCaseMiddleware
{
    private readonly Closure $begin;

    private readonly Closure $commit;

    private readonly Closure $rollback;

    /**
     * @param callable(): mixed $begin Opens the transaction
     * @param callable(): mixed $commit Commits it
     * @param callable(): mixed $rollback Rolls it back
     */
    public function __construct(callable $begin, callable $commit, callable $rollback)
    {
        $this->begin = $begin(...);
        $this->commit = $commit(...);
        $this->rollback = $rollback(...);
    }

    /**
     * @throws Throwable Whatever the pipeline or the commit threw, after the rollback
     * @throws TransactionRollbackFailedException When the rollback after an exception throws too
     */
    public function process(object $request, callable $next): UseCaseResult
    {
        ($this->begin)();

        try {
            $result = $next($request);
        } catch (Throwable $e) {
            $this->rollbackAfter($e);
            throw $e;
        }

        if (!$result->isSuccess()) {
            ($this->rollback)();

            return $result;
        }

        try {
            ($this->commit)();
        } catch (Throwable $e) {
            $this->rollbackAfter($e);
            throw $e;
        }

        return $result;
    }

    /**
     * @throws TransactionRollbackFailedException When the rollback throws
     */
    private function rollbackAfter(Throwable $original): void
    {
        try {
            ($this->rollback)();
        } catch (Throwable $rollbackFailure) {
            throw new TransactionRollbackFailedException($original, $rollbackFailure);
        }
    }
}
