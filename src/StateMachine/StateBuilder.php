<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;

/**
 * StateBuilder - Fluent Builder of a StateDefinition
 *
 * @version    2.7.0
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

    private int|string|null $code = null;

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
     * Gives the state its legacy code (2.7.0): the integer id (or text key) of the table the machine
     * replaces, unique in the whole machine. The code is data of the definition; it does not change how
     * the machine runs. MachineDefinition::stateByCode() and codeOf() map one to the other.
     *
     * @param int|string $code Integer, or a non-empty text without surrounding whitespace
     */
    public function code(int|string $code): self
    {
        $this->code = DefinitionSupport::assertCode($code, $this->name);
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
     * Declares the state's persisted deadline (forward 023, D18; forward 025, D7 and D9).
     *
     * Exactly one source: a fixed duration (`deadline('P3D', 'EXPIRE')`, as in 1.8.0), a duration
     * read from the context (`deadline(event: 'STAGE_OVERDUE', afterFrom: 'context.deadlines.stage')`)
     * or the due instant read from the context (`deadline(event: 'SLA_BREACHED', atFrom:
     * 'context.sla_due_at')`). The optional pause condition is a guard expression, or a
     * `'region.state'` string as a shorthand for `inState` (`pausedWhile: 'pending.open'`). Since
     * 2.7.0 a fixed or context duration may count in a named calendar (`calendar: 'business'`).
     *
     * @param string|null $after ISO-8601 duration (e.g. 'P3D')
     * @param string|null $event Event delivered when it expires (internal); required
     * @param string|null $afterFrom Context path of an ISO-8601 duration (e.g. 'context.deadlines.stage')
     * @param string|null $atFrom Context path of the due instant (e.g. 'context.sla_due_at')
     * @param GuardExpression|string|null $pausedWhile Pause condition; a string is an inState path
     * @param string|null $calendar Name of the calendar the duration counts in (DeadlineCalendarRegistry, 2.7.0)
     * @throws InvalidMachineDefinitionException When the event is missing, the sources are not exactly
     *                                           one, or the calendar comes with `atFrom` or years/months
     */
    public function deadline(
        ?string $after = null,
        ?string $event = null,
        ?string $afterFrom = null,
        ?string $atFrom = null,
        GuardExpression|string|null $pausedWhile = null,
        ?string $calendar = null
    ): self {
        if ($event === null) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_INVALID_DEADLINE,
                sprintf('The deadline of state "%s" needs the event delivered when it expires.', $this->name),
                state: $this->name
            );
        }
        if (is_string($pausedWhile)) {
            $pausedWhile = GuardExpression::ref(GuardRegistry::IN_STATE, ['state' => $pausedWhile]);
        }
        $this->deadline = new DeadlineDefinition($after, $event, $afterFrom, $atFrom, $pausedWhile, $calendar);
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

        return new StateDefinition($this->name, $this->final, $this->entry, $this->exit, $transitions, $this->deadline, $this->code);
    }
}
