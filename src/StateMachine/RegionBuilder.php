<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

/**
 * RegionBuilder - Fluent Builder of a RegionDefinition
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class RegionBuilder
{
    /** @var list<StateBuilder> */
    private array $states = [];

    /** @var list<TransitionBuilder> */
    private array $transitions = [];

    /**
     * @param MachineBuilder $machine Parent builder
     * @param string $name Region name
     * @param string|null $initial Initial state
     */
    public function __construct(
        private readonly MachineBuilder $machine,
        private readonly string $name,
        private ?string $initial = null
    ) {
    }

    /**
     * Sets the initial state of the region.
     */
    public function initial(string $state): self
    {
        $this->initial = $state;
        return $this;
    }

    /**
     * Starts a new (non-final) state. The first state becomes the initial one when none was set.
     */
    public function state(string $name): StateBuilder
    {
        $state = new StateBuilder($this, $name, false);
        $this->states[] = $state;
        $this->initial ??= $name;
        return $state;
    }

    /**
     * Starts a new final state.
     */
    public function finalState(string $name): StateBuilder
    {
        $state = new StateBuilder($this, $name, true);
        $this->states[] = $state;
        return $state;
    }

    /**
     * Starts a region-level transition: it applies from any non-final state of the region that
     * does not handle the event first (forward 023, D9).
     *
     * @param string $event Event type
     * @param string|null $target Target state; null for a targetless transition
     * @param string|null $id Transition id (generated when null)
     * @return TransitionBuilder
     */
    public function on(string $event, ?string $target = null, ?string $id = null): TransitionBuilder
    {
        $transition = new TransitionBuilder($this, $event, $target, $id);
        $this->transitions[] = $transition;
        return $transition;
    }

    /**
     * Alias of on() that reads better in a chain: a region-level transition, valid from any
     * non-final state of the region (forward 023, D9).
     *
     * @param string $event Event type
     * @param string|null $target Target state; null for a targetless transition
     * @param string|null $id Transition id (generated when null)
     * @return TransitionBuilder
     */
    public function onAnyState(string $event, ?string $target = null, ?string $id = null): TransitionBuilder
    {
        return $this->on($event, $target, $id);
    }

    /**
     * Starts another region of the machine.
     */
    public function region(string $name, ?string $initial = null): self
    {
        return $this->machine->region($name, $initial);
    }

    /**
     * Returns to the machine builder.
     */
    public function end(): MachineBuilder
    {
        return $this->machine;
    }

    /**
     * Builds the whole machine.
     */
    public function build(): MachineDefinition
    {
        return $this->machine->build();
    }

    /**
     * Explicit transition ids declared in the region.
     *
     * @return list<string>
     * @internal
     */
    public function explicitIds(): array
    {
        $builders = $this->transitions;
        foreach ($this->states as $state) {
            $builders = [...$builders, ...$state->transitionBuilders()];
        }
        $ids = [];
        foreach ($builders as $transition) {
            if ($transition->explicitId() !== null) {
                $ids[] = $transition->explicitId();
            }
        }

        return $ids;
    }

    /**
     * @param array<string, true> $taken Transition ids already used (updated)
     * @internal
     */
    public function toDefinition(array &$taken): RegionDefinition
    {
        $states = [];
        foreach ($this->states as $state) {
            $states[] = $state->toDefinition($this->name, $taken);
        }
        $transitions = [];
        foreach ($this->transitions as $transition) {
            $transitions[] = $transition->toDefinition($this->name, null, $taken);
        }

        return new RegionDefinition($this->name, $this->initial, $states, $transitions);
    }
}
