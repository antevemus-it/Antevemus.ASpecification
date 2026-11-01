<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Resolution\RetryAfter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RetryAfterTest - The reader of `retry-after-ms` and `Retry-After` (2.12.0, forward 059, D1 and RN-05).
 *
 * The forms of RFC 9110 (delay-seconds; HTTP-date as IMF-fixdate, RFC 850 and asctime) and the
 * `retry-after-ms` of OpenAI and Azure, read strictly: by property over random delays, random instants
 * written in the three date formats and random noise (fixed seeds, so a failure reproduces), plus the
 * boundaries and the forms a lenient reader (`is_numeric`, `strtotime`) would take by mistake.
 *
 * @version    2.12.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class RetryAfterTest extends TestCase
{
    private const SEED = 20261101;

    /** 2026-11-01 12:00:00 UTC. */
    private const NOW = 1793534400;

    public function testTheExampleOfRfc9110InTheThreeFormats(): void
    {
        // RFC 9110, section 5.6.7: the same instant in the three formats
        $instant = 784111777;
        self::assertSame($instant, RetryAfter::httpDate('Sun, 06 Nov 1994 08:49:37 GMT'));
        self::assertSame($instant, RetryAfter::httpDate('Sunday, 06-Nov-94 08:49:37 GMT'));
        self::assertSame($instant, RetryAfter::httpDate('Sun Nov  6 08:49:37 1994'));
        self::assertSame(gmmktime(8, 49, 37, 11, 16, 1994), RetryAfter::httpDate('Wed Nov 16 08:49:37 1994'), 'asctime with a two-digit day');
        self::assertSame($instant, RetryAfter::httpDate("  Sun, 06 Nov 1994 08:49:37 GMT \t"), 'surrounding spaces ignored');
        // Second 60 (a leap second) is the first second of the next minute; 61 does not exist
        self::assertSame(gmmktime(0, 0, 0, 1, 1, 2017), RetryAfter::httpDate('Sat, 31 Dec 2016 23:59:60 GMT'));
        self::assertNull(RetryAfter::httpDate('Sat, 31 Dec 2016 23:59:61 GMT'));
        // Every month by its name
        foreach (['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] as $i => $month) {
            $at = gmmktime(10, 0, 0, $i + 1, 15, 2026);
            self::assertSame($at, RetryAfter::httpDate(gmdate('D', $at) . ", 15 {$month} 2026 10:00:00 GMT"), $month);
        }
    }

    public function testDelaySeconds(): void
    {
        self::assertSame(120.0, RetryAfter::fromHeaders(['retry-after' => '120']));
        self::assertSame(0.0, RetryAfter::fromHeaders(['retry-after' => '0']));
        self::assertSame(7.0, RetryAfter::fromHeaders(['retry-after' => '007']));
        self::assertSame(5.0, RetryAfter::fromHeaders(['Retry-After' => ' 5 ']), 'name without case, value trimmed');
        self::assertSame(5.0, RetryAfter::fromHeaders(['RETRY-AFTER' => '5']));
    }

    public function testRetryAfterMsComesFirst(): void
    {
        self::assertSame(0.25, RetryAfter::fromHeaders(['retry-after-ms' => '250', 'retry-after' => '9']));
        self::assertSame(0.0125, RetryAfter::fromHeaders(['Retry-After-Ms' => '12.5']));
        self::assertSame(0.0, RetryAfter::fromHeaders(['retry-after-ms' => '0']));
        // An invalid retry-after-ms falls back to retry-after
        self::assertSame(9.0, RetryAfter::fromHeaders(['retry-after-ms' => 'soon', 'retry-after' => '9']));
        self::assertSame(9.0, RetryAfter::fromHeaders(['retry-after-ms' => '-1', 'retry-after' => '9']));
        self::assertNull(RetryAfter::fromHeaders(['retry-after-ms' => '1e3']));
    }

    public function testHttpDateIsMeasuredFromTheDateOfTheResponse(): void
    {
        $headers = ['date' => 'Sun, 01 Nov 2026 12:00:00 GMT', 'retry-after' => 'Sun, 01 Nov 2026 12:00:30 GMT'];
        self::assertSame(30.0, RetryAfter::fromHeaders($headers), 'the local clock plays no part');
        self::assertSame(30.0, RetryAfter::fromHeaders($headers, 0), 'whatever $now says');
        // Without a valid date: from $now
        self::assertSame(30.0, RetryAfter::fromHeaders(['retry-after' => 'Sun, 01 Nov 2026 12:00:30 GMT'], self::NOW));
        self::assertSame(30.0, RetryAfter::fromHeaders(['date' => 'yesterday', 'retry-after' => 'Sun, 01 Nov 2026 12:00:30 GMT'], self::NOW));
        // A date in the past is a delay of zero
        self::assertSame(0.0, RetryAfter::fromHeaders(['retry-after' => 'Sun, 01 Nov 2026 11:59:00 GMT'], self::NOW));
        self::assertSame(0.0, RetryAfter::fromHeaders(['retry-after' => 'Sun, 01 Nov 2026 12:00:00 GMT'], self::NOW));
        // Default $now: the current time
        $soon = gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT';
        $delay = RetryAfter::fromHeaders(['retry-after' => $soon]);
        self::assertNotNull($delay);
        self::assertGreaterThanOrEqual(3598.0, $delay);
        self::assertLessThanOrEqual(3600.0, $delay);
    }

    public function testRfc850YearMoreThanFiftyYearsAheadIsInThePreviousCentury(): void
    {
        $year = (int) gmdate('Y');
        // RFC 9110: a two-digit year that appears more than 50 years in the future is the most recent past year
        $expected = static function (int $twoDigits) use ($year): int {
            $candidate = $year - $year % 100 + $twoDigits;

            return $candidate > $year + 50 ? $candidate - 100 : $candidate;
        };
        foreach ([($year + 50) % 100, ($year + 51) % 100, $year % 100, 94, 0, 99] as $twoDigits) {
            $full = $expected($twoDigits);
            $text = sprintf('%s, 01-Jan-%02d 00:00:00 GMT', gmdate('l', gmmktime(0, 0, 0, 1, 1, $full)), $twoDigits);
            self::assertSame(gmmktime(0, 0, 0, 1, 1, $full), RetryAfter::httpDate($text), $text);
        }
        self::assertLessThanOrEqual($year + 50, $expected(($year + 50) % 100), 'fifty years ahead is never read in the future beyond fifty');
        self::assertLessThan($year, $expected(($year + 51) % 100), 'fifty-one years ahead is in the past');
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function headersWithoutADelay(): iterable
    {
        yield 'no header' => [[]];
        yield 'other headers' => [['content-type' => 'application/json', 'x-ratelimit-reset' => '10']];
        yield 'empty' => [['retry-after' => '']];
        yield 'spaces' => [['retry-after' => '   ']];
        yield 'negative' => [['retry-after' => '-5']];
        yield 'plus sign' => [['retry-after' => '+5']];
        yield 'fraction (not delay-seconds)' => [['retry-after' => '1.5']];
        yield 'exponent' => [['retry-after' => '1e3']];
        yield 'hex' => [['retry-after' => '0x10']];
        yield 'two headers joined' => [['retry-after' => '120, 60']];
        yield 'two dates joined' => [['retry-after' => 'Sun, 01 Nov 2026 12:00:30 GMT, Sun, 01 Nov 2026 12:00:40 GMT']];
        yield 'relative words' => [['retry-after' => 'tomorrow']];
        yield 'strtotime offset' => [['retry-after' => '+1 week']];
        yield 'ISO 8601' => [['retry-after' => '2026-11-01T12:00:30Z']];
        yield 'not GMT' => [['retry-after' => 'Sun, 01 Nov 2026 12:00:30 UTC']];
        yield 'zone offset' => [['retry-after' => 'Sun, 01 Nov 2026 12:00:30 +0000']];
        yield 'lower-case month' => [['retry-after' => 'Sun, 01 nov 2026 12:00:30 GMT']];
        yield 'one-digit day in IMF' => [['retry-after' => 'Sun, 1 Nov 2026 12:00:30 GMT']];
        yield '31 February' => [['retry-after' => 'Mon, 31 Feb 2026 12:00:00 GMT']];
        yield 'hour 24' => [['retry-after' => 'Sun, 01 Nov 2026 24:00:00 GMT']];
        yield 'minute 60' => [['retry-after' => 'Sun, 01 Nov 2026 12:60:00 GMT']];
        yield 'second 61' => [['retry-after' => 'Sun, 01 Nov 2026 12:00:61 GMT']];
        yield 'short day name in RFC 850' => [['retry-after' => 'Sun, 06-Nov-94 08:49:37 GMT']];
        yield 'long day name in IMF' => [['retry-after' => 'Sunday, 06 Nov 1994 08:49:37 GMT']];
        yield 'trailing text after asctime' => [['retry-after' => 'Sun Nov 06 08:49:37 1994 extra']];
        yield 'trailing text' => [['retry-after' => '120 seconds']];
        yield 'newline inside' => [['retry-after' => "12\n0"]];
        yield 'too large for a float' => [['retry-after' => str_repeat('9', 400)]];
        yield 'retry-after-ms too large and no fallback' => [['retry-after-ms' => str_repeat('9', 400)]];
        yield 'not a string' => [['retry-after' => 120]];
        yield 'array value' => [['retry-after' => ['120']]];
    }

    /** @param array<array-key, mixed> $headers */
    #[DataProvider('headersWithoutADelay')]
    public function testFormsThatAreNotADelay(array $headers): void
    {
        self::assertNull(RetryAfter::fromHeaders($headers, self::NOW));
    }

    /** Property: any delay-seconds and any retry-after-ms are read as written. */
    public function testRandomDelaysAreReadAsWritten(): void
    {
        mt_srand(self::SEED);
        for ($round = 0; $round < 500; $round++) {
            $seconds = mt_rand(0, 10_000_000);
            self::assertSame((float) $seconds, RetryAfter::fromHeaders(['retry-after' => (string) $seconds], self::NOW), "seconds {$seconds}");

            $milliseconds = mt_rand(0, 100_000_000) / 100;
            $text = rtrim(rtrim(sprintf('%.2f', $milliseconds), '0'), '.');
            self::assertSame((float) $text / 1000, RetryAfter::fromHeaders(['retry-after-ms' => $text, 'retry-after' => '1'], self::NOW), "ms {$text}");
        }
    }

    /** Property: any instant written in any of the three formats is read back, in delay and in Unix time. */
    public function testRandomInstantsInTheThreeFormats(): void
    {
        mt_srand(self::SEED + 1);
        $year = (int) gmdate('Y');
        for ($round = 0; $round < 600; $round++) {
            // RFC 850 has a two-digit year: keep the instant inside the window it can express
            $instant = mt_rand(gmmktime(0, 0, 0, 1, 1, $year - 49), gmmktime(23, 59, 59, 12, 31, $year + 49));
            $forms = [
                gmdate('D, d M Y H:i:s', $instant) . ' GMT',
                gmdate('l, d-M-y H:i:s', $instant) . ' GMT',
                sprintf('%s %2d %s', gmdate('D M', $instant), (int) gmdate('j', $instant), gmdate('H:i:s Y', $instant)),
            ];
            $from = $instant - mt_rand(0, 86400);
            foreach ($forms as $form) {
                self::assertSame($instant, RetryAfter::httpDate($form), $form);
                self::assertSame((float) ($instant - $from), RetryAfter::fromHeaders(['retry-after' => $form], $from), $form);
                self::assertSame(0.0, RetryAfter::fromHeaders(['retry-after' => $form], $instant + 1), "{$form} in the past");
            }
        }
    }

    /** Property: random noise never throws and never invents a negative or infinite delay. */
    public function testRandomNoiseNeverThrows(): void
    {
        mt_srand(self::SEED + 2);
        $alphabet = str_split("0123456789 ,.:-+eEGMTSunMonNovJanxyz\t\r\n/;=");
        for ($round = 0; $round < 3000; $round++) {
            $length = mt_rand(0, 40);
            $text = '';
            for ($i = 0; $i < $length; $i++) {
                $text .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            foreach (['retry-after', 'retry-after-ms', 'date'] as $name) {
                $delay = RetryAfter::fromHeaders([$name => $text, 'date' => $text], self::NOW);
                if ($delay !== null) {
                    self::assertTrue(is_finite($delay) && $delay >= 0.0, "'{$text}' as {$name}");
                }
            }
            self::assertTrue(RetryAfter::httpDate($text) === null || is_int(RetryAfter::httpDate($text)));
        }
    }
}
