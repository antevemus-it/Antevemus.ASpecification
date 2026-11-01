<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

/**
 * RetryAfter - Reads the Delay a Server Asks For Before the Next Request
 *
 * Reads the response headers of a failed call and answers the delay, in seconds, or null when the
 * response asks for none (or asks in a form this reader does not accept). It never throws: a malformed
 * header is a header that is not there.
 *
 * <code>
 * RetryAfter::fromHeaders(['retry-after' => '120']);                            // 120.0
 * RetryAfter::fromHeaders(['retry-after-ms' => '250']);                         // 0.25
 * RetryAfter::fromHeaders([
 *     'date'        => 'Sun, 01 Nov 2026 12:00:00 GMT',
 *     'retry-after' => 'Sun, 01 Nov 2026 12:00:30 GMT',
 * ]);                                                                           // 30.0
 * </code>
 *
 * Forms accepted, in this order:
 * 1. `retry-after-ms` (not standard; sent by OpenAI and Azure OpenAI): a non-negative decimal number of
 *    milliseconds, read first because it is more precise;
 * 2. `retry-after` (RFC 9110, section 10.2.3) as `delay-seconds`: digits only (no sign, no exponent, no
 *    fraction);
 * 3. `retry-after` as an `HTTP-date` in one of the three formats a recipient must accept (RFC 9110,
 *    section 5.6.7): IMF-fixdate (`Sun, 06 Nov 1994 08:49:37 GMT`), the obsolete RFC 850 form
 *    (`Sunday, 06-Nov-94 08:49:37 GMT`) and the asctime form (`Sun Nov  6 08:49:37 1994`), read strictly in
 *    GMT (never `strtotime()`, which takes `tomorrow` or `+1 week`). The delay is measured from the `date`
 *    header of the same response when it is a valid HTTP-date, so a local clock that is off does not change
 *    it; otherwise from `$now` (Unix time, default `time()`). A date in the past is a delay of 0.
 *
 * Header names are compared without case; surrounding spaces of a value are ignored. Two `retry-after`
 * headers joined by the transport (`120, 60`) are not a valid value, so the answer is null.
 *
 * @version    2.12.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class RetryAfter
{
    private const DAYS = 'Mon|Tue|Wed|Thu|Fri|Sat|Sun';
    private const LONG_DAYS = 'Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday';
    private const MONTHS = 'Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec';
    private const MONTH_NUMBERS = [
        'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
        'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12,
    ];

    private function __construct()
    {
    }

    /**
     * The delay the response asks for, in seconds, or null.
     *
     * @param array<array-key, mixed> $headers Response headers by name (any case)
     * @param int|null $now Unix time the delay of an HTTP-date is measured from when the response has no valid `date` header (default `time()`)
     */
    public static function fromHeaders(array $headers, ?int $now = null): ?float
    {
        $byName = [];
        foreach ($headers as $name => $value) {
            if (is_string($value)) {
                $byName[strtolower((string) $name)] = trim($value);
            }
        }

        if (isset($byName['retry-after-ms'])) {
            $milliseconds = self::milliseconds($byName['retry-after-ms']);
            if ($milliseconds !== null) {
                return $milliseconds / 1000;
            }
        }

        $value = $byName['retry-after'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^[0-9]+$/D', $value) === 1) {
            $seconds = (float) $value;

            return is_finite($seconds) ? $seconds : null;
        }

        $at = self::httpDate($value);
        if ($at === null) {
            return null;
        }
        $from = isset($byName['date']) ? self::httpDate($byName['date']) : null;

        return (float) max(0, $at - ($from ?? $now ?? time()));
    }

    /**
     * Unix time of an HTTP-date (RFC 9110, section 5.6.7), or null when the text is not one.
     *
     * The day name must be one of the format but is not checked against the date (as most recipients do);
     * the date and the time must exist (no 31 Feb, no hour 24). A two-digit year of the RFC 850 form that
     * would be more than 50 years ahead of the current year is read in the previous century (RFC 9110).
     */
    public static function httpDate(string $value): ?int
    {
        $value = trim($value);
        $days = self::DAYS;
        $months = self::MONTHS;
        $time = '([0-9]{2}):([0-9]{2}):([0-9]{2})';

        if (preg_match("/^(?:{$days}), ([0-9]{2}) ({$months}) ([0-9]{4}) {$time} GMT$/D", $value, $m) === 1) {
            return self::instant((int) $m[3], $m[2], (int) $m[1], (int) $m[4], (int) $m[5], (int) $m[6]);
        }
        if (preg_match('/^(?:' . self::LONG_DAYS . "), ([0-9]{2})-({$months})-([0-9]{2}) {$time} GMT$/D", $value, $m) === 1) {
            $currentYear = (int) gmdate('Y');
            $year = $currentYear - $currentYear % 100 + (int) $m[3];
            if ($year > $currentYear + 50) {
                $year -= 100;
            }

            return self::instant($year, $m[2], (int) $m[1], (int) $m[4], (int) $m[5], (int) $m[6]);
        }
        if (preg_match("/^(?:{$days}) ({$months}) ( [1-9]|[0-9]{2}) {$time} ([0-9]{4})$/D", $value, $m) === 1) {
            return self::instant((int) $m[6], $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5]);
        }

        return null;
    }

    /**
     * Unix time of a GMT date and time, or null when it does not exist (second 60, a leap second, is the
     * first second of the next minute, as in RFC 5322).
     */
    private static function instant(int $year, string $month, int $day, int $hour, int $minute, int $second): ?int
    {
        $monthNumber = self::MONTH_NUMBERS[$month];
        if (!checkdate($monthNumber, $day, $year) || $hour > 23 || $minute > 59 || $second > 60) {
            return null;
        }

        return gmmktime($hour, $minute, $second, $monthNumber, $day, $year);
    }

    /**
     * A non-negative decimal number of milliseconds, or null.
     */
    private static function milliseconds(string $value): ?float
    {
        if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $value) !== 1) {
            return null;
        }
        $milliseconds = (float) $value;

        return is_finite($milliseconds) ? $milliseconds : null;
    }
}
