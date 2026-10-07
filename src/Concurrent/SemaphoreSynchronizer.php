<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Concurrent;

use RuntimeException;

/**
 * SemaphoreSynchronizer - Read/Write Lock Synchronizer Based on Counting Semaphores
 *
 * Direct port of the Java implementation net.sourceforge.domian.util.concurrent.locks.SemaphoreSynchronizer.
 * Utilizes a permit-counting model with high capacity (10,000 concurrent permits)
 * for non-blocking parallel reads, and drains all permits for exclusive isolation
 * during atomic mutations/writes, with full reentrancy support.
 *
 * Features:
 * - High concurrency capacity with 10,000 default permits (MAX_NUMBER_OF_CONCURRENT_PERMITS)
 * - Concurrent mode consuming single permits (callConcurrently / runConcurrently)
 * - Exclusive mode draining all permits to guarantee atomic isolated access
 * - Transparent reentrancy detection preventing self-deadlocks
 * - State introspection methods (getAvailablePermits, isExclusiveLocked)
 *
 * @version    1.3.1
 * @package    Antevemus\ASpecification
 * @subpackage Concurrent
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class SemaphoreSynchronizer extends AbstractSynchronizer
{
    /** @var int Upper bound of permits accepted by the constructor (typed constants need PHP 8.3; the package floor is 8.2). */
    public const MAX_NUMBER_OF_CONCURRENT_PERMITS = 10000;

    private readonly int $maxPermits;
    private int $availablePermits;
    private bool $exclusiveLocked = false;
    private int $concurrentDepth = 0;
    private int $exclusiveDepth = 0;

    /**
     * Initialize the synchronizer with maximum concurrent permit limit.
     *
     * @param int $maxPermits Maximum simultaneous concurrent permits (default 10,000)
     */
    public function __construct(int $maxPermits = self::MAX_NUMBER_OF_CONCURRENT_PERMITS)
    {
        $this->maxPermits = max(1, $maxPermits);
        $this->availablePermits = $this->maxPermits;
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

        $this->acquireConcurrentPermit();
        $this->concurrentDepth++;

        try {
            return $action();
        } finally {
            $this->concurrentDepth--;
            $this->releaseConcurrentPermit();
        }
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

        $this->acquireExclusiveLock();
        $this->exclusiveDepth++;

        try {
            return $action();
        } finally {
            $this->exclusiveDepth--;
            $this->releaseExclusiveLock();
        }
    }

    /**
     * Indicate whether current thread/call stack already acquired execution permission (reentrancy).
     *
     * @return bool
     */
    public function hasAcquiredPermit(): bool
    {
        return $this->exclusiveDepth > 0 || $this->concurrentDepth > 0;
    }

    /**
     * Return currently available concurrent permits.
     *
     * @return int
     */
    public function getAvailablePermits(): int
    {
        return $this->availablePermits;
    }

    /**
     * Return maximum configured concurrent permits.
     *
     * @return int
     */
    public function getMaxPermits(): int
    {
        return $this->maxPermits;
    }

    /**
     * Indicate whether exclusive lock is currently active.
     *
     * @return bool
     */
    public function isExclusiveLocked(): bool
    {
        return $this->exclusiveLocked;
    }

    /**
     * Acquire 1 concurrent permit.
     *
     * @return void
     */
    private function acquireConcurrentPermit(): void
    {
        if ($this->exclusiveLocked) {
            throw new RuntimeException("Exclusive lock is active: cannot acquire concurrent permit.");
        }

        if ($this->availablePermits <= 0) {
            throw new RuntimeException("Maximum concurrent permit capacity ({$this->maxPermits}) exhausted.");
        }

        $this->availablePermits--;
    }

    /**
     * Release 1 concurrent permit.
     *
     * @return void
     */
    private function releaseConcurrentPermit(): void
    {
        if ($this->availablePermits < $this->maxPermits) {
            $this->availablePermits++;
        }
    }

    /**
     * Acquire exclusive lock by draining all permits.
     *
     * @return void
     */
    private function acquireExclusiveLock(): void
    {
        if ($this->exclusiveLocked) {
            throw new RuntimeException("Exclusive lock already acquired by another operation.");
        }

        if ($this->availablePermits < $this->maxPermits) {
            throw new RuntimeException("Concurrent operations active ({$this->availablePermits}/{$this->maxPermits}): wait for completion before acquiring exclusive lock.");
        }

        $this->exclusiveLocked = true;
        $this->availablePermits = 0; // Drain all permits
    }

    /**
     * Release exclusive lock by restoring all permits.
     *
     * @return void
     */
    private function releaseExclusiveLock(): void
    {
        $this->exclusiveLocked = false;
        $this->availablePermits = $this->maxPermits; // Restore permits
    }
}
