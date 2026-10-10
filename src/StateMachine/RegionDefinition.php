<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;

/**
 * RegionDefinition - Orthogonal Region of a Machine Definition
 *
 * A machine has one or more regions; each region has exactly one current state at a time
 * (forward 023, D2). A single region is an ordinary finite state machine. Besides the
 * transitions of its states, a region may declare region-level transitions: they apply from any
 * non-final state of the region, with lower priority than the state's own transitions for the
 * same event (D9: the "wildcard" of the legacy engine without fixed ids).
 *
 * The initial state is nullable so that a definition loaded from data without it can still be
 * reported by the validator (D17) instead of failing on load.
 *
 * Array form: `{"name": "...", "initial": "...", "states": [...], "transitions": [...]}`.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class RegionDefinition
{
    public readonly string $name;

    /** @var list<StateDefinition> */
    public readonly array $states;

    /** @var list<TransitionDefinition> */
    public readonly array $transitions;

    /** @var array<string, StateDefinition> */
    private array $byName = [];

    /**
     * @param string $name Region name (no dots), unique within the machine
     * @param string|null $initial Initial state name (validated by MachineDefinitionValidator)
     * @param list<StateDefinition> $states States, in declaration order (names unique)
     * @param list<TransitionDefinition> $transitions Region-level transitions, in priority order
     * @throws InvalidMachineDefinitionException
     */
    public function __construct(
        string $name,
        public readonly ?string $initial,
        array $states,
        array $transitions = []
    ) {
        $this->name = DefinitionSupport::assertName($name, 'region name', true);
        foreach ($states as $state) {
            if (!$state instanceof StateDefinition) {
                throw InvalidMachineDefinitionException::single(
                    MachineDefinitionError::KIND_MALFORMED,
                    sprintf('The states of region "%s" must be StateDefinition instances.', $name),
                    $name
                );
            }
            if (isset($this->byName[$state->name])) {
                throw InvalidMachineDefinitionException::single(
                    MachineDefinitionError::KIND_DUPLICATE,
                    sprintf('Region "%s" declares state "%s" twice.', $name, $state->name),
                    $name,
                    $state->name
                );
            }
            $this->byName[$state->name] = $state;
        }
        foreach ($transitions as $transition) {
            if (!$transition instanceof TransitionDefinition) {
                throw InvalidMachineDefinitionException::single(
                    MachineDefinitionError::KIND_MALFORMED,
                    sprintf('The transitions of region "%s" must be TransitionDefinition instances.', $name),
                    $name
                );
            }
        }
        $this->states = array_values($states);
        $this->transitions = array_values($transitions);
    }

    /**
     * Returns the state with the given name, or null.
     */
    public function getState(string $name): ?StateDefinition
    {
        return $this->byName[$name] ?? null;
    }

    /**
     * True when the region declares the state.
     */
    public function hasState(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    /**
     * Returns the state names, in declaration order.
     *
     * @return list<string>
     */
    public function stateNames(): array
    {
        return array_map(static fn(StateDefinition $s): string => $s->name, $this->states);
    }

    /**
     * Returns the region-level transitions answering the event type, in declaration order.
     *
     * @param string $eventType Event type
     * @return list<TransitionDefinition>
     */
    public function transitionsFor(string $eventType): array
    {
        return array_values(array_filter(
            $this->transitions,
            static fn(TransitionDefinition $t): bool => $t->event === $eventType
        ));
    }

    /**
     * Returns the candidate transitions for an event from the given state, in priority order: the
     * state's own transitions, then the region-level ones (RN-03). A final state has none (D10).
     *
     * @param StateDefinition $state Current state of the region
     * @param string $eventType Event type
     * @return list<TransitionDefinition>
     */
    public function candidatesFor(StateDefinition $state, string $eventType): array
    {
        if ($state->final) {
            return [];
        }

        return [...$state->transitionsFor($eventType), ...$this->transitionsFor($eventType)];
    }

    /**
     * Returns the canonical array form.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'initial' => $this->initial,
            'states' => array_map(static fn(StateDefinition $s): array => $s->toArray(), $this->states),
            'transitions' => array_map(static fn(TransitionDefinition $t): array => $t->toArray(), $this->transitions),
        ];
    }

    /**
     * Hydrates a region from its array form. Transitions without id receive a generated one.
     *
     * @param array<string, mixed> $data
     * @param array<string, true> $takenIds Transition ids already used in the machine (updated)
     * @return self
     * @throws InvalidMachineDefinitionException
     */
    public static function fromArray(array $data, array &$takenIds): self
    {
        $name = DefinitionSupport::requireString($data, 'name', 'a region');
        $initial = $data['initial'] ?? null;
        if ($initial !== null && !is_string($initial)) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('The initial state of region "%s" must be a string.', $name),
                $name
            );
        }

        $states = [];
        foreach (DefinitionSupport::optionalList($data, 'states', "region '{$name}'") as $state) {
            if (!is_array($state)) {
                throw InvalidMachineDefinitionException::single(
                    MachineDefinitionError::KIND_MALFORMED,
                    sprintf('A state of region "%s" must be an object.', $name),
                    $name
                );
            }
            $states[] = StateDefinition::fromArray($state, $name, $takenIds);
        }
        $transitions = [];
        foreach (DefinitionSupport::optionalList($data, 'transitions', "region '{$name}'") as $transition) {
            $transitions[] = StateDefinition::transitionFromArray($transition, $name, null, $takenIds);
        }

        return new self($name, $initial, $states, $transitions);
    }
}
