<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Util;

use Closure;
use InvalidArgumentException;
use LogicException;
use OverflowException;

/**
 * UlidGenerator - Monotonic ULID Generator (Crockford Base32, 26 Characters)
 *
 * Generates Universally Unique Lexicographically Sortable Identifiers as specified by
 * https://github.com/ulid/spec: a 48-bit Unix timestamp in milliseconds followed by 80 bits of
 * cryptographically secure randomness, encoded as 26 Crockford base32 characters
 * (`0123456789ABCDEFGHJKMNPQRSTVWXYZ`, no I, L, O, U).
 *
 * Features:
 * - Lexicographic order equals generation order (time first, then randomness)
 * - Monotonic within the same millisecond: the random part of the previous ULID is incremented
 *   by one instead of drawn again, so identifiers generated in one millisecond stay sorted
 * - Clock going backwards keeps the last timestamp, so order never regresses within a generator
 * - Random-part overflow inside one millisecond fails loudly (OverflowException), as the spec says
 * - Injectable clock (milliseconds) for deterministic tests; a process-wide shared instance
 * - Validation and timestamp extraction helpers
 *
 * Requires 64-bit integers (48-bit timestamp).
 *
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Util
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class UlidGenerator
{
    /** Crockford base32 alphabet. */
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Largest timestamp a ULID can carry (2^48 - 1 milliseconds). */
    public const MAX_TIME = 0xFFFFFFFFFFFF;

    private static ?self $shared = null;

    /** @var Closure(): int|null */
    private readonly ?Closure $clock;

    private int $lastTime = -1;

    /** Last 80-bit random part, as 10 raw bytes. */
    private string $lastRandom = '';

    /**
     * @param (Closure(): int)|null $clock Returns the current Unix time in milliseconds; null = system clock
     */
    public function __construct(?Closure $clock = null)
    {
        if (PHP_INT_SIZE < 8) {
            throw new LogicException('UlidGenerator requires 64-bit integers.');
        }
        $this->clock = $clock;
    }

    /**
     * Returns the process-wide generator used by AbstractUlidEntity.
     *
     * @return self
     */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    /**
     * Generates a ULID with the process-wide generator.
     *
     * @return string 26-character Crockford base32 ULID
     */
    public static function ulid(): string
    {
        return self::shared()->generate();
    }

    /**
     * Generates the next ULID of this generator.
     *
     * @return string 26-character Crockford base32 ULID
     * @throws OverflowException When more than 2^80 ULIDs are requested within one millisecond
     * @throws LogicException When the entropy source is unavailable or the clock is out of range
     */
    public function generate(): string
    {
        $time = $this->now();
        if ($time < 0 || $time > self::MAX_TIME) {
            throw new LogicException(sprintf('ULID timestamp %d is outside the 48-bit range.', $time));
        }

        if ($time <= $this->lastTime) {
            // Same millisecond (or clock went backwards): keep the last timestamp, increment the random part.
            $time = $this->lastTime;
            $this->lastRandom = self::increment($this->lastRandom);
        } else {
            $this->lastTime = $time;
            $this->lastRandom = self::randomBytes(10);
        }

        return self::encodeTime($time) . self::encodeRandom($this->lastRandom);
    }

    /**
     * Checks whether a string is a canonical (uppercase) ULID.
     *
     * @param string $ulid
     * @return bool
     */
    public static function isValid(string $ulid): bool
    {
        return preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $ulid) === 1;
    }

    /**
     * Extracts the Unix timestamp in milliseconds carried by a ULID.
     *
     * @param string $ulid
     * @return int
     * @throws InvalidArgumentException When the string is not a ULID
     */
    public static function timestampOf(string $ulid): int
    {
        if (!self::isValid($ulid)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid ULID.', $ulid));
        }

        $time = 0;
        for ($i = 0; $i < 10; $i++) {
            $time = ($time << 5) | strpos(self::ALPHABET, $ulid[$i]);
        }

        return $time;
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : (int) floor(microtime(true) * 1000);
    }

    private static function encodeTime(int $time): string
    {
        $out = '';
        for ($i = 0; $i < 10; $i++) {
            $out = self::ALPHABET[$time & 31] . $out;
            $time >>= 5;
        }

        return $out;
    }

    /**
     * Encodes 10 raw bytes (80 bits) as 16 base32 characters, in two 40-bit halves.
     */
    private static function encodeRandom(string $bytes): string
    {
        $out = '';
        foreach ([substr($bytes, 0, 5), substr($bytes, 5, 5)] as $half) {
            $value = 0;
            for ($i = 0; $i < 5; $i++) {
                $value = ($value << 8) | ord($half[$i]);
            }
            $chunk = '';
            for ($i = 0; $i < 8; $i++) {
                $chunk = self::ALPHABET[$value & 31] . $chunk;
                $value >>= 5;
            }
            $out .= $chunk;
        }

        return $out;
    }

    /**
     * Adds one to a big-endian byte string.
     *
     * @throws OverflowException When every byte is 0xFF
     */
    private static function increment(string $bytes): string
    {
        for ($i = strlen($bytes) - 1; $i >= 0; $i--) {
            $byte = ord($bytes[$i]);
            if ($byte < 255) {
                $bytes[$i] = chr($byte + 1);
                return $bytes;
            }
            $bytes[$i] = "\x00";
        }

        throw new OverflowException('ULID random component overflowed within the same millisecond.');
    }

    private static function randomBytes(int $length): string
    {
        try {
            return random_bytes($length);
        } catch (\Exception $e) {
            throw new LogicException('Catastrophic failure in PHP native RNG source.', 0, $e);
        }
    }
}
