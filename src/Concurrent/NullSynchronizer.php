<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Concurrent;

/**
 * NullSynchronizer - No-Op Implementation of Null Object Pattern for Synchronization
 *
 * Executes code blocks immediately without acquiring any locks or semaphores.
 * Ideal for isolated unit tests, benchmarks, strictly single-threaded environments,
 * or scenarios where concurrency guarantees are managed by external layers.
 *
 * Features:
 * - Direct, immediate execution of concurrent callables
 * - Direct, immediate execution of exclusive callables
 * - Zero overhead from filesystem I/O or operating system system-calls
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Concurrent
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class NullSynchronizer extends AbstractSynchronizer
{
    /**
     * {@inheritdoc}
     */
    public function callConcurrently(callable $action): mixed
    {
        return $action();
    }

    /**
     * {@inheritdoc}
     */
    public function callExclusively(callable $action): mixed
    {
        return $action();
    }
}
