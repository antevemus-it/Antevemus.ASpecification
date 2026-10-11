<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\DomainEvents\Outbox\DomainEventSupport;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

/**
 * SpecificationTrigger - A Specification Bound to Domain Events
 *
 * When an entity starts to satisfy the specification the trigger emits its enter event; when it
 * stops, its exit event (forward 029, D1). At least one of the two events is required; a trigger
 * with only one of them is silent on the other crossing.
 *
 * <code>
 * $late = new SpecificationTrigger(
 *     'policy.delinquent',
 *     Spec::property('daysOverdue', Spec::greaterThan(30)),
 *     onEnter: 'delinquency.started',
 *     onExit: 'delinquency.ended'
 * );
 * </code>
 *
 * Time-based triggers (since 1.12.0, forward 030, D1): timed() takes a factory
 * `fn(DateTimeImmutable $now): ISpecification` instead of a specification, so that a rule such as
 * "unpaid and due more than 30 days ago" is rebuilt with the instant of each evaluation (the write
 * of TriggeringRepository, the sweep of TriggerSweeper). The factory receives the instant, it never
 * reads a clock. A time-based trigger may also declare the real instant of its crossings (D5):
 * `fn(object $entity, DateTimeImmutable $now, DomainEventKind $kind): ?DateTimeImmutable` (a
 * closure declaring fewer parameters is fine), e.g. `$invoice->dueDate->modify('+30 days')`. The
 * sweeper stamps the event with it, and so derives the idempotency key from it, when it returns an
 * instant after the last recorded evaluation and not after the sweep; otherwise, and on writes, the
 * event carries the instant of the evaluation.
 *
 * <code>
 * $overdue = SpecificationTrigger::timed(
 *     'invoice.overdue_30',
 *     fn(DateTimeImmutable $now) => Spec::property('dueDate', Spec::lessThan($now->modify('-30 days'))),
 *     onEnter: 'collection.started',
 *     crossedAt: fn(Invoice $invoice) => $invoice->dueDate->modify('+30 days')
 * );
 * </code>
 *
 * The public `$specification` of a time-based trigger is a placeholder that throws LogicException
 * when evaluated (no instant): use specificationAt($now), which answers for both kinds.
 *
 * Names (trigger and events) are non-empty, without surrounding whitespace and at most 120
 * characters (the columns of `resources/sql/domain_events.ansi.sql`).
 *
 * @version    1.12.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class SpecificationTrigger
{
    public readonly string $name;
    public readonly ?string $onEnter;
    public readonly ?string $onExit;

    /** @var (Closure(DateTimeImmutable): ISpecification)|null Factory of a time-based trigger */
    private ?Closure $factory = null;

    /** @var (Closure(object, DateTimeImmutable, DomainEventKind): ?DateTimeImmutable)|null */
    private ?Closure $crossedAt = null;

    /**
     * @param string $name Trigger name, unique in its TriggerSet
     * @param ISpecification $specification Specification watched on every write
     * @param string|null $onEnter Event emitted when the entity starts to satisfy it
     * @param string|null $onExit Event emitted when the entity stops satisfying it
     * @throws InvalidArgumentException When a name is invalid or both events are null
     */
    public function __construct(
        string $name,
        public readonly ISpecification $specification,
        ?string $onEnter = null,
        ?string $onExit = null
    ) {
        $this->name = DomainEventSupport::assertName($name, 'trigger name');
        if ($onEnter === null && $onExit === null) {
            throw new InvalidArgumentException(sprintf('Trigger "%s" must declare an enter event, an exit event or both.', $name));
        }
        $this->onEnter = $onEnter === null ? null : DomainEventSupport::assertName($onEnter, 'enter event name');
        $this->onExit = $onExit === null ? null : DomainEventSupport::assertName($onExit, 'exit event name');
    }

    /**
     * A time-based trigger: its specification is built from the instant of each evaluation.
     *
     * @param string $name Trigger name, unique in its TriggerSet
     * @param Closure(DateTimeImmutable): ISpecification $specificationAt Factory receiving "now"
     * @param string|null $onEnter Event emitted when the entity starts to satisfy it
     * @param string|null $onExit Event emitted when the entity stops satisfying it
     * @param (Closure(object, DateTimeImmutable, DomainEventKind): ?DateTimeImmutable)|null $crossedAt
     *        Real instant of a crossing found by the sweeper (see the class description); null = the sweep instant
     * @throws InvalidArgumentException When a name is invalid or both events are null
     */
    public static function timed(
        string $name,
        Closure $specificationAt,
        ?string $onEnter = null,
        ?string $onExit = null,
        ?Closure $crossedAt = null
    ): self {
        $trigger = new self($name, self::placeholder($name), $onEnter, $onExit);
        $trigger->factory = $specificationAt;
        $trigger->crossedAt = $crossedAt;

        return $trigger;
    }

    /** Trigger name. */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Watched specification. For a time-based trigger, a placeholder that throws when evaluated:
     * use specificationAt().
     */
    public function getSpecification(): ISpecification
    {
        return $this->specification;
    }

    /** True for a trigger made by timed(). */
    public function isTimed(): bool
    {
        return $this->factory !== null;
    }

    /** True when the trigger declares the real instant of its crossings. */
    public function hasCrossingInstant(): bool
    {
        return $this->crossedAt !== null;
    }

    /**
     * The specification at an instant: the factory's for a time-based trigger, the fixed one otherwise.
     *
     * @throws UnexpectedValueException When the factory does not return a specification
     * @throws \Throwable Whatever the factory throws
     */
    public function specificationAt(DateTimeImmutable $now): ISpecification
    {
        if ($this->factory === null) {
            return $this->specification;
        }
        $specification = ($this->factory)($now);
        if (!$specification instanceof ISpecification) {
            throw new UnexpectedValueException(sprintf(
                'The specification factory of trigger "%s" returned %s instead of an ISpecification.',
                $this->name,
                get_debug_type($specification)
            ));
        }

        return $specification;
    }

    /**
     * The instant the trigger's calculator gives for a crossing, or null (no calculator, or it
     * returned null).
     *
     * @throws UnexpectedValueException When the calculator returns something else than an instant or null
     * @throws \Throwable Whatever the calculator throws
     */
    public function crossingInstant(object $entity, DateTimeImmutable $now, DomainEventKind $kind): ?DateTimeImmutable
    {
        if ($this->crossedAt === null) {
            return null;
        }
        $instant = ($this->crossedAt)($entity, $now, $kind);
        if ($instant !== null && !$instant instanceof DateTimeImmutable) {
            throw new UnexpectedValueException(sprintf(
                'The crossing instant calculator of trigger "%s" returned %s instead of a DateTimeImmutable or null.',
                $this->name,
                get_debug_type($instant)
            ));
        }

        return $instant;
    }

    /** Event name emitted for the given crossing, or null when the trigger is silent on it. */
    public function eventFor(DomainEventKind $kind): ?string
    {
        return $kind === DomainEventKind::ENTER ? $this->onEnter : $this->onExit;
    }

    /** Specification of a time-based trigger read without an instant: always throws. */
    private static function placeholder(string $name): ISpecification
    {
        return new PredicateSpecification(static function () use ($name): bool {
            throw new LogicException(sprintf(
                'Trigger "%s" is time-based: its specification depends on "now"; use specificationAt($now).',
                $name
            ));
        });
    }
}
