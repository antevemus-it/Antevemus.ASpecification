<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Concurrent;

use Fiber;
use InvalidArgumentException;
use RuntimeException;
use SplObjectStorage;
use SysvSemaphore;

/**
 * SysVSemaphoreSynchronizer - Read/Write Lock Shared Between Processes (SysV IPC Semaphores)
 *
 * The cross-process counterpart of SemaphoreSynchronizer (which is intra-process): the same permit
 * model of the Domian SemaphoreSynchronizer (Copyright 2006-2010 the original author or authors,
 * Apache License 2.0; see THIRD_PARTY_NOTICES.md), backed by two System V semaphores that every
 * process using the same `$name` shares:
 *
 * - a counting semaphore with `$maxPermits` permits: each concurrent (read) block takes one, so up
 *   to `$maxPermits` processes read at the same time;
 * - a binary write semaphore: an exclusive block takes it FIRST and only then drains the
 *   `$maxPermits` read permits, so two writers never sit on partial permits waiting for each other.
 *   A reader passes through the write semaphore (takes it and gives it back) before taking its
 *   permit, so a waiting writer is not starved by a continuous flow of readers.
 *
 * Unlike SemaphoreSynchronizer, a block requested while another PROCESS holds a conflicting permit
 * WAITS (sem_acquire blocks) until it is released: that is the whole point of the class. Inside one
 * process the reentrancy rules are the same as SemaphoreSynchronizer's (one flag per execution
 * context, the main flow or the current Fiber, covering both modes): a context that already holds a
 * permit runs any nested block directly. A request that could only be satisfied by another context
 * of the SAME process releasing its permits (a fiber asking for the exclusive lock while another
 * fiber of this process reads, or reading while another fiber of this process writes) would wait
 * for itself, so it throws a RuntimeException instead, as SemaphoreSynchronizer does.
 *
 * Keys: the two SysV keys derive from `$name` by crc32 (stable between processes and restarts); use
 * the same name and the same `$maxPermits` in every process. The semaphore set is created by the
 * first process that asks for it, with that process's `$maxPermits` and `$permissions`.
 *
 * Lifecycle: permits are returned in the `finally` of every block, and release() returns any permit
 * this instance still holds (also called by __destruct); the kernel undoes the permits of a process
 * that dies while holding them (SEM_UNDO). remove() deletes the semaphore set (sem_remove): an act of
 * the owner, after every process has stopped using it.
 *
 * Requires ext-sysvsem (not available on Windows): without it the constructor throws a
 * RuntimeException telling to use SemaphoreSynchronizer. The choice between intra-process and
 * cross-process locking is explicit, never a silent fallback.
 *
 * Features:
 * - Shared (concurrent) and exclusive blocks excluded across processes
 * - Writer preference (turnstile) and no partial-permit deadlock between writers
 * - Reentrancy per execution context, in all four mode combinations
 * - Permits restored when the block throws, on release(), on destruction and on process death
 *
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Concurrent
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class SysVSemaphoreSynchronizer extends AbstractSynchronizer
{
    /** @var int Largest value a System V semaphore holds on Linux (SEMVMX). */
    public const MAX_SYSV_PERMITS = 32767;

    private readonly string $name;
    private readonly int $maxPermits;
    private readonly int $readKey;
    private readonly int $writeKey;
    private ?SysvSemaphore $readSemaphore = null;
    private ?SysvSemaphore $writeSemaphore = null;

    /** Read permits currently held by this instance (this process). */
    private int $heldReadPermits = 0;

    /** Whether this instance (this process) holds the write semaphore. */
    private bool $holdsWrite = false;

    /** Number of contexts of this process currently holding a concurrent permit through this instance. */
    private int $concurrentHolders = 0;

    /** Nesting depth of the main flow (no fiber) inside synchronized blocks. */
    private int $mainDepth = 0;

    /** @var SplObjectStorage<Fiber, int> Nesting depth of each fiber inside synchronized blocks. */
    private SplObjectStorage $fiberDepths;

    /**
     * @param string $name Name shared by every process using the lock (the SysV keys derive from it)
     * @param int $maxPermits Maximum simultaneous concurrent permits (1 to 32767)
     * @param int $permissions Permissions of the semaphore set when this process creates it
     * @throws RuntimeException When ext-sysvsem is not available or the semaphores cannot be obtained
     * @throws InvalidArgumentException When the name is empty or $maxPermits is out of range
     */
    public function __construct(
        string $name,
        int $maxPermits = SemaphoreSynchronizer::MAX_NUMBER_OF_CONCURRENT_PERMITS,
        int $permissions = 0666
    ) {
        if (!self::isSupported()) {
            throw new RuntimeException(
                'SysVSemaphoreSynchronizer requires the ext-sysvsem extension (not available on Windows nor in builds '
                . 'without it). Use SemaphoreSynchronizer for intra-process locking, or FileLockSynchronizer to exclude '
                . 'between processes without SysV IPC.'
            );
        }
        if ($name === '') {
            throw new InvalidArgumentException('The synchronizer name cannot be empty: it identifies the lock between processes.');
        }
        if ($maxPermits < 1 || $maxPermits > self::MAX_SYSV_PERMITS) {
            throw new InvalidArgumentException(sprintf(
                'The number of concurrent permits must be between 1 and %d (SysV SEMVMX), %d given.',
                self::MAX_SYSV_PERMITS,
                $maxPermits
            ));
        }

        $this->name = $name;
        $this->maxPermits = $maxPermits;
        $this->readKey = self::keyFor($name, 'read');
        $this->writeKey = self::keyFor($name, 'write');
        $this->fiberDepths = new SplObjectStorage();

        $read = sem_get($this->readKey, $maxPermits, $permissions, true);
        $write = sem_get($this->writeKey, 1, $permissions, true);
        if ($read === false || $write === false) {
            throw new RuntimeException(sprintf(
                'Unable to obtain the SysV semaphores of synchronizer "%s" (keys 0x%08x, 0x%08x).',
                $name,
                $this->readKey,
                $this->writeKey
            ));
        }
        $this->readSemaphore = $read;
        $this->writeSemaphore = $write;
    }

    /**
     * True when this runtime can create SysV semaphores (ext-sysvsem loaded).
     *
     * @return bool
     */
    public static function isSupported(): bool
    {
        return function_exists('sem_get');
    }

    /**
     * SysV key derived from a synchronizer name and a role (stable between processes and restarts).
     * Never 0, which is IPC_PRIVATE (a semaphore nobody else could find).
     *
     * @param string $name
     * @param string $role 'read' or 'write'
     * @return int
     */
    public static function keyFor(string $name, string $role): int
    {
        $key = crc32("antevemus.aspecification:{$name}:{$role}") & 0x7FFFFFFF;
        return $key === 0 ? 1 : $key;
    }

    /**
     * {@inheritdoc}
     */
    public function callConcurrently(callable $action): mixed
    {
        if ($this->currentDepth() > 0) {
            return $this->runNested($action);
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
        if ($this->currentDepth() > 0) {
            return $this->runNested($action);
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
     * Returns every permit this instance still holds (read permits and the write semaphore) to the
     * other processes. Blocks never need it (they release in their finally); it exists for an
     * owner that aborts a process flow, and __destruct calls it.
     *
     * @return void
     */
    public function release(): void
    {
        if ($this->readSemaphore !== null) {
            while ($this->heldReadPermits > 0) {
                @sem_release($this->readSemaphore);
                $this->heldReadPermits--;
            }
        }
        if ($this->holdsWrite && $this->writeSemaphore !== null) {
            @sem_release($this->writeSemaphore);
        }
        $this->holdsWrite = false;
        $this->heldReadPermits = 0;
        $this->concurrentHolders = 0;
    }

    /**
     * Deletes the semaphore set from the system (sem_remove). An act of the owner, once no process
     * uses the lock any more; afterwards this instance cannot be used.
     *
     * @return void
     */
    public function remove(): void
    {
        $this->release();
        if ($this->readSemaphore !== null) {
            @sem_remove($this->readSemaphore);
        }
        if ($this->writeSemaphore !== null) {
            @sem_remove($this->writeSemaphore);
        }
        $this->readSemaphore = null;
        $this->writeSemaphore = null;
    }

    /**
     * Returns the held permits when the instance goes away.
     */
    public function __destruct()
    {
        $this->release();
    }

    /**
     * Returns the name shared by the processes using this lock.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns the maximum number of concurrent permits.
     */
    public function getMaxPermits(): int
    {
        return $this->maxPermits;
    }

    /**
     * Returns the SysV key of the counting (read) semaphore.
     */
    public function getReadKey(): int
    {
        return $this->readKey;
    }

    /**
     * Returns the SysV key of the binary (write) semaphore.
     */
    public function getWriteKey(): int
    {
        return $this->writeKey;
    }

    /**
     * Indicates whether the current execution context already holds a permit, in either mode.
     */
    public function hasAcquiredPermit(): bool
    {
        return $this->currentDepth() > 0;
    }

    /**
     * Indicates whether this process holds the exclusive lock through this instance.
     */
    public function isExclusiveLocked(): bool
    {
        return $this->holdsWrite;
    }

    ///////////////////////////////////////////////////////////////////////////
    // Permits
    ///////////////////////////////////////////////////////////////////////////

    /**
     * Takes one read permit, passing through the write semaphore first (writer preference).
     *
     * @throws RuntimeException When another context of this process holds the exclusive lock
     */
    private function acquireConcurrentPermit(): void
    {
        $this->assertUsable();
        if ($this->holdsWrite) {
            throw new RuntimeException(sprintf(
                'Exclusive lock "%s" is held by another execution context of this process: %s would wait for itself, '
                . 'so the concurrent request is refused instead.',
                $this->name,
                $this->describeCurrentContext()
            ));
        }

        $this->acquire($this->writeSemaphore);
        try {
            $this->acquire($this->readSemaphore);
            $this->heldReadPermits++;
            $this->concurrentHolders++;
        } finally {
            sem_release($this->writeSemaphore);
        }
    }

    /**
     * Returns one read permit.
     */
    private function releaseConcurrentPermit(): void
    {
        if ($this->heldReadPermits > 0 && $this->readSemaphore !== null) {
            sem_release($this->readSemaphore);
            $this->heldReadPermits--;
        }
        $this->concurrentHolders = max(0, $this->concurrentHolders - 1);
    }

    /**
     * Takes the write semaphore, then drains every read permit (waits for the readers of every
     * process to finish).
     *
     * @throws RuntimeException When another context of this process holds a permit
     */
    private function acquireExclusiveLock(): void
    {
        $this->assertUsable();
        if ($this->holdsWrite || $this->concurrentHolders > 0) {
            throw new RuntimeException(sprintf(
                'Synchronizer "%s" is held by another execution context of this process (%s): %s would wait for '
                . 'itself, so the exclusive request is refused instead.',
                $this->name,
                $this->holdsWrite ? 'exclusive lock' : $this->concurrentHolders . ' concurrent holder(s)',
                $this->describeCurrentContext()
            ));
        }

        $this->acquire($this->writeSemaphore);
        $this->holdsWrite = true;

        try {
            for ($i = 0; $i < $this->maxPermits; $i++) {
                $this->acquire($this->readSemaphore);
                $this->heldReadPermits++;
            }
        } catch (\Throwable $e) {
            $this->release();
            throw $e;
        }
    }

    /**
     * Returns every read permit, then the write semaphore.
     */
    private function releaseExclusiveLock(): void
    {
        $this->release();
    }

    /**
     * Blocking acquisition of one unit of a semaphore.
     *
     * @throws RuntimeException When the kernel refuses the operation (set removed, permissions)
     */
    private function acquire(?SysvSemaphore $semaphore): void
    {
        if ($semaphore === null || !@sem_acquire($semaphore)) {
            throw new RuntimeException(sprintf('Unable to acquire the SysV semaphore of synchronizer "%s".', $this->name));
        }
    }

    /**
     * @throws RuntimeException When remove() was called
     */
    private function assertUsable(): void
    {
        if ($this->readSemaphore === null || $this->writeSemaphore === null) {
            throw new RuntimeException(sprintf('Synchronizer "%s" was removed and can no longer be used.', $this->name));
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // Execution context (reentrancy)
    ///////////////////////////////////////////////////////////////////////////

    /**
     * @template R
     * @param callable(): R $action
     * @return R
     */
    private function runNested(callable $action): mixed
    {
        $this->enter();
        try {
            return $action();
        } finally {
            $this->leave();
        }
    }

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
}
