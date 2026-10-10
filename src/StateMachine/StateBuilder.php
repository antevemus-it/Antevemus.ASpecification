<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

/**
 * StateBuilder - Fluent Builder of a StateDefinition
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class StateBuilder
{
    /** @var list<ActionRef> */
    private array $entry = [];

    /** @var list<ActionRef> */
    private array $exit = [];

    /** @var list<TransitionBuilder> */
    private array $transitions = [];

    private ?DeadlineDefinition $deadline = null;

    /**
     * @param RegionBuilder $region Parent builder
     * @param string $name State name
     * @param bool $final Whether the state is final
     */
    public function __construct(
        private readonly RegionBuilder $region,
        private readonly string $name,
        private bool $final
    ) {
    }

    /**
     * Marks the state as final (or not).
     */
    public function final(bool $final = true): self
    {
        $this->final = $final;
        return $this;
    }

    /**
     * Adds an entry action.
     *
     * @param string $action Action name
     * @param array<mixed> $params JSON-friendly parameters
     */
    public function entry(string $action, array $params = []): self
    {
        $this->entry[] = new ActionRef($action, $params);
        return $this;
    }

    /**
     * Adds an exit action.
     *
     * @param string $action Action name
     * @param array<mixed> $params JSON-friendly parameters
     */
    public function exit(string $action, array $params = []): self
    {
        $this->exit[] = new ActionRef($action, $params);
        return $this;
    }

    /**
     * Declares the state's persisted deadline.
     *
     * @param string $after ISO-8601 duration (e.g. 'P3D')
     * @param string $event Event delivered when it expires (internal)
     */
    public function deadline(string $after, string $event): self
    {
        $this->deadline = new DeadlineDefinition($after, $event);
        return $this;
    }

    /**
     * Starts a transition of this state.
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
     * Starts a region-level transition of the enclosing region (valid from any non-final state of
     * the region, forward 023, D9). Unlike on(), the transition does not belong to this state.
     *
     * @param string $event Event type
     * @param string|null $target Target state; null for a targetless transition
     * @param string|null $id Transition id (generated when null)
     * @return TransitionBuilder
     */
    public function onAnyState(string $event, ?string $target = null, ?string $id = null): TransitionBuilder
    {
        return $this->region->on($event, $target, $id);
    }

    /**
     * Starts another state of the same region.
     */
    public function state(string $name): self
    {
        return $this->region->state($name);
    }

    /**
     * Starts another final state of the same region.
     */
    public function finalState(string $name): self
    {
        return $this->region->finalState($name);
    }

    /**
     * Starts another region of the machine.
     */
    public function region(string $name, ?string $initial = null): RegionBuilder
    {
        return $this->region->region($name, $initial);
    }

    /**
     * Returns to the region builder.
     */
    public function end(): RegionBuilder
    {
        return $this->region;
    }

    /**
     * Builds the whole machine.
     */
    public function build(): MachineDefinition
    {
        return $this->region->build();
    }

    /**
     * @return list<TransitionBuilder>
     * @internal
     */
    public function transitionBuilders(): array
    {
        return $this->transitions;
    }

    /**
     * @param array<string, true> $taken Transition ids already used (updated)
     * @internal
     */
    public function toDefinition(string $region, array &$taken): StateDefinition
    {
        $transitions = [];
        foreach ($this->transitions as $transition) {
            $transitions[] = $transition->toDefinition($region, $this->name, $taken);
        }

        return new StateDefinition($this->name, $this->final, $this->entry, $this->exit, $transitions, $this->deadline);
    }
}
