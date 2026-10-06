<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Concurrent;

use Antevemus\ASpecification\Contracts\Concurrent\ISynchronizer;

/**
 * AbstractSynchronizer - Abstract Base Class for Concurrency Synchronizers
 *
 * Provides default implementation for void execution operations (runConcurrently and runExclusively)
 * by delegating directly to their value-returning counterparts (callConcurrently and callExclusively).
 * Subclasses only need to implement the two primitive operations for acquiring and releasing locks.
 *
 * Features:
 * - Transparent delegation of runConcurrently to callConcurrently
 * - Transparent delegation of runExclusively to callExclusively
 * - Extensible base for synchronization strategies across memory, file locks, or counting semaphores
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Concurrent
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractSynchronizer implements ISynchronizer
{
    /**
     * {@inheritdoc}
     */
    public function runConcurrently(callable $action): void
    {
        $this->callConcurrently(static function () use ($action): null {
            $action();
            return null;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function runExclusively(callable $action): void
    {
        $this->callExclusively(static function () use ($action): null {
            $action();
            return null;
        });
    }
}
