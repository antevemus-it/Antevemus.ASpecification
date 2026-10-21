<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;

/**
 * StateDefinition - Atomic State of a Region
 *
 * A state has a name, a final flag, entry and exit actions, its ordered transitions and an
 * optional deadline (forward 023, D9-D11, D18). States are atomic in v1 (no nesting inside a
 * region). A final state is inert: the validator refuses a final state that declares transitions
 * (D10), and region-level transitions never fire from it.
 *
 * Since 2.7.0 a state may carry a legacy `code` (the integer id of the table the machine replaces,
 * or a text): data of the definition, versioned with it, that changes nothing in how the machine
 * runs. Codes are unique in the whole machine (MachineDefinition, `KIND_DUPLICATE`) and a canonical
 * integer text is the same code as the integer (`"20"` ≡ `20`).
 *
 * Array form: `{"name": "...", "final": false, "entry": [...], "exit": [...], "deadline": {...}|null, "transitions": [...]}`,
 * plus `"code": 20` only when the state has a code (so the fingerprint of a definition without codes
 * is the one it had before 2.7.0).
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class StateDefinition
{
    public readonly string $name;

    /** @var list<ActionRef> */
    public readonly array $entry;

    /** @var list<ActionRef> */
    public readonly array $exit;

    /** @var list<TransitionDefinition> */
    public readonly array $transitions;

    /** Legacy code of the state (2.7.0), null when it has none. */
    public readonly int|string|null $code;

    /**
     * @param string $name State name (no dots), unique within its region
     * @param bool $final Whether the state is final (inert)
     * @param list<ActionRef> $entry Entry actions, in order
     * @param list<ActionRef> $exit Exit actions, in order
     * @param list<TransitionDefinition> $transitions Transitions, in priority order
     * @param DeadlineDefinition|null $deadline Optional persisted deadline
     * @param int|string|null $code Legacy code (2.7.0): an integer or a non-empty text without surrounding whitespace
     * @throws InvalidMachineDefinitionException
     */
    public function __construct(
        string $name,
        public readonly bool $final = false,
        array $entry = [],
        array $exit = [],
        array $transitions = [],
        public readonly ?DeadlineDefinition $deadline = null,
        int|string|null $code = null
    ) {
        $this->name = DefinitionSupport::assertName($name, 'state name', true);
        $this->code = $code === null ? null : DefinitionSupport::assertCode($code, $name);
        foreach ([...$entry, ...$exit] as $action) {
            if (!$action instanceof ActionRef) {
                throw InvalidMachineDefinitionException::single(
                    MachineDefinitionError::KIND_MALFORMED,
                    sprintf('The entry/exit actions of state "%s" must be ActionRef instances.', $name),
                    state: $name
                );
            }
        }
        foreach ($transitions as $transition) {
            if (!$transition instanceof TransitionDefinition) {
                throw InvalidMachineDefinitionException::single(
                    MachineDefinitionError::KIND_MALFORMED,
                    sprintf('The transitions of state "%s" must be TransitionDefinition instances.', $name),
                    state: $name
                );
            }
        }
        $this->entry = array_values($entry);
        $this->exit = array_values($exit);
        $this->transitions = array_values($transitions);
    }

    /**
     * Returns the transitions of this state answering the event type, in declaration order.
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
     * Returns the canonical array form.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [
            'name' => $this->name,
            'final' => $this->final,
            'entry' => array_map(static fn(ActionRef $a): array => $a->toArray(), $this->entry),
            'exit' => array_map(static fn(ActionRef $a): array => $a->toArray(), $this->exit),
            'deadline' => $this->deadline?->toArray(),
            'transitions' => array_map(static fn(TransitionDefinition $t): array => $t->toArray(), $this->transitions),
        ];
        if ($this->code !== null) {
            $array['code'] = $this->code;
        }

        return $array;
    }

    /**
     * Hydrates a state from its array form. Transitions without id receive a generated one.
     *
     * @param array<string, mixed> $data
     * @param string $region Region name (for generated transition ids)
     * @param array<string, true> $takenIds Transition ids already used in the machine (updated)
     * @return self
     * @throws InvalidMachineDefinitionException
     */
    public static function fromArray(array $data, string $region, array &$takenIds): self
    {
        $name = DefinitionSupport::requireString($data, 'name', 'a state');
        $final = $data['final'] ?? false;
        if (!is_bool($final)) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('The "final" flag of state "%s" must be a boolean.', $name),
                $region,
                $name
            );
        }
        $deadline = $data['deadline'] ?? null;
        if ($deadline !== null && !is_array($deadline)) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('The deadline of state "%s" must be an object.', $name),
                $region,
                $name
            );
        }

        $code = $data['code'] ?? null;
        if ($code !== null && !is_int($code) && !is_string($code)) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('The code of state "%s" must be an integer or a string (got %s).', $name, get_debug_type($code)),
                $region,
                $name
            );
        }

        $transitions = [];
        foreach (DefinitionSupport::optionalList($data, 'transitions', "state '{$name}'") as $transition) {
            $transitions[] = self::transitionFromArray($transition, $region, $name, $takenIds);
        }

        return new self(
            $name,
            $final,
            array_map(ActionRef::fromArray(...), DefinitionSupport::optionalList($data, 'entry', "state '{$name}'")),
            array_map(ActionRef::fromArray(...), DefinitionSupport::optionalList($data, 'exit', "state '{$name}'")),
            $transitions,
            $deadline === null ? null : DeadlineDefinition::fromArray($deadline),
            $code
        );
    }

    /**
     * Hydrates one transition, generating its id when missing.
     *
     * @param mixed $data Transition array
     * @param string $region Region name
     * @param string|null $state State name, null for a region-level transition
     * @param array<string, true> $takenIds Transition ids already used (updated)
     * @return TransitionDefinition
     * @throws InvalidMachineDefinitionException
     * @internal
     */
    public static function transitionFromArray(mixed $data, string $region, ?string $state, array &$takenIds): TransitionDefinition
    {
        if (!is_array($data)) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('A transition of %s must be an object.', $state === null ? "region '{$region}'" : "state '{$state}'"),
                $region,
                $state
            );
        }
        $defaultId = null;
        if (!isset($data['id']) && isset($data['event']) && is_string($data['event'])) {
            $defaultId = DefinitionSupport::generateTransitionId($region, $state, $data['event'], $takenIds);
        }

        return TransitionDefinition::fromArray($data, $defaultId);
    }
}
