<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use BackedEnum;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Stringable;
use Throwable;

/**
 * TriggerEvaluator - Compares the Before and the After of a Write
 *
 * For every trigger of a set, in declaration order (forward 029, D2):
 *
 * | before        | after         | event                         |
 * |---------------|---------------|-------------------------------|
 * | not satisfied | satisfied     | enter (when the trigger has one) |
 * | satisfied     | not satisfied | exit (when the trigger has one)  |
 * | same          | same          | nothing                       |
 * | absent (creation) | satisfied | enter                         |
 * | satisfied     | absent (removal) | exit                       |
 *
 * Evaluation errors (D3): a specification that throws on either side makes its trigger emit
 * nothing for that write and adds a TriggerEvaluationError to the result, with the exception kept
 * for the log; the other triggers are still evaluated. The evaluator never throws for an
 * evaluation error.
 *
 * "Now" is injected, never read inside: changes() stamps the events with $now, or with the clock
 * given to the constructor when $now is null. With neither, a write that produces an event is
 * refused with InvalidArgumentException (a write that produces none needs no instant). A plain
 * specification never receives a clock. A time-based trigger (SpecificationTrigger::timed(), since
 * 1.12.0, forward 030, D1) is evaluated on the specification its factory builds with that same
 * instant; without an instant it cannot be evaluated, which is an evaluation error of that trigger.
 * A crossing caused by time alone, with no write, is found by TriggerSweeper.
 *
 * The entity id is read with IEntity::getEntityId() unless an identity reader is given; ints,
 * strings, Stringable objects and backed enums have a text form, anything else is an identity
 * error of the trigger that needed it.
 *
 * @version    1.12.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class TriggerEvaluator
{
    /**
     * @param (Closure(): DateTimeImmutable)|null $clock Instant used when changes() gets no $now; null = none
     * @param (Closure(object): mixed)|null $identity Reads the id of an entity; null = IEntity::getEntityId()
     */
    public function __construct(
        private readonly ?Closure $clock = null,
        private readonly ?Closure $identity = null
    ) {
    }

    /**
     * Events of a write: compares the satisfaction of every trigger before and after it.
     *
     * @param TriggerSet $triggers Triggers to evaluate
     * @param object|null $before State before the write; null = creation
     * @param object|null $after State after the write; null = removal
     * @param DateTimeImmutable|null $now Instant of the write; null = the constructor's clock
     * @return TriggerChanges Events (iterable, countable) and evaluation errors
     * @throws InvalidArgumentException When before and after are different entities, or an event
     *                                  must be stamped and there is neither $now nor a clock
     */
    public function changes(TriggerSet $triggers, ?object $before, ?object $after, ?DateTimeImmutable $now = null): TriggerChanges
    {
        if ($before === null && $after === null) {
            return new TriggerChanges();
        }
        if ($before !== null && $after !== null && $before !== $after) {
            $this->assertSameEntity($before, $after);
        }
        // A time-based trigger needs the instant to be evaluated, not only to stamp an event
        if ($now === null && $this->clock !== null && $triggers->hasTimed()) {
            $now = ($this->clock)();
        }

        return $this->compare(
            $triggers,
            $this->statesOf($triggers, $before, $now),
            $this->statesOf($triggers, $after, $now),
            $after ?? $before,
            $now
        );
    }

    /**
     * Satisfaction of every trigger by one object (an exception is kept as the state).
     *
     * A time-based trigger is evaluated on the specification built for $now; without $now its
     * state is a LogicException (an evaluation error, never a silent false).
     *
     * @param TriggerSet $triggers Triggers
     * @param object|null $subject Object; null = absent (nothing satisfied)
     * @param DateTimeImmutable|null $now Instant of the evaluation (time-based triggers; since 1.12.0)
     */
    public function statesOf(TriggerSet $triggers, ?object $subject, ?DateTimeImmutable $now = null): TriggerStates
    {
        if ($subject === null) {
            return TriggerStates::absent();
        }
        $states = [];
        foreach ($triggers->all() as $trigger) {
            try {
                $states[$trigger->name] = $this->satisfies($trigger, $subject, $now);
            } catch (Throwable $e) {
                $states[$trigger->name] = $e;
            }
        }

        return new TriggerStates($states);
    }

    /**
     * Whether one object satisfies one trigger at an instant (since 1.12.0).
     *
     * @throws LogicException When the trigger is time-based and there is no instant
     * @throws Throwable Whatever the specification (or the factory of a time-based trigger) throws
     */
    public function satisfies(SpecificationTrigger $trigger, object $subject, ?DateTimeImmutable $now = null): bool
    {
        if ($trigger->isTimed()) {
            if ($now === null) {
                throw new LogicException(sprintf('Trigger "%s" is time-based: an instant is required to evaluate it.', $trigger->name));
            }

            return $trigger->specificationAt($now)->isSatisfiedBy($subject) === true;
        }

        return $trigger->specification->isSatisfiedBy($subject) === true;
    }

    /**
     * Events and errors from two sets of states already computed (used by TriggeringRepository,
     * which may know the "before" of an entity mutated in place only by its recorded states).
     *
     * @param TriggerSet $triggers Triggers
     * @param TriggerStates $before States before the write
     * @param TriggerStates $after States after the write
     * @param object $subject The entity (its class and id stamp the events)
     * @param DateTimeImmutable|null $now Instant of the write; null = the constructor's clock
     * @throws InvalidArgumentException When an event must be stamped and there is no instant
     */
    public function compare(
        TriggerSet $triggers,
        TriggerStates $before,
        TriggerStates $after,
        object $subject,
        ?DateTimeImmutable $now = null
    ): TriggerChanges {
        $events = [];
        $errors = [];
        $entityClass = get_class($subject);
        /** @var array{0: ?string, 1: ?Throwable}|null $identity */
        $identity = null;
        $instant = $now;

        foreach ($triggers->all() as $trigger) {
            $failed = false;
            foreach ([TriggerEvaluationError::SIDE_BEFORE => $before, TriggerEvaluationError::SIDE_AFTER => $after] as $side => $states) {
                $error = $states->errorOf($trigger->name);
                if ($error !== null) {
                    $identity ??= $this->readIdentity($subject);
                    $errors[] = new TriggerEvaluationError($trigger->name, $side, $entityClass, $identity[0], $error);
                    $failed = true;
                }
            }
            if ($failed) {
                continue;
            }

            $was = $before->isSatisfied($trigger->name);
            $is = $after->isSatisfied($trigger->name);
            if ($was === $is) {
                continue;
            }
            $kind = $is ? DomainEventKind::ENTER : DomainEventKind::EXIT;
            $name = $trigger->eventFor($kind);
            if ($name === null) {
                continue;
            }

            $identity ??= $this->readIdentity($subject);
            if ($identity[1] !== null) {
                $errors[] = new TriggerEvaluationError($trigger->name, TriggerEvaluationError::SIDE_IDENTITY, $entityClass, null, $identity[1]);
                continue;
            }
            $instant ??= $this->clock !== null
                ? ($this->clock)()
                : throw new InvalidArgumentException('An instant is required to stamp domain events: pass $now or give the evaluator a clock.');
            try {
                $events[] = new DomainEvent($name, $trigger->name, $kind, $entityClass, (string) $identity[0], $instant);
            } catch (InvalidArgumentException $e) {
                $errors[] = new TriggerEvaluationError($trigger->name, TriggerEvaluationError::SIDE_IDENTITY, $entityClass, $identity[0], $e);
            }
        }

        return new TriggerChanges($events, $errors);
    }

    /**
     * Text form of an entity id (int, string, Stringable, backed enum).
     *
     * @throws InvalidArgumentException When the id is null or has no text form
     */
    public function identityOf(object $subject): string
    {
        $read = $this->readIdentity($subject);
        if ($read[1] !== null) {
            throw $read[1] instanceof InvalidArgumentException
                ? $read[1]
                : new InvalidArgumentException('The entity id could not be read.', 0, $read[1]);
        }

        return (string) $read[0];
    }

    /**
     * Reads the id: [text, null] or [null, the reason].
     *
     * @return array{0: ?string, 1: ?Throwable}
     */
    private function readIdentity(object $subject): array
    {
        try {
            if ($this->identity !== null) {
                $id = ($this->identity)($subject);
            } elseif ($subject instanceof IEntity) {
                $id = $subject->getEntityId();
            } else {
                throw new InvalidArgumentException(sprintf(
                    'Cannot read the id of a %s: it is not an IEntity and the evaluator has no identity reader.',
                    get_class($subject)
                ));
            }
            $text = self::textOf($id);
            if ($text === null) {
                throw new InvalidArgumentException(sprintf('The id of %s has no text form (%s).', get_class($subject), get_debug_type($id)));
            }

            return [$text, null];
        } catch (Throwable $e) {
            return [null, $e];
        }
    }

    /** Text form of an id value, or null when it has none (null included). */
    public static function textOf(mixed $id): ?string
    {
        return match (true) {
            is_int($id), is_string($id) => (string) $id,
            $id instanceof BackedEnum => (string) $id->value,
            $id instanceof Stringable => (string) $id,
            default => null,
        };
    }

    /**
     * @throws InvalidArgumentException When the two objects are not the same entity
     */
    private function assertSameEntity(object $before, object $after): void
    {
        $a = $this->readIdentity($before);
        $b = $this->readIdentity($after);
        if ((!$before instanceof $after && !$after instanceof $before) || ($a[1] === null && $b[1] === null && $a[0] !== $b[0])) {
            throw new InvalidArgumentException(sprintf(
                'Before and after must be the same entity (%s#%s vs %s#%s).',
                get_class($before),
                $a[0] ?? '?',
                get_class($after),
                $b[0] ?? '?'
            ));
        }
    }
}
