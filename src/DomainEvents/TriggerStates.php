<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

use Throwable;

/**
 * TriggerStates - Whether One Object Satisfies Each Trigger of a Set
 *
 * The outcome of TriggerEvaluator::statesOf() for one side of a write (forward 029, D2): per
 * trigger name, true or false, or the exception its specification threw. An absent object (the
 * "before" of a creation, the "after" of a removal) satisfies nothing. TriggeringRepository keeps
 * the states of the last write of each entity object, so that an entity mutated in place (the
 * in-memory repositories store the caller's own instance) still has a "before".
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class TriggerStates
{
    /**
     * @param array<string, bool|Throwable> $states Trigger name => satisfied, or the exception thrown
     * @param bool $present False for an absent object (nothing satisfied)
     */
    public function __construct(
        private readonly array $states,
        private readonly bool $present = true
    ) {
    }

    /** States of an absent object: no trigger is satisfied. */
    public static function absent(): self
    {
        return new self([], false);
    }

    /** False for the states of an absent object. */
    public function isPresent(): bool
    {
        return $this->present;
    }

    /** True when the object satisfies the trigger (false when absent, unknown or failed). */
    public function isSatisfied(string $triggerName): bool
    {
        return ($this->states[$triggerName] ?? false) === true;
    }

    /** The exception the trigger's specification threw, or null. */
    public function errorOf(string $triggerName): ?Throwable
    {
        $state = $this->states[$triggerName] ?? null;

        return $state instanceof Throwable ? $state : null;
    }

    /**
     * Raw states, by trigger name.
     *
     * @return array<string, bool|Throwable>
     */
    public function toArray(): array
    {
        return $this->states;
    }
}
