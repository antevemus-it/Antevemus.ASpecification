<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ADeepPropertyAccess\PropertyAccess;
use Antevemus\ASpecification\StateMachine\Calendar\IDeadlineCalendar;
use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use LogicException;

/**
 * DeadlineDefinition - Persisted Deadline Declared by a State
 *
 * A state may declare a deadline and the event delivered when it expires (forward 023, D18).
 * There is no in-memory timer: entering the state records `dueAt` for the region in the snapshot,
 * leaving it clears it and reentering recomputes it (RN-06). The deadline event is internal: sent
 * from outside it is rejected (D19), and it is only accepted once `dueAt <= occurredAt` (an
 * implicit guard, so late or repeated deliveries are harmless).
 *
 * Sources of the due instant (forward 025, D7), exactly one per deadline:
 * - `after`: a fixed ISO-8601 duration (`P3D`, `PT12H`); `dueAt = enteredAt + after`;
 * - `afterFrom`: a dotted path into the context (`context.deadlines.document_analysis`) holding an
 *   ISO-8601 duration (string or DateInterval); `dueAt = enteredAt + duration`;
 * - `atFrom`: a dotted path into the context (`context.sla_due_at`) holding the due instant itself
 *   (DateTimeInterface, or an ISO-8601 text with date, time and offset).
 * Paths start with `context.` and are resolved with the shared property accessor (`PropertyAccess`) against the context given
 * to transition()/initialSnapshot() at the moment the state is entered. A missing or invalid value
 * makes the entering event rejected with SM_DEADLINE_SOURCE_INVALID (D8): never a silently empty
 * deadline.
 *
 * Pause (forward 025, D9): `pausedWhile` is a guard expression evaluated against the snapshot AFTER
 * each accepted transition (and at the start). While it holds, the region's `dueAt` is null and
 * the remaining whole seconds are kept in the snapshot; when it stops holding, the deadline resumes
 * at `occurredAt + remaining`. Reentering the state restarts the deadline and drops the balance.
 *
 * Business time (2.7.0): a deadline may name a calendar (`calendar: 'business'`), resolved by the
 * core in its DeadlineCalendarRegistry. The duration of `after`/`afterFrom` then counts working
 * seconds of that calendar: each day is the calendar's secondsPerDay(), hours, minutes and
 * seconds count as such, and years and months are refused (a month of business days is
 * ambiguous): in `after` when the definition is built, in a context duration as an invalid
 * source. `atFrom` with a calendar is refused (the instant is already absolute). The pause keeps
 * the working seconds left (`workSecondsBetween(occurredAt, dueAt)`) and resumes with
 * `advance(occurredAt, balance)`. The due instant is computed once, on entering or resuming, and
 * stored: a holiday registered later does not move a deadline already running.
 *
 * Array form: `{"after": "P3D", "event": "QUOTE_EXPIRED"}`, `{"afterFrom": "context.x", "event": "..."}`
 * or `{"atFrom": "context.y", "event": "..."}`, each with an optional `"pausedWhile": <guard expression>`
 * and, for `after`/`afterFrom`, an optional `"calendar": "<name>"`. The keys of an unused source,
 * of an absent pause and of an absent calendar are omitted, so a definition without the 1.9.0 or
 * 2.7.0 fields keeps its earlier array form and fingerprint.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DeadlineDefinition
{
    /** Source kind: fixed duration. */
    public const SOURCE_AFTER = 'after';

    /** Source kind: duration read from the context. */
    public const SOURCE_AFTER_FROM = 'afterFrom';

    /** Source kind: due instant read from the context. */
    public const SOURCE_AT_FROM = 'atFrom';

    /** Root segment every context path starts with. */
    private const CONTEXT_ROOT = 'context';

    /** ISO-8601 instant with date, time and offset (seconds and fraction optional). */
    private const ISO_INSTANT = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})$/';

    /** Fixed ISO-8601 duration, or null when the deadline reads its source from the context. */
    public readonly ?string $after;

    public readonly string $event;

    /** Context path of an ISO-8601 duration, or null. */
    public readonly ?string $afterFrom;

    /** Context path of the due instant, or null. */
    public readonly ?string $atFrom;

    /** Pause condition, or null when the deadline never pauses. */
    public readonly ?GuardExpression $pausedWhile;

    /** Name of the calendar the duration counts in (DeadlineCalendarRegistry), or null for elapsed time. */
    public readonly ?string $calendar;

    /**
     * Backward compatible with 1.8.0: `new DeadlineDefinition('P3D', 'EXPIRE')`.
     *
     * @param string|null $after ISO-8601 duration (DateInterval syntax), or null with another source
     * @param string $event Event type delivered when the deadline expires
     * @param string|null $afterFrom Context path (`context.a.b`) of an ISO-8601 duration
     * @param string|null $atFrom Context path (`context.a.b`) of the due instant
     * @param GuardExpression|null $pausedWhile Condition under which the deadline is paused
     * @param string|null $calendar Name of the calendar the duration counts in (2.7.0); null = elapsed time
     * @throws InvalidMachineDefinitionException When there is not exactly one valid source, or the
     *                                           calendar is combined with `atFrom` or with years/months
     */
    public function __construct(
        ?string $after,
        string $event,
        ?string $afterFrom = null,
        ?string $atFrom = null,
        ?GuardExpression $pausedWhile = null,
        ?string $calendar = null
    ) {
        $this->event = DefinitionSupport::assertName($event, 'deadline event type');
        $sources = array_keys(array_filter(
            [self::SOURCE_AFTER => $after, self::SOURCE_AFTER_FROM => $afterFrom, self::SOURCE_AT_FROM => $atFrom],
            static fn(?string $v): bool => $v !== null
        ));
        if (count($sources) !== 1) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_INVALID_DEADLINE,
                sprintf(
                    'The deadline of event "%s" must declare exactly one source among "after", "afterFrom" and "atFrom" (%s given).',
                    $event,
                    $sources === [] ? 'none' : implode(', ', $sources)
                )
            );
        }
        if ($after !== null) {
            try {
                new DateInterval($after);
            } catch (\Throwable) {
                throw InvalidMachineDefinitionException::single(
                    MachineDefinitionError::KIND_MALFORMED,
                    sprintf('The deadline "after" value "%s" is not a valid ISO-8601 duration.', $after)
                );
            }
        }
        foreach ([self::SOURCE_AFTER_FROM => $afterFrom, self::SOURCE_AT_FROM => $atFrom] as $field => $path) {
            if ($path !== null && !self::isContextPath($path)) {
                throw InvalidMachineDefinitionException::single(
                    MachineDefinitionError::KIND_INVALID_DEADLINE,
                    sprintf(
                        'The deadline "%s" value "%s" must be a dotted path into the context, such as "context.sla_due_at".',
                        $field,
                        $path
                    )
                );
            }
        }
        if ($calendar !== null) {
            DefinitionSupport::assertName($calendar, 'deadline calendar name');
            if ($atFrom !== null) {
                throw InvalidMachineDefinitionException::single(
                    MachineDefinitionError::KIND_INVALID_DEADLINE,
                    sprintf(
                        'The deadline of event "%s" reads its due instant from "%s" and cannot count it in calendar "%s": the instant is already absolute.',
                        $event,
                        $atFrom,
                        $calendar
                    )
                );
            }
            if ($after !== null && self::hasYearsOrMonths(new DateInterval($after))) {
                throw InvalidMachineDefinitionException::single(
                    MachineDefinitionError::KIND_INVALID_DEADLINE,
                    sprintf(
                        'The deadline "after" value "%s" counts in calendar "%s" and must not use years or months (a month of working days is ambiguous): use days, hours, minutes and seconds.',
                        $after,
                        $calendar
                    )
                );
            }
        }
        $this->after = $after;
        $this->afterFrom = $afterFrom;
        $this->atFrom = $atFrom;
        $this->pausedWhile = $pausedWhile;
        $this->calendar = $calendar;
    }

    /**
     * The source kind in use: one of the SOURCE_* constants.
     */
    public function sourceKind(): string
    {
        return match (true) {
            $this->after !== null => self::SOURCE_AFTER,
            $this->afterFrom !== null => self::SOURCE_AFTER_FROM,
            default => self::SOURCE_AT_FROM,
        };
    }

    /**
     * The context path the due instant depends on, or null for a fixed `after` duration.
     */
    public function sourcePath(): ?string
    {
        return $this->afterFrom ?? $this->atFrom;
    }

    /**
     * Computes the due instant of a fixed `after` deadline from the instant the state was entered.
     *
     * @param DateTimeImmutable $enteredAt Instant of the event that entered the state
     * @return DateTimeImmutable
     * @throws LogicException When the deadline reads its source from the context, or counts in a
     *                        calendar (use resolveDue())
     */
    public function dueFrom(DateTimeImmutable $enteredAt): DateTimeImmutable
    {
        if ($this->calendar !== null) {
            throw new LogicException(sprintf(
                'The deadline of event "%s" counts in calendar "%s": use resolveDue() with the calendar.',
                $this->event,
                $this->calendar
            ));
        }
        if ($this->after === null) {
            throw new LogicException(sprintf(
                'The deadline of event "%s" reads its source from "%s": use resolveDue() with the context.',
                $this->event,
                (string) $this->sourcePath()
            ));
        }

        return $enteredAt->add(new DateInterval($this->after));
    }

    /**
     * Computes the due instant from the instant the state was entered and the context. Pure.
     *
     * Without $calendar the duration is added as a DateInterval (wall-clock), as before 2.7.0. With
     * a calendar it is converted to working seconds (days x secondsPerDay() + hours, minutes and
     * seconds; years or months make the source invalid) and placed with the calendar's advance().
     *
     * @param DateTimeImmutable $enteredAt Instant of the event that entered the state
     * @param object|array<mixed>|null $context Domain context given to the transition
     * @param IDeadlineCalendar|null $calendar Calendar the duration counts in (2.7.0); required when the deadline names one
     * @return DateTimeImmutable|null The due instant; null when the context source is missing or
     *                                invalid, or when the calendar cannot place it within its limit
     * @throws LogicException When the deadline names a calendar and none is given
     */
    public function resolveDue(DateTimeImmutable $enteredAt, object|array|null $context, ?IDeadlineCalendar $calendar = null): ?DateTimeImmutable
    {
        if ($calendar === null && $this->calendar !== null) {
            throw new LogicException(sprintf(
                'The deadline of event "%s" counts in calendar "%s": give resolveDue() the calendar.',
                $this->event,
                $this->calendar
            ));
        }
        if ($this->after !== null) {
            return $calendar === null
                ? $this->dueFrom($enteredAt)
                : self::advanceBy($calendar, $enteredAt, new DateInterval($this->after));
        }
        try {
            $value = PropertyAccess::getValue([self::CONTEXT_ROOT => $context], (string) $this->sourcePath());
        } catch (\Throwable) {
            return null;
        }
        if ($this->afterFrom === null) {
            return self::toInstant($value);
        }
        if ($calendar === null) {
            return self::addDuration($enteredAt, $value);
        }
        $interval = self::toInterval($value);

        return $interval === null ? null : self::advanceBy($calendar, $enteredAt, $interval);
    }

    /**
     * Returns the canonical array form (unused sources and an absent pause are omitted).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [];
        if ($this->after !== null) {
            $array['after'] = $this->after;
        }
        if ($this->afterFrom !== null) {
            $array['afterFrom'] = $this->afterFrom;
        }
        if ($this->atFrom !== null) {
            $array['atFrom'] = $this->atFrom;
        }
        $array['event'] = $this->event;
        if ($this->pausedWhile !== null) {
            $array['pausedWhile'] = $this->pausedWhile->toArray();
        }
        if ($this->calendar !== null) {
            $array['calendar'] = $this->calendar;
        }

        return $array;
    }

    /**
     * Hydrates a deadline from its array form.
     *
     * @param array<string, mixed> $data
     * @return self
     * @throws InvalidMachineDefinitionException
     */
    public static function fromArray(array $data): self
    {
        $optional = static function (string $field) use ($data): ?string {
            if (!array_key_exists($field, $data) || $data[$field] === null) {
                return null;
            }
            if (!is_string($data[$field])) {
                throw InvalidMachineDefinitionException::single(
                    MachineDefinitionError::KIND_MALFORMED,
                    sprintf('Field "%s" of a deadline must be a string.', $field)
                );
            }

            return $data[$field];
        };
        $pausedWhile = $data['pausedWhile'] ?? null;
        if ($pausedWhile !== null && !is_array($pausedWhile) && !is_string($pausedWhile)) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                'Field "pausedWhile" of a deadline must be a guard expression.'
            );
        }

        return new self(
            $optional('after'),
            DefinitionSupport::requireString($data, 'event', 'a deadline'),
            $optional('afterFrom'),
            $optional('atFrom'),
            $pausedWhile === null ? null : GuardExpression::fromArray($pausedWhile),
            $optional('calendar')
        );
    }

    private static function isContextPath(string $path): bool
    {
        $segments = explode('.', $path);
        if (count($segments) < 2 || $segments[0] !== self::CONTEXT_ROOT) {
            return false;
        }
        foreach ($segments as $segment) {
            if ($segment === '' || trim($segment) !== $segment) {
                return false;
            }
        }

        return true;
    }

    /**
     * Adds an ISO-8601 duration read from the context; null when it is not one (negative included).
     */
    private static function addDuration(DateTimeImmutable $enteredAt, mixed $value): ?DateTimeImmutable
    {
        $interval = self::toInterval($value);

        return $interval === null ? null : $enteredAt->add($interval);
    }

    /**
     * The duration read from the context as a DateInterval; null when it is not an ISO-8601
     * duration (negative included).
     */
    private static function toInterval(mixed $value): ?DateInterval
    {
        if ($value instanceof DateInterval) {
            return $value->invert === 1 ? null : $value;
        }
        if (!is_string($value) || !str_starts_with($value, 'P')) {
            return null;
        }
        try {
            return new DateInterval($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The instant at which the duration, counted in working seconds of the calendar, runs out;
     * null when the duration has years or months, or the calendar cannot place it.
     */
    private static function advanceBy(IDeadlineCalendar $calendar, DateTimeImmutable $enteredAt, DateInterval $interval): ?DateTimeImmutable
    {
        $seconds = self::workSecondsOf($interval, $calendar->secondsPerDay());

        return $seconds === null ? null : $calendar->advance($enteredAt, $seconds);
    }

    /** True when the duration counts years or months (refused in a calendar). */
    private static function hasYearsOrMonths(DateInterval $interval): bool
    {
        return $interval->y !== 0 || $interval->m !== 0;
    }

    /**
     * Working seconds of a duration: days x $secondsPerDay + hours, minutes and whole seconds (a
     * week is seven days); null when it has years or months.
     */
    private static function workSecondsOf(DateInterval $interval, int $secondsPerDay): ?int
    {
        if (self::hasYearsOrMonths($interval)) {
            return null;
        }

        return $interval->d * $secondsPerDay + $interval->h * 3600 + $interval->i * 60 + $interval->s;
    }

    /**
     * Converts the instant read from the context; null when it is not a DateTimeInterface nor an
     * ISO-8601 text with date, time and offset (relative texts such as "tomorrow" are refused: the
     * core reads no clock).
     */
    private static function toInstant(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (!is_string($value) || preg_match(self::ISO_INSTANT, $value, $m) !== 1) {
            return null;
        }
        try {
            $instant = new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
        // Refuse calendar overflow (2026-02-30 would silently become March 2nd).
        $seconds = ($m[6] ?? '') !== '' ? $m[6] : '00';
        $expected = sprintf('%s-%s-%sT%s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $seconds);

        return $instant->format('Y-m-d\TH:i:s') === $expected ? $instant : null;
    }
}
