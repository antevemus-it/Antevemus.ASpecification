<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * TriggerChanges - Events and Errors of One Evaluated Write
 *
 * Returned by TriggerEvaluator::changes() (forward 029, D2, D3): iterating it or counting it walks
 * the events (`list<DomainEvent>`, in trigger declaration order); the evaluation errors come along
 * in getErrors(), so an error is never swallowed in silence.
 *
 * @implements IteratorAggregate<int, DomainEvent>
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class TriggerChanges implements IteratorAggregate, Countable
{
    /**
     * @param list<DomainEvent> $events Events, in trigger declaration order
     * @param list<TriggerEvaluationError> $errors Evaluation errors, in trigger declaration order
     */
    public function __construct(
        private readonly array $events = [],
        private readonly array $errors = []
    ) {
    }

    /**
     * The events.
     *
     * @return list<DomainEvent>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * The evaluation errors.
     *
     * @return list<TriggerEvaluationError>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** True when at least one trigger could not be evaluated. */
    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** True when there is neither an event nor an error. */
    public function isEmpty(): bool
    {
        return $this->events === [] && $this->errors === [];
    }

    /**
     * Names of the events, in order (assertions, logs).
     *
     * @return list<string>
     */
    public function eventNames(): array
    {
        return array_map(static fn(DomainEvent $e): string => $e->name, $this->events);
    }

    /** Number of events. */
    public function count(): int
    {
        return count($this->events);
    }

    /** @return Traversable<int, DomainEvent> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->events);
    }
}
