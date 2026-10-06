<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Concurrent;

use RuntimeException;
use Throwable;

/**
 * FileLockSynchronizer - Multi-Process Concurrency Synchronizer via OS File Locks (flock)
 *
 * Implements the Read/Write Lock pattern on the filesystem using the native OS flock() primitive.
 * Supports shared locks (LOCK_SH) for parallel concurrent reads and exclusive locks (LOCK_EX)
 * for atomic writes, with reentrancy tracking to prevent self-deadlocks.
 *
 * Features:
 * - Shared (LOCK_SH) and exclusive (LOCK_EX) lock mode support
 * - Reentrancy tracking for nested calls originating within the same process
 * - Guaranteed descriptor and lock release via try/finally blocks
 * - Configurable timeout with non-blocking attempts (LOCK_NB) and exponential backoff
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Concurrent
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class FileLockSynchronizer extends AbstractSynchronizer
{
    private readonly string $lockFilePath;
    private readonly int $lockTimeoutMs;
    private int $concurrentDepth = 0;
    private int $exclusiveDepth = 0;

    /**
     * Initialize the synchronizer with target lock file path.
     *
     * @param string|null $lockFilePath Path to lock file (null for default temp file)
     * @param int $lockTimeoutMs Timeout limit in milliseconds for acquiring the lock (default 3000ms)
     */
    public function __construct(?string $lockFilePath = null, int $lockTimeoutMs = 3000)
    {
        $this->lockFilePath = $lockFilePath ?? (sys_get_temp_dir() . '/aspecification_concurrency.lock');
        $this->lockTimeoutMs = max(100, $lockTimeoutMs);
    }

    /**
     * {@inheritdoc}
     */
    public function callConcurrently(callable $action): mixed
    {
        // Reentrancy: if already holding exclusive or concurrent lock, execute directly
        if ($this->exclusiveDepth > 0 || $this->concurrentDepth > 0) {
            $this->concurrentDepth++;
            try {
                return $action();
            } finally {
                $this->concurrentDepth--;
            }
        }

        return $this->executeWithLock(LOCK_SH, function () use ($action): mixed {
            $this->concurrentDepth++;
            try {
                return $action();
            } finally {
                $this->concurrentDepth--;
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function callExclusively(callable $action): mixed
    {
        // Reentrancy: if already holding exclusive lock, execute directly
        if ($this->exclusiveDepth > 0) {
            $this->exclusiveDepth++;
            try {
                return $action();
            } finally {
                $this->exclusiveDepth--;
            }
        }

        return $this->executeWithLock(LOCK_EX, function () use ($action): mixed {
            $this->exclusiveDepth++;
            try {
                return $action();
            } finally {
                $this->exclusiveDepth--;
            }
        });
    }

    /**
     * Execute callable by acquiring and releasing a file lock with timeout.
     *
     * @template T
     * @param int $lockType LOCK_SH or LOCK_EX
     * @param callable(): T $action
     * @return T
     * @throws RuntimeException
     */
    private function executeWithLock(int $lockType, callable $action): mixed
    {
        $handle = @fopen($this->lockFilePath, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Could not open synchronization file: {$this->lockFilePath}");
        }

        $startTime = microtime(true);
        $acquired = false;

        while ((microtime(true) - $startTime) * 1000 < $this->lockTimeoutMs) {
            if (flock($handle, $lockType | LOCK_NB)) {
                $acquired = true;
                break;
            }
            usleep(5000); // 5ms backoff
        }

        if (!$acquired) {
            fclose($handle);
            throw new RuntimeException(
                "Timeout of {$this->lockTimeoutMs}ms exceeded while acquiring lock for: {$this->lockFilePath}"
            );
        }

        try {
            return $action();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Return the path of the lock file in use.
     *
     * @return string
     */
    public function getLockFilePath(): string
    {
        return $this->lockFilePath;
    }
}
