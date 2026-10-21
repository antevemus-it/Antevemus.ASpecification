<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Calendar;

use Countable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * HolidaySet - The Holidays a Business Calendar Skips
 *
 * Immutable set of local dates: single dates (`2026-02-16`, a Carnival Monday) and recurring ones,
 * a day and month that repeat every year **from a given year on** (`11-20` from 2024: November
 * 20th of 2024, 2025, 2026...). The library ships no holiday at all: which days are holidays is
 * the policy of the application (a national list, a bank calendar, a state or a city), usually a
 * table edited by a screen; fromRows() builds the set from such rows, where a recurring row
 * repeats the day and month of its date from the year of its date on.
 *
 * Dates are local: contains() reads the date of the instant it receives in that instant's own
 * time zone, so the calendars pass it the day in their time zone.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Calendar
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class HolidaySet implements Countable
{
    /** Texts read as a true recurring flag (compared lowercased, trimmed). */
    private const TRUE_TEXTS = ['1', 't', 'true', 'y', 'yes'];

    /** Texts read as a false recurring flag (compared lowercased, trimmed). */
    private const FALSE_TEXTS = ['0', 'f', 'false', 'n', 'no', ''];

    /** @var array<string, string> `Y-m-d` => `Y-m-d` */
    private array $dates = [];

    /** @var array<string, int> `m-d` => first year it applies */
    private array $recurring = [];

    /**
     * @param iterable<string|DateTimeInterface> $dates Single dates (`Y-m-d` texts or instants, read in their own time zone)
     */
    public function __construct(iterable $dates = [])
    {
        foreach ($dates as $date) {
            $day = self::dateOf($date);
            $this->dates[$day] = $day;
        }
        ksort($this->dates);
    }

    /** A set without holidays. */
    public static function none(): self
    {
        return new self();
    }

    /**
     * Builds the set from table rows (arrays or objects): each row has a date and, optionally, a
     * recurring flag; a recurring row repeats its day and month every year from its own year on.
     *
     * @param iterable<array<string, mixed>|object> $rows Rows read from the application's table
     * @param string $dateField Field of the date (`Y-m-d` text, a text starting with it, or a DateTimeInterface)
     * @param string $recurringField Field of the recurring flag (bool, 0/1, or t/f, true/false, y/n, yes/no texts; missing or null = single date)
     * @return self
     * @throws InvalidArgumentException When a row has no valid date or an unreadable flag
     */
    public static function fromRows(iterable $rows, string $dateField = 'date', string $recurringField = 'recurring'): self
    {
        $set = new self();
        foreach ($rows as $row) {
            $values = is_object($row) ? get_object_vars($row) : $row;
            if (!is_array($values) || !array_key_exists($dateField, $values)) {
                throw new InvalidArgumentException(sprintf('Every holiday row needs the field "%s".', $dateField));
            }
            $date = $values[$dateField];
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}(?=$|[ T])/', $date, $match) === 1) {
                $date = $match[0];
            }
            if (!is_string($date) && !$date instanceof DateTimeInterface) {
                throw new InvalidArgumentException(sprintf('The holiday field "%s" must be a Y-m-d text or a DateTimeInterface.', $dateField));
            }
            $day = self::dateOf($date);
            if (self::flag($values[$recurringField] ?? null)) {
                [$year, $month, $dayOfMonth] = array_map('intval', explode('-', $day));
                $set = $set->withRecurring($month, $dayOfMonth, $year);
            } else {
                $set = $set->withDate($day);
            }
        }

        return $set;
    }

    /**
     * A copy with one more single date.
     *
     * @param string|DateTimeInterface $date `Y-m-d` text, or an instant read in its own time zone
     */
    public function withDate(string|DateTimeInterface $date): self
    {
        $day = self::dateOf($date);
        $copy = clone $this;
        $copy->dates[$day] = $day;
        ksort($copy->dates);

        return $copy;
    }

    /**
     * A copy with a day and month that repeat every year from $fromYear on (February 29th only in
     * leap years). Declaring the same day and month twice keeps the earliest year.
     *
     * @throws InvalidArgumentException When the day and month do not exist
     */
    public function withRecurring(int $month, int $day, int $fromYear): self
    {
        if (!checkdate($month, $day, 2000)) {
            throw new InvalidArgumentException(sprintf('%02d-%02d is not a day of the year.', $month, $day));
        }
        $key = sprintf('%02d-%02d', $month, $day);
        $copy = clone $this;
        $copy->recurring[$key] = min($fromYear, $copy->recurring[$key] ?? $fromYear);
        ksort($copy->recurring);

        return $copy;
    }

    /** True when the local date of $day (in its own time zone) is a holiday. */
    public function contains(DateTimeInterface $day): bool
    {
        return $this->isHoliday($day->format('Y-m-d'));
    }

    /**
     * True when the `Y-m-d` date is a holiday.
     *
     * @throws InvalidArgumentException When the text is not a valid date
     */
    public function isHoliday(string $date): bool
    {
        $date = self::dateOf($date);
        if (isset($this->dates[$date])) {
            return true;
        }
        $from = $this->recurring[substr($date, 5)] ?? null;

        return $from !== null && (int) $date >= $from;
    }

    /** Single dates plus recurring days. */
    public function count(): int
    {
        return count($this->dates) + count($this->recurring);
    }

    /**
     * The set as data, sorted: `{"dates": ["2026-02-16", ...], "recurring": [{"month": 11, "day": 20, "fromYear": 2024}, ...]}`.
     *
     * @return array{dates: list<string>, recurring: list<array{month: int, day: int, fromYear: int}>}
     */
    public function toArray(): array
    {
        $recurring = [];
        foreach ($this->recurring as $key => $fromYear) {
            [$month, $day] = explode('-', $key);
            $recurring[] = ['month' => (int) $month, 'day' => (int) $day, 'fromYear' => $fromYear];
        }

        return ['dates' => array_keys($this->dates), 'recurring' => $recurring];
    }

    private static function dateOf(string|DateTimeInterface $date): string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }
        $parts = explode('-', $date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || !checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            throw new InvalidArgumentException(sprintf('"%s" is not a Y-m-d date.', $date));
        }

        return $date;
    }

    private static function flag(mixed $value): bool
    {
        if ($value === null || is_bool($value)) {
            return $value === true;
        }
        if ($value === 0 || $value === 1) {
            return $value === 1;
        }
        $text = is_string($value) ? strtolower(trim($value)) : null;
        if ($text !== null && in_array($text, self::TRUE_TEXTS, true)) {
            return true;
        }
        if ($text !== null && in_array($text, self::FALSE_TEXTS, true)) {
            return false;
        }

        throw new InvalidArgumentException(sprintf('The recurring flag of a holiday row must be a boolean (got %s).', get_debug_type($value)));
    }
}
