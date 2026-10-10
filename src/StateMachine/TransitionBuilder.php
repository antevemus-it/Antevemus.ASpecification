<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

/**
 * TransitionBuilder - Fluent Builder of a TransitionDefinition
 *
 * guard() may be called several times: the guards are combined with `and`, in call order.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class TransitionBuilder
{
    /** @var list<GuardExpression> */
    private array $guards = [];

    /** @var list<ActionRef> */
    private array $actions = [];

    private bool $internal = false;

    /**
     * @param StateBuilder|RegionBuilder $owner State (state-level) or region (region-level)
     * @param string $event Event type
     * @param string|null $target Target state; null for a targetless transition
     * @param string|null $id Transition id (generated when null)
     */
    public function __construct(
        private readonly StateBuilder|RegionBuilder $owner,
        private readonly string $event,
        private readonly ?string $target,
        private ?string $id
    ) {
    }

    /**
     * Sets the transition id.
     */
    public function id(string $id): self
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Adds a guard: a guard expression, or a guard name with its parameters.
     *
     * @param GuardExpression|string $guard Guard expression or guard name
     * @param array<mixed> $params Parameters when $guard is a name
     */
    public function guard(GuardExpression|string $guard, array $params = []): self
    {
        $this->guards[] = is_string($guard) ? new GuardRef($guard, $params) : $guard;
        return $this;
    }

    /**
     * Adds a transition action.
     *
     * @param string $action Action name
     * @param array<mixed> $params JSON-friendly parameters
     */
    public function action(string $action, array $params = []): self
    {
        $this->actions[] = new ActionRef($action, $params);
        return $this;
    }

    /**
     * Declares the transition internal: only internal events may take it (forward 023, D19).
     */
    public function internal(bool $internal = true): self
    {
        $this->internal = $internal;
        return $this;
    }

    /**
     * Starts another transition on the same owner (state or region).
     */
    public function on(string $event, ?string $target = null, ?string $id = null): self
    {
        return $this->owner->on($event, $target, $id);
    }

    /**
     * Starts a region-level transition of the enclosing region (valid from any non-final state).
     */
    public function onAnyState(string $event, ?string $target = null, ?string $id = null): self
    {
        return $this->owner->onAnyState($event, $target, $id);
    }

    /**
     * Starts another state of the same region.
     */
    public function state(string $name): StateBuilder
    {
        return $this->owner->state($name);
    }

    /**
     * Starts another final state of the same region.
     */
    public function finalState(string $name): StateBuilder
    {
        return $this->owner->finalState($name);
    }

    /**
     * Starts another region of the machine.
     */
    public function region(string $name, ?string $initial = null): RegionBuilder
    {
        return $this->owner->region($name, $initial);
    }

    /**
     * Returns to the owner (state or region).
     */
    public function end(): StateBuilder|RegionBuilder
    {
        return $this->owner;
    }

    /**
     * Builds the whole machine.
     */
    public function build(): MachineDefinition
    {
        return $this->owner->build();
    }

    /**
     * @internal
     */
    public function explicitId(): ?string
    {
        return $this->id;
    }

    /**
     * @param array<string, true> $taken Transition ids already used (updated)
     * @internal
     */
    public function toDefinition(string $region, ?string $state, array &$taken): TransitionDefinition
    {
        $id = $this->id ?? DefinitionSupport::generateTransitionId($region, $state, $this->event, $taken);
        $guard = match (count($this->guards)) {
            0 => null,
            1 => $this->guards[0],
            default => new GuardAllOf($this->guards),
        };

        return new TransitionDefinition($id, $this->event, $this->target, $guard, $this->actions, $this->internal);
    }
}
