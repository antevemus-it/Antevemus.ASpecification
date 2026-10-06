<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\File;

use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;

/**
 * FileLockTrait - Safe and non-blocking file locking trait
 *
 * Encapsulates PHP flock() calls, providing support for shared locks
 * (LOCK_SH for reading) and exclusive locks (LOCK_EX for writing), with a retry
 * loop and configurable timeout to prevent perpetual deadlocks.
 *
 * Features:
 * - Atomic execution under exclusive lock (withExclusiveLock)
 * - Safe execution under shared lock (withSharedLock)
 * - Microsecond backoff retries with configurable timeout
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\File
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait FileLockTrait
{
    /**
     * Default timeout in milliseconds for lock acquisition.
     */
    protected int $lockTimeoutMs = 3000;

    /**
     * Executes a callback protected by an exclusive lock (LOCK_EX).
     *
     * @template R
     * @param string $lockFilePath Path to the file or lock resource
     * @param callable(): R $callback Function to execute under lock
     * @return R
     * @throws RepositoryException
     */
    protected function withExclusiveLock(string $lockFilePath, callable $callback): mixed
    {
        return $this->executeWithLock($lockFilePath, LOCK_EX, $callback);
    }

    /**
     * Executes a callback protected by a shared lock (LOCK_SH).
     *
     * @template R
     * @param string $lockFilePath Path to the file or lock resource
     * @param callable(): R $callback Function to execute under lock
     * @return R
     * @throws RepositoryException
     */
    protected function withSharedLock(string $lockFilePath, callable $callback): mixed
    {
        return $this->executeWithLock($lockFilePath, LOCK_SH, $callback);
    }

    /**
     * Executes a routine with flock respecting retries and timeout.
     *
     * @template R
     * @param string $lockFilePath
     * @param int $lockType LOCK_EX or LOCK_SH
     * @param callable(): R $callback
     * @return R
     * @throws RepositoryException
     */
    private function executeWithLock(string $lockFilePath, int $lockType, callable $callback): mixed
    {
        $handle = @fopen($lockFilePath, "c+");
        if ($handle === false) {
            throw new RepositoryException("Não foi possível abrir o descritor de lock para o arquivo: {$lockFilePath}");
        }

        $startTime = microtime(true);
        $acquired = false;

        while ((microtime(true) - $startTime) * 1000 < $this->lockTimeoutMs) {
            if (flock($handle, $lockType | LOCK_NB)) {
                $acquired = true;
                break;
            }
            usleep(10000); // aguarda 10ms
        }

        if (!$acquired) {
            fclose($handle);
            throw new RepositoryException("Timeout de {$this->lockTimeoutMs}ms excedido ao tentar obter lock para: {$lockFilePath}");
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
