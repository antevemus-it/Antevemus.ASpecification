<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Helpers;

/**
 * IStopWatch - Contract for High-Precision Stopwatches and Latency Measurement
 *
 * Defines high-resolution stopwatch interface for benchmarking query performance,
 * partitioning traversal, and specification evaluation operations.
 *
 * Features:
 * - State management (READY, STARTED, STOPPED)
 * - Intermediary lap timing recording
 * - Retrieval of elapsed time in nanoseconds, milliseconds, and seconds
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Helpers
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IStopWatch
{
    public const STATE_READY = 'READY';
    public const STATE_STARTED = 'STARTED';
    public const STATE_STOPPED = 'STOPPED';

    /**
     * Start or restart the stopwatch.
     *
     * @return self
     */
    public function start(): self;

    /**
     * Stop the stopwatch.
     *
     * @return self
     */
    public function stop(): self;

    /**
     * Reset the stopwatch back to the initial READY state.
     *
     * @return self
     */
    public function reset(): self;

    /**
     * Record an intermediary lap and return duration in nanoseconds since previous lap.
     *
     * @return int
     */
    public function lap(): int;

    /**
     * Return current state of the stopwatch.
     *
     * @return string
     */
    public function getState(): string;

    /**
     * Return total elapsed time in nanoseconds.
     *
     * @return int
     */
    public function getElapsedNanoseconds(): int;

    /**
     * Return total elapsed time in milliseconds.
     *
     * @return float
     */
    public function getElapsedMilliseconds(): float;

    /**
     * Return total elapsed time in seconds.
     *
     * @return float
     */
    public function getElapsedSeconds(): float;

    /**
     * Return all recorded laps in nanoseconds.
     *
     * @return array<int, int>
     */
    public function getLaps(): array;

    /**
     * Return formatted human-readable elapsed time string (e.g. '12.34 ms', '1.50 s').
     *
     * @return string
     */
    public function formatElapsed(): string;
}
