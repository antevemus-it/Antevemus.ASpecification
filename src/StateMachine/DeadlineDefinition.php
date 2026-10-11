<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\Helpers\PropertyAccessor;
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
 * Paths start with `context.` and are resolved with the PropertyAccessor against the context given
 * to transition()/initialSnapshot() at the moment the state is entered. A missing or invalid value
 * makes the entering event rejected with SM_DEADLINE_SOURCE_INVALID (D8): never a silently empty
 * deadline.
 *
 * Pause (forward 025, D9): `pausedWhile` is a guard expression evaluated against the snapshot AFTER
 * each accepted transition (and at the start). While it holds, the region's `dueAt` is null and
 * the remaining whole seconds are kept in the snapshot; when it stops holding, the deadline resumes
 * at `occurredAt + remaining`. Reentering the state restarts the deadline and drops the balance.
 *
 * Array form: `{"after": "P3D", "event": "QUOTE_EXPIRED"}`, `{"afterFrom": "context.x", "event": "..."}`
 * or `{"atFrom": "context.y", "event": "..."}`, each with an optional `"pausedWhile": <guard expression>`.
 * The keys of an unused source and of an absent pause are omitted, so a definition without the
 * 1.9.0 fields keeps its 1.8.0 array form and fingerprint.
 *
 * @version    1.9.0
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

    /**
     * Backward compatible with 1.8.0: `new DeadlineDefinition('P3D', 'EXPIRE')`.
     *
     * @param string|null $after ISO-8601 duration (DateInterval syntax), or null with another source
     * @param string $event Event type delivered when the deadline expires
     * @param string|null $afterFrom Context path (`context.a.b`) of an ISO-8601 duration
     * @param string|null $atFrom Context path (`context.a.b`) of the due instant
     * @param GuardExpression|null $pausedWhile Condition under which the deadline is paused
     * @throws InvalidMachineDefinitionException When there is not exactly one valid source
     */
    public function __construct(
        ?string $after,
        string $event,
        ?string $afterFrom = null,
        ?string $atFrom = null,
        ?GuardExpression $pausedWhile = null
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
        $this->after = $after;
        $this->afterFrom = $afterFrom;
        $this->atFrom = $atFrom;
        $this->pausedWhile = $pausedWhile;
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
     * @throws LogicException When the deadline reads its source from the context (use resolveDue())
     */
    public function dueFrom(DateTimeImmutable $enteredAt): DateTimeImmutable
    {
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
     * @param DateTimeImmutable $enteredAt Instant of the event that entered the state
     * @param object|array<mixed>|null $context Domain context given to the transition
     * @return DateTimeImmutable|null The due instant; null when the context source is missing or invalid
     */
    public function resolveDue(DateTimeImmutable $enteredAt, object|array|null $context): ?DateTimeImmutable
    {
        if ($this->after !== null) {
            return $this->dueFrom($enteredAt);
        }
        try {
            $value = PropertyAccessor::getValue([self::CONTEXT_ROOT => $context], (string) $this->sourcePath());
        } catch (\Throwable) {
            return null;
        }

        return $this->afterFrom !== null
            ? self::addDuration($enteredAt, $value)
            : self::toInstant($value);
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
            $pausedWhile === null ? null : GuardExpression::fromArray($pausedWhile)
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
        if ($value instanceof DateInterval) {
            return $value->invert === 1 ? null : $enteredAt->add($value);
        }
        if (!is_string($value) || !str_starts_with($value, 'P')) {
            return null;
        }
        try {
            return $enteredAt->add(new DateInterval($value));
        } catch (\Throwable) {
            return null;
        }
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
