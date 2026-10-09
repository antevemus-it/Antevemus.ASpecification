<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Concurrent;

use Fiber;
use RuntimeException;
use SplObjectStorage;

/**
 * SemaphoreSynchronizer - Intra-Process Read/Write Lock Synchronizer Based on Counting Semaphores
 *
 * Modeled on the algorithm of net.sourceforge.domian.util.concurrent.locks.SemaphoreSynchronizer (Domian,
 * Copyright 2006-2010 the original author or authors, Apache License 2.0; see THIRD_PARTY_NOTICES.md).
 *
 * What is the same as the Java original:
 * - Two "semaphores": a pool of concurrent permits (one per concurrent block) and one exclusive permit;
 *   an exclusive block takes the exclusive permit and then drains the whole concurrent pool, so it only
 *   starts when no concurrent block is running, and blocks every other block until it ends.
 * - One reentrancy flag per execution context covering BOTH modes (Java: a ThreadLocal boolean). A
 *   context that already holds a permit, concurrent or exclusive, runs any nested block directly:
 *   concurrent in concurrent, exclusive in concurrent, concurrent in exclusive and exclusive in
 *   exclusive never deadlock (the four cases of SemaphoreSynchronizerTest). Nested blocks neither take
 *   nor release permits; the outermost block does, in its finally.
 * - Permits are restored even when the block throws.
 *
 * What is a limit of a single PHP process (no threads):
 * - The execution context is the current Fiber (or the main flow when no fiber runs), the closest thing
 *   PHP has to a thread identity. In Java the flag is a static ThreadLocal shared by ALL synchronizer
 *   instances (a thread holding a permit on synchronizer A skips the locks of synchronizer B); here the
 *   state is per instance, which is stricter and what the permit model intends.
 * - Java WAITS (acquireUninterruptibly) when the permit is held by another thread. A single process has
 *   nobody to wait for: if another fiber holds the permit, waiting here would deadlock, since that fiber
 *   can only progress when this one yields. So the two blocking situations of the Java original throw a
 *   RuntimeException naming the holder instead: a concurrent or exclusive block requested while another
 *   context runs an exclusive block, and an exclusive block requested while other contexts run concurrent
 *   blocks (or the concurrent pool is exhausted).
 * - The permits live in this object: the lock is INTRA-PROCESS. To exclude between processes use
 *   SysVSemaphoreSynchronizer (same permit model over SysV IPC semaphores, ext-sysvsem, 1.5.0) or
 *   FileLockSynchronizer (flock); the choice is explicit, there is no silent fallback.
 * - Exceptions thrown by the block propagate as they are; the Java original wraps every Throwable of
 *   call*() in a RuntimeException because of checked exceptions, which PHP does not have.
 * - Java drains the concurrent pool when fewer than 10 permits remain after an exclusive block (an
 *   artifact of Integer.MAX_VALUE arithmetic); here the pool is simply restored to its maximum.
 *
 * Features:
 * - High concurrency capacity with 10,000 default permits (MAX_NUMBER_OF_CONCURRENT_PERMITS)
 * - Concurrent mode consuming single permits (callConcurrently / runConcurrently)
 * - Exclusive mode draining all permits to guarantee atomic isolated access
 * - Full reentrancy per execution context, in all four mode combinations
 * - State introspection methods (getAvailablePermits, isExclusiveLocked, hasAcquiredPermit)
 *
 * @version    1.5.0
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

    /** Nesting depth of the main flow (no fiber) inside synchronized blocks: the Java ThreadLocal flag, counted. */
    private int $mainDepth = 0;

    /** @var SplObjectStorage<Fiber, int> Nesting depth of each fiber inside synchronized blocks. */
    private SplObjectStorage $fiberDepths;

    /** Number of distinct contexts currently holding a concurrent permit (diagnostics for the messages). */
    private int $concurrentHolders = 0;

    /**
     * Initialize the synchronizer with maximum concurrent permit limit.
     *
     * @param int $maxPermits Maximum simultaneous concurrent permits (default 10,000)
     */
    public function __construct(int $maxPermits = self::MAX_NUMBER_OF_CONCURRENT_PERMITS)
    {
        $this->maxPermits = max(1, $maxPermits);
        $this->availablePermits = $this->maxPermits;
        $this->fiberDepths = new SplObjectStorage();
    }

    /**
     * {@inheritdoc}
     */
    public function callConcurrently(callable $action): mixed
    {
        // Reentrancy (Java ThreadLocal flag): a context already inside a block, in either mode, runs directly
        if ($this->currentDepth() > 0) {
            $this->enter();
            try {
                return $action();
            } finally {
                $this->leave();
            }
        }

        $this->acquireConcurrentPermit();
        $this->enter();

        try {
            return $action();
        } finally {
            $this->leave();
            $this->releaseConcurrentPermit();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function callExclusively(callable $action): mixed
    {
        // Reentrancy (Java ThreadLocal flag): exclusive inside concurrent or exclusive runs directly
        if ($this->currentDepth() > 0) {
            $this->enter();
            try {
                return $action();
            } finally {
                $this->leave();
            }
        }

        $this->acquireExclusiveLock();
        $this->enter();

        try {
            return $action();
        } finally {
            $this->leave();
            $this->releaseExclusiveLock();
        }
    }

    /**
     * Indicate whether the current execution context (fiber or main flow) already acquired execution
     * permission, in either mode (reentrancy flag of the Java original).
     *
     * @return bool
     */
    public function hasAcquiredPermit(): bool
    {
        return $this->currentDepth() > 0;
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

    ///////////////////////////////////////////////////////////////////////////
    // Execution context (the Java ThreadLocal)
    ///////////////////////////////////////////////////////////////////////////

    private function currentDepth(): int
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            return $this->mainDepth;
        }
        return $this->fiberDepths->contains($fiber) ? $this->fiberDepths[$fiber] : 0;
    }

    private function enter(): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $this->mainDepth++;
            return;
        }
        $this->fiberDepths[$fiber] = $this->currentDepth() + 1;
    }

    private function leave(): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $this->mainDepth = max(0, $this->mainDepth - 1);
            return;
        }
        $depth = $this->currentDepth() - 1;
        if ($depth <= 0) {
            $this->fiberDepths->detach($fiber);
        } else {
            $this->fiberDepths[$fiber] = $depth;
        }
    }

    private function describeCurrentContext(): string
    {
        $fiber = Fiber::getCurrent();
        return $fiber === null ? 'the main flow' : 'fiber #' . spl_object_id($fiber);
    }

    ///////////////////////////////////////////////////////////////////////////
    // Permits
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Acquire 1 concurrent permit.
     *
     * @return void
     * @throws RuntimeException When another context holds the exclusive permit or the pool is exhausted
     *                          (the Java original would block until it is released; see the class DocBlock)
     */
    private function acquireConcurrentPermit(): void
    {
        if ($this->exclusiveLocked) {
            throw new RuntimeException(sprintf(
                'Exclusive lock is active in another execution context: %s cannot acquire a concurrent permit. ' .
                'Java would wait for the exclusive block to finish; a single PHP process cannot wait for another ' .
                'fiber without deadlocking, so the request is refused instead.',
                $this->describeCurrentContext()
            ));
        }

        if ($this->availablePermits <= 0) {
            throw new RuntimeException(sprintf(
                'Maximum concurrent permit capacity (%d) exhausted by other execution contexts. ' .
                'Java would wait for a permit to be released; a single PHP process cannot wait for another ' .
                'fiber without deadlocking, so the request is refused instead.',
                $this->maxPermits
            ));
        }

        $this->availablePermits--;
        $this->concurrentHolders++;
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
        $this->concurrentHolders = max(0, $this->concurrentHolders - 1);
    }

    /**
     * Acquire the exclusive lock by draining all concurrent permits.
     *
     * @return void
     * @throws RuntimeException When another context holds the exclusive permit or concurrent permits
     *                          (the Java original would wait for them to finish; see the class DocBlock)
     */
    private function acquireExclusiveLock(): void
    {
        if ($this->exclusiveLocked) {
            throw new RuntimeException(sprintf(
                'Exclusive lock already acquired by another execution context: %s cannot acquire it. ' .
                'Java would queue behind the running exclusive block; a single PHP process cannot wait for ' .
                'another fiber without deadlocking, so the request is refused instead.',
                $this->describeCurrentContext()
            ));
        }

        if ($this->availablePermits < $this->maxPermits) {
            throw new RuntimeException(sprintf(
                'Concurrent operations active in other execution contexts (%d holder(s), %d/%d permits available): ' .
                '%s cannot acquire the exclusive lock. Java would wait for the running concurrent blocks to finish; ' .
                'a single PHP process cannot wait for another fiber without deadlocking, so the request is refused instead.',
                $this->concurrentHolders,
                $this->availablePermits,
                $this->maxPermits,
                $this->describeCurrentContext()
            ));
        }

        $this->exclusiveLocked = true;
        $this->availablePermits = 0; // Drain all permits
    }

    /**
     * Release the exclusive lock by restoring all permits.
     *
     * @return void
     */
    private function releaseExclusiveLock(): void
    {
        $this->exclusiveLocked = false;
        $this->availablePermits = $this->maxPermits; // Restore permits
    }
}
