<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Helpers;

use Antevemus\ASpecification\Contracts\Helpers\IStopWatch;

/**
 * StopWatch - High-Precision Stopwatch for Benchmarking and Latency Measurement
 *
 * Utilizes PHP's high-resolution monotonic clock (hrtime) with nanosecond precision,
 * enabling precise measurement of repository queries, partitioning traversal, and specification evaluation.
 *
 * Features:
 * - Nanosecond resolution measurement via hrtime(true)
 * - Safe state machine (READY, STARTED, STOPPED)
 * - Intermediary lap timing recording and history
 * - Automatic human-readable time formatting (ns, µs, ms, s)
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Helpers
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class StopWatch implements IStopWatch
{
    private string $state = self::STATE_READY;
    private int $startTime = 0;
    private int $stopTime = 0;
    private int $lastLapTime = 0;

    /**
     * @var array<int, int>
     */
    private array $laps = [];

    /**
     * Instantiate a new stopwatch. If $autoStart is true, starts timing immediately.
     *
     * @param bool $autoStart If true, starts counting upon instantiation
     */
    public function __construct(bool $autoStart = false)
    {
        if ($autoStart) {
            $this->start();
        }
    }

    /**
     * Static factory creating and starting stopwatch immediately.
     *
     * @return self
     */
    public static function createStarted(): self
    {
        return new self(true);
    }

    /**
     * {@inheritdoc}
     */
    public function start(): self
    {
        $now = hrtime(true);
        if ($this->state === self::STATE_STOPPED) {
            // Resuming or restarting count
            $this->startTime = $now;
        } else {
            $this->startTime = $now;
        }
        $this->lastLapTime = $now;
        $this->stopTime = 0;
        $this->state = self::STATE_STARTED;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): self
    {
        if ($this->state === self::STATE_STARTED) {
            $this->stopTime = hrtime(true);
            $this->state = self::STATE_STOPPED;
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): self
    {
        $this->state = self::STATE_READY;
        $this->startTime = 0;
        $this->stopTime = 0;
        $this->lastLapTime = 0;
        $this->laps = [];
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function lap(): int
    {
        $now = hrtime(true);
        $fromTime = ($this->lastLapTime > 0) ? $this->lastLapTime : $this->startTime;
        $duration = ($fromTime > 0) ? ($now - $fromTime) : 0;
        $this->laps[] = $duration;
        $this->lastLapTime = $now;
        return $duration;
    }

    /**
     * {@inheritdoc}
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * {@inheritdoc}
     */
    public function getElapsedNanoseconds(): int
    {
        if ($this->state === self::STATE_READY) {
            return 0;
        }

        if ($this->state === self::STATE_STARTED) {
            return hrtime(true) - $this->startTime;
        }

        return $this->stopTime - $this->startTime;
    }

    /**
     * {@inheritdoc}
     */
    public function getElapsedMilliseconds(): float
    {
        return $this->getElapsedNanoseconds() / 1_000_000.0;
    }

    /**
     * {@inheritdoc}
     */
    public function getElapsedSeconds(): float
    {
        return $this->getElapsedNanoseconds() / 1_000_000_000.0;
    }

    /**
     * {@inheritdoc}
     */
    public function getLaps(): array
    {
        return $this->laps;
    }

    /**
     * {@inheritdoc}
     */
    public function formatElapsed(): string
    {
        $nanos = $this->getElapsedNanoseconds();

        if ($nanos < 1_000) {
            return sprintf('%d ns', $nanos);
        }

        if ($nanos < 1_000_000) {
            return sprintf('%.2f µs', $nanos / 1_000.0);
        }

        if ($nanos < 1_000_000_000) {
            return sprintf('%.2f ms', $nanos / 1_000_000.0);
        }

        return sprintf('%.3f s', $nanos / 1_000_000_000.0);
    }
}
