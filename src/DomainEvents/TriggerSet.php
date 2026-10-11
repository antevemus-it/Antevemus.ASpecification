<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * TriggerSet - Immutable, Ordered Set of Uniquely Named Triggers
 *
 * The evaluator walks the triggers in declaration order, so the events of one write come out in
 * that order too (forward 029, D1). Trigger names are unique; with() returns a new set.
 *
 * @implements IteratorAggregate<string, SpecificationTrigger>
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class TriggerSet implements IteratorAggregate, Countable
{
    /** @var array<string, SpecificationTrigger> name => trigger, in declaration order */
    private readonly array $triggers;

    /**
     * @param SpecificationTrigger ...$triggers Triggers, in evaluation order
     * @throws InvalidArgumentException When two triggers share a name
     */
    public function __construct(SpecificationTrigger ...$triggers)
    {
        $byName = [];
        foreach ($triggers as $trigger) {
            if (isset($byName[$trigger->name])) {
                throw new InvalidArgumentException(sprintf('Trigger name "%s" is declared twice.', $trigger->name));
            }
            $byName[$trigger->name] = $trigger;
        }
        $this->triggers = $byName;
    }

    /**
     * Returns a new set with the trigger appended.
     *
     * @throws InvalidArgumentException When the name is already taken
     */
    public function with(SpecificationTrigger $trigger): self
    {
        return new self(...array_values($this->triggers), ...[$trigger]);
    }

    /** True when a trigger has the name. */
    public function has(string $name): bool
    {
        return isset($this->triggers[$name]);
    }

    /** The trigger of the name, or null. */
    public function get(string $name): ?SpecificationTrigger
    {
        return $this->triggers[$name] ?? null;
    }

    /**
     * Every trigger, in declaration order.
     *
     * @return list<SpecificationTrigger>
     */
    public function all(): array
    {
        return array_values($this->triggers);
    }

    /**
     * Trigger names, in declaration order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map('strval', array_keys($this->triggers));
    }

    /**
     * Every event name the set can emit, without duplicates, in declaration order.
     *
     * @return list<string>
     */
    public function eventNames(): array
    {
        $names = [];
        foreach ($this->triggers as $trigger) {
            foreach ([$trigger->onEnter, $trigger->onExit] as $event) {
                if ($event !== null && !in_array($event, $names, true)) {
                    $names[] = $event;
                }
            }
        }

        return $names;
    }

    /** Number of triggers. */
    public function count(): int
    {
        return count($this->triggers);
    }

    /** @return Traversable<string, SpecificationTrigger> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->triggers);
    }
}
