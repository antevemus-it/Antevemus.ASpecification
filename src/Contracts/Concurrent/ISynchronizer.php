<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Concurrent;

/**
 * ISynchronizer - Contract for Concurrent and Exclusive Execution Synchronization
 *
 * Defines concurrency control operations based on the Read/Write Lock pattern.
 * Enables code blocks (callables) to be executed in CONCURRENT mode
 * (shared across multiple simultaneous reads) or in EXCLUSIVE mode
 * (atomic, isolated access for critical state mutations/writes).
 *
 * Features:
 * - Void concurrent execution (runConcurrently) and value-returning (callConcurrently)
 * - Void exclusive execution (runExclusively) and value-returning (callExclusively)
 * - Guaranteed release of locks/permits via protected execution blocks
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Concurrent
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISynchronizer
{
    /**
     * Execute an action concurrently (shared mode).
     *
     * Ideal for parallel reads. Does not block other concurrent operations,
     * but yields to pending or active exclusive operations.
     *
     * @param callable(): void $action Action to execute
     * @return void
     */
    public function runConcurrently(callable $action): void;

    /**
     * Execute a value-returning action concurrently (shared mode).
     *
     * @template T
     * @param callable(): T $action Action to execute
     * @return T Result returned by the action
     */
    public function callConcurrently(callable $action): mixed;

    /**
     * Execute an action exclusively (isolated mode).
     *
     * Awaits completion of all currently running concurrent operations,
     * blocks subsequent concurrent and exclusive operations, and executes atomically.
     *
     * @param callable(): void $action Action to execute
     * @return void
     */
    public function runExclusively(callable $action): void;

    /**
     * Execute a value-returning action exclusively (isolated mode).
     *
     * @template T
     * @param callable(): T $action Action to execute
     * @return T Result returned by the action
     */
    public function callExclusively(callable $action): mixed;
}
