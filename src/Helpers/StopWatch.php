<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Helpers;

use Antevemus\ASpecification\Contracts\Helpers\IStopWatch;
use Stringable;

/**
 * StopWatch - High-Precision Stopwatch for Benchmarking and Latency Measurement
 *
 * Utilizes PHP's high-resolution monotonic clock (hrtime) with nanosecond precision,
 * enabling precise measurement of repository queries, partitioning traversal, and specification evaluation.
 *
 * State machine and accounting follow net.sourceforge.domian.util.StopWatch (Domian, Copyright 2006-2010
 * the original author or authors, Apache License 2.0; see THIRD_PARTY_NOTICES.md), as fixed by its
 * StopWatchTest:
 * - start() in READY starts; in STARTED it is idempotent (does NOT restart the count);
 *   in STOPPED it RESUMES: the interval already run is kept and a new one begins.
 * - stop() in STARTED suspends the count; in READY and STOPPED it is idempotent.
 * - getElapsedTime() is the sum of all run intervals (0 in READY; still growing in STARTED).
 * - getLapTime() / lap() measure only while STARTED; in READY and STOPPED they return 0 and record nothing.
 * - reset() goes back to READY and discards the intervals and the laps.
 * - print() formats with plain flooring: "N s M ms", "N ms" or "N us".
 *
 * Features:
 * - Nanosecond resolution measurement via hrtime(true)
 * - Safe state machine (READY, STARTED, STOPPED) with resume on start() after stop()
 * - Intermediary lap timing recording and history
 * - Automatic human-readable time formatting (ns, µs, ms, s) plus the Domian "N s M ms" format
 *
 * @version    1.4.4
 * @package    Antevemus\ASpecification
 * @subpackage Helpers
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class StopWatch implements IStopWatch, Stringable
{
    private string $state = self::STATE_READY;
    private int $startTime = 0;
    private int $stopTime = 0;
    private int $lastLapTime = 0;

    /**
     * Run intervals (nanoseconds) closed by stop() and followed by a start(): Java elapsedTimeIntervalList.
     *
     * @var array<int, int>
     */
    private array $elapsedTimeIntervals = [];

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
     *
     * READY: starts. STARTED: idempotent. STOPPED: resumes, keeping the time already run.
     */
    public function start(): self
    {
        switch ($this->state) {
            case self::STATE_READY:
                $this->startTime = hrtime(true);
                $this->lastLapTime = $this->startTime;
                $this->state = self::STATE_STARTED;
                break;

            case self::STATE_STARTED:
                // Idempotent: the running count is not restarted (StopWatchTest.startShouldBeIdempotent)
                break;

            case self::STATE_STOPPED:
                $this->elapsedTimeIntervals[] = $this->stopTime - $this->startTime;
                $this->startTime = hrtime(true);
                // The pause is not part of the next lap (deviation from the Java original, which keeps
                // the lap origin across the pause and therefore measures the pause into the next lap).
                $this->lastLapTime = $this->startTime;
                $this->state = self::STATE_STARTED;
                break;
        }
        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * STARTED: suspends the count. READY and STOPPED: idempotent.
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
        $this->elapsedTimeIntervals = [];
        $this->laps = [];
        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * Measures the time since the previous lap (or since start) and records it, only while STARTED;
     * in READY and STOPPED returns 0 and records nothing (Java getLapTime()).
     */
    public function lap(): int
    {
        if ($this->state !== self::STATE_STARTED) {
            return 0;
        }

        $now = hrtime(true);
        $duration = $now - $this->lastLapTime;
        $this->lastLapTime = $now;
        $this->laps[] = $duration;
        return $duration;
    }

    /**
     * Java name of lap(): lap time in nanoseconds, 0 unless STARTED.
     *
     * @return int
     */
    public function getLapTime(): int
    {
        return $this->lap();
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
     *
     * Sum of every run interval: the intervals closed by stop()/start() pairs plus the current one
     * (up to now while STARTED, up to the stop instant while STOPPED). 0 while READY.
     */
    public function getElapsedNanoseconds(): int
    {
        if ($this->state === self::STATE_READY) {
            return 0;
        }

        $end = $this->state === self::STATE_STARTED ? hrtime(true) : $this->stopTime;

        return ($end - $this->startTime) + array_sum($this->elapsedTimeIntervals);
    }

    /**
     * Java name of getElapsedNanoseconds(): total elapsed time in nanoseconds.
     *
     * @return int
     */
    public function getElapsedTime(): int
    {
        return $this->getElapsedNanoseconds();
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

    /**
     * Elapsed time in the Domian format (see print()).
     *
     * @return string
     */
    public function elapsedTimeToString(): string
    {
        return self::print($this->getElapsedTime());
    }

    /**
     * Takes a lap (see lap()) and returns it in the Domian format.
     *
     * @return string
     */
    public function lapTimeToString(): string
    {
        return self::print($this->getLapTime());
    }

    /**
     * Java toString(): the elapsed time in the Domian format.
     *
     * @return string
     */
    public function toString(): string
    {
        return $this->elapsedTimeToString();
    }

    /**
     * @return string Same as toString()
     */
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Formats a duration in nanoseconds the way Domian prints it. NB! Rounding mode is plain flooring:
     * above 1 s "N s M ms", above 1 ms "N ms", otherwise "N us".
     *
     * @param int $elapsedTime Duration in nanoseconds
     * @return string
     */
    public static function print(int $elapsedTime): string
    {
        if ($elapsedTime > 1_000_000) {
            if ($elapsedTime > 1_000_000_000) {
                return intdiv($elapsedTime, 1_000_000_000) . ' s ' . (intdiv($elapsedTime, 1_000_000) % 1000) . ' ms';
            }
            return intdiv($elapsedTime, 1_000_000) . ' ms';
        }
        return intdiv($elapsedTime, 1_000) . ' us';
    }
}
