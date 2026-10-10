<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

/**
 * Effect - Action to Be Dispatched, Produced by a Transition
 *
 * The pure core never runs actions (forward 023, D5, D11): each action referenced by an exit, a
 * transition or an entry becomes an Effect in the TransitionResult. Whoever applies the result
 * (the MachineRunner, through its outbox and the EffectDispatcher) dispatches them.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class Effect
{
    /**
     * @param string $action Action name
     * @param array<mixed> $params Action parameters, as declared
     * @param string $region Region the action belongs to
     * @param EffectPhase $phase Exit, transition or entry
     * @param string $state State exited (exit), source state (transition) or state entered (entry)
     * @param string|null $transitionId Transition that produced it; null for the start entry actions
     */
    public function __construct(
        public readonly string $action,
        public readonly array $params,
        public readonly string $region,
        public readonly EffectPhase $phase,
        public readonly string $state,
        public readonly ?string $transitionId
    ) {
    }

    /**
     * Returns the JSON-friendly array form.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'params' => $this->params,
            'region' => $this->region,
            'phase' => $this->phase->value,
            'state' => $this->state,
            'transition_id' => $this->transitionId,
        ];
    }

    /**
     * Hydrates an effect from its array form (the inverse of toArray(); used by the outbox).
     *
     * @param array<string, mixed> $data
     * @return self
     * @throws \InvalidArgumentException When a field is missing or malformed
     */
    public static function fromArray(array $data): self
    {
        $phase = EffectPhase::tryFrom((string) ($data['phase'] ?? ''));
        $params = $data['params'] ?? [];
        $transitionId = $data['transition_id'] ?? null;
        if (!isset($data['action'], $data['region'], $data['state'])
            || !is_string($data['action']) || !is_string($data['region']) || !is_string($data['state'])
            || $phase === null || !is_array($params) || ($transitionId !== null && !is_string($transitionId))) {
            throw new \InvalidArgumentException('An effect array needs action, region, state (strings), a valid phase, params (array) and transition_id (string|null).');
        }

        return new self($data['action'], $params, $data['region'], $phase, $data['state'], $transitionId);
    }
}
