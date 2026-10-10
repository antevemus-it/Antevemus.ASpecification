<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

/**
 * TakenTransition - One Region Moved (or Acted) by an Accepted Event
 *
 * One entry per region that transitioned or ran a targetless transition: the unit of the future
 * history (forward 023, D15, RN-07). At start, one entry per region with `from` and
 * `transitionId` null (the creation line).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class TakenTransition
{
    /**
     * @param string $region Region name
     * @param string|null $from State before (null at start)
     * @param string $to State after (equal to $from for targetless and self transitions)
     * @param string|null $transitionId Transition id (null at start)
     * @param string|null $eventType Event type (null at start)
     * @param bool $targetless Whether the transition had no target (state kept, no exit/entry)
     */
    public function __construct(
        public readonly string $region,
        public readonly ?string $from,
        public readonly string $to,
        public readonly ?string $transitionId,
        public readonly ?string $eventType,
        public readonly bool $targetless = false
    ) {
    }

    /**
     * True when the transition left and re-entered the same state (exit and entry ran).
     */
    public function isReentry(): bool
    {
        return !$this->targetless && $this->from === $this->to;
    }

    /**
     * Returns the JSON-friendly array form.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'region' => $this->region,
            'from' => $this->from,
            'to' => $this->to,
            'transition_id' => $this->transitionId,
            'event' => $this->eventType,
            'targetless' => $this->targetless,
        ];
    }

    /**
     * Hydrates a taken transition from its array form (the inverse of toArray(); used by the
     * runner's persistence to give back a recorded result).
     *
     * @param array<string, mixed> $data
     * @return self
     * @throws \InvalidArgumentException When a field is missing or malformed
     */
    public static function fromArray(array $data): self
    {
        $from = $data['from'] ?? null;
        $transitionId = $data['transition_id'] ?? null;
        $event = $data['event'] ?? null;
        if (!isset($data['region'], $data['to']) || !is_string($data['region']) || !is_string($data['to'])
            || ($from !== null && !is_string($from))
            || ($transitionId !== null && !is_string($transitionId))
            || ($event !== null && !is_string($event))) {
            throw new \InvalidArgumentException('A taken transition array needs region and to (strings); from, transition_id and event must be strings or null.');
        }

        return new self($data['region'], $from, $data['to'], $transitionId, $event, (bool) ($data['targetless'] ?? false));
    }
}
