<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Util;

use Closure;
use InvalidArgumentException;
use LogicException;

/**
 * UuidV7Generator - Time-Ordered UUID Version 7 Generator (RFC 9562)
 *
 * Generates RFC 9562 §5.7 UUIDv7 strings: 48-bit big-endian Unix timestamp in milliseconds,
 * 4-bit version `0111`, 12 bits `rand_a`, 2-bit variant `10`, 62 bits `rand_b`, rendered in the
 * canonical lowercase 8-4-4-4-12 hexadecimal form.
 *
 * Features:
 * - Lexicographic order of the string equals generation order
 * - Monotonic within the same millisecond (RFC 9562 §6.2, method 1 with a 74-bit counter):
 *   `rand_a` and `rand_b` are seeded randomly at each new millisecond (with the most significant
 *   counter bit cleared to leave room for increments) and incremented by one for every further
 *   UUID generated in that millisecond
 * - Counter rollover advances the timestamp by one millisecond and reseeds (RFC 9562 §6.2),
 *   so generation never fails; a clock going backwards keeps the last timestamp
 * - Injectable clock (milliseconds) for deterministic tests; a process-wide shared instance
 * - Validation and timestamp extraction helpers
 *
 * Requires 64-bit integers.
 *
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Util
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class UuidV7Generator
{
    /** Largest timestamp a UUIDv7 can carry (2^48 - 1 milliseconds). */
    public const MAX_TIME = 0xFFFFFFFFFFFF;

    private const RAND_A_MAX = 0xFFF;

    private const RAND_B_MAX = 0x3FFFFFFFFFFFFFFF;

    private static ?self $shared = null;

    /** @var Closure(): int|null */
    private readonly ?Closure $clock;

    private int $lastTime = -1;

    private int $randA = 0;

    private int $randB = 0;

    /**
     * @param (Closure(): int)|null $clock Returns the current Unix time in milliseconds; null = system clock
     */
    public function __construct(?Closure $clock = null)
    {
        if (PHP_INT_SIZE < 8) {
            throw new LogicException('UuidV7Generator requires 64-bit integers.');
        }
        $this->clock = $clock;
    }

    /**
     * Returns the process-wide generator used by AbstractUuidV7Entity.
     *
     * @return self
     */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    /**
     * Generates a UUIDv7 with the process-wide generator.
     *
     * @return string Canonical lowercase UUID string
     */
    public static function uuid(): string
    {
        return self::shared()->generate();
    }

    /**
     * Generates the next UUIDv7 of this generator.
     *
     * @return string Canonical lowercase UUID string
     * @throws LogicException When the entropy source is unavailable or the clock is out of range
     */
    public function generate(): string
    {
        $time = $this->now();
        if ($time < 0 || $time > self::MAX_TIME) {
            throw new LogicException(sprintf('UUIDv7 timestamp %d is outside the 48-bit range.', $time));
        }

        if ($time <= $this->lastTime) {
            // Same millisecond (or clock went backwards): keep the last timestamp, increment the counter.
            $time = $this->lastTime;
            if ($this->randB < self::RAND_B_MAX) {
                $this->randB++;
            } elseif ($this->randA < self::RAND_A_MAX) {
                $this->randB = 0;
                $this->randA++;
            } else {
                // Counter rollover: move the timestamp ahead by one millisecond and reseed.
                $time = $this->lastTime + 1;
                if ($time > self::MAX_TIME) {
                    throw new LogicException('UUIDv7 timestamp overflowed the 48-bit range.');
                }
                $this->seed($time);
            }
        } else {
            $this->seed($time);
        }

        $bytes = substr(pack('J', $time), 2)
            . pack('n', 0x7000 | $this->randA)
            . pack('J', PHP_INT_MIN | $this->randB);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * Checks whether a string is a canonical (lowercase) RFC 9562 UUIDv7.
     *
     * @param string $uuid
     * @return bool
     */
    public static function isValid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid) === 1;
    }

    /**
     * Extracts the Unix timestamp in milliseconds carried by a UUIDv7.
     *
     * @param string $uuid
     * @return int
     * @throws InvalidArgumentException When the string is not a UUIDv7
     */
    public static function timestampOf(string $uuid): int
    {
        if (!self::isValid($uuid)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid UUIDv7.', $uuid));
        }

        return (int) hexdec(substr($uuid, 0, 8) . substr($uuid, 9, 4));
    }

    private function seed(int $time): void
    {
        $this->lastTime = $time;
        try {
            $random = random_bytes(10);
        } catch (\Exception $e) {
            throw new LogicException('Catastrophic failure in PHP native RNG source.', 0, $e);
        }
        // rand_a: 12 bits with the most significant bit cleared, leaving room for the counter.
        $this->randA = ((ord($random[0]) << 8) | ord($random[1])) & 0x7FF;
        // rand_b: 62 random bits.
        $this->randB = unpack('J', substr($random, 2, 8))[1] & self::RAND_B_MAX;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : (int) floor(microtime(true) * 1000);
    }
}
