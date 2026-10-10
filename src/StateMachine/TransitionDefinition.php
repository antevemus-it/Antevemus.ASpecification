<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;

/**
 * TransitionDefinition - Event-Driven Transition of a Machine Definition
 *
 * A transition answers an event type (forward 023, D1): when its guard passes, the region moves
 * to the target state. Several transitions may answer the same event in the same state; they are
 * tried in declaration order and the first whose guard passes wins (RN-03).
 *
 * - `target` null: targetless transition, runs only its own actions and keeps the state (and its
 *   deadline) untouched (RN-05)
 * - `target` equal to the source state: reentry, runs exit and entry and recomputes the deadline
 * - `internal` true: only accepted from events whose origin is internal (D19)
 * - `id`: unique within the machine; history and effects refer to it
 *
 * Array form: `{"id": "...", "event": "...", "target": "...", "guard": {...}, "actions": [...], "internal": false}`.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class TransitionDefinition
{
    public readonly string $id;
    public readonly string $event;
    public readonly ?string $target;

    /** @var list<ActionRef> */
    public readonly array $actions;

    /**
     * @param string $id Transition id, unique within the machine
     * @param string $event Event type answered
     * @param string|null $target Target state name in the same region; null for a targetless transition
     * @param GuardExpression|null $guard Guard expression; null means "always passes"
     * @param list<ActionRef> $actions Transition actions, in order
     * @param bool $internal Whether only internal events may take it
     * @throws InvalidMachineDefinitionException
     */
    public function __construct(
        string $id,
        string $event,
        ?string $target = null,
        public readonly ?GuardExpression $guard = null,
        array $actions = [],
        public readonly bool $internal = false
    ) {
        $this->id = DefinitionSupport::assertName($id, 'transition id');
        $this->event = DefinitionSupport::assertName($event, 'event type');
        $this->target = $target === null ? null : DefinitionSupport::assertName($target, 'transition target', true);
        foreach ($actions as $action) {
            if (!$action instanceof ActionRef) {
                throw InvalidMachineDefinitionException::single(
                    MachineDefinitionError::KIND_MALFORMED,
                    sprintf('The actions of transition "%s" must be ActionRef instances.', $id),
                    transitionId: $id
                );
            }
        }
        $this->actions = array_values($actions);
    }

    /**
     * True when the transition has no target (keeps the state, runs only its actions).
     */
    public function isTargetless(): bool
    {
        return $this->target === null;
    }

    /**
     * Returns the canonical array form.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'target' => $this->target,
            'guard' => $this->guard?->toArray(),
            'actions' => array_map(static fn(ActionRef $a): array => $a->toArray(), $this->actions),
            'internal' => $this->internal,
        ];
    }

    /**
     * Hydrates a transition from its array form. When `id` is missing, $defaultId is used.
     *
     * @param array<string, mixed> $data
     * @param string|null $defaultId Id to use when the array has none
     * @return self
     * @throws InvalidMachineDefinitionException
     */
    public static function fromArray(array $data, ?string $defaultId = null): self
    {
        $event = DefinitionSupport::requireString($data, 'event', 'a transition');
        $id = $data['id'] ?? $defaultId;
        if (!is_string($id)) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('A transition for event "%s" has no string id.', $event)
            );
        }
        $target = $data['target'] ?? null;
        if ($target !== null && !is_string($target)) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('The target of transition "%s" must be a string or null.', $id),
                transitionId: $id
            );
        }
        $guard = $data['guard'] ?? null;
        if ($guard !== null && !is_array($guard) && !is_string($guard)) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('The guard of transition "%s" must be a guard expression.', $id),
                transitionId: $id
            );
        }
        $internal = $data['internal'] ?? false;
        if (!is_bool($internal)) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('The "internal" flag of transition "%s" must be a boolean.', $id),
                transitionId: $id
            );
        }

        return new self(
            $id,
            $event,
            $target,
            $guard === null ? null : GuardExpression::fromArray($guard),
            array_map(ActionRef::fromArray(...), DefinitionSupport::optionalList($data, 'actions', "transition '{$id}'")),
            $internal
        );
    }
}
