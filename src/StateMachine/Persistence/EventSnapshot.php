<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use Antevemus\ASpecification\StateMachine\Event;
use Antevemus\ASpecification\StateMachine\EventOrigin;
use Antevemus\ASpecification\StateMachine\MachineSnapshot;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * EventSnapshot - The Event That Produced an Effect, as Its Handler Needs It
 *
 * Since 2.10.0 the runner writes, with every outbox entry, a copy of the event of the step: its type,
 * payload, origin, idempotency key, instant and the actor given to the runner. The handler of the
 * effect reads it through EffectContext (the motive of a cancellation, who approved), without going
 * back to the history. The start of an instance writes the reserved type `machine.start` with an
 * empty payload.
 *
 * The in-memory outbox always keeps it; the PDO outbox keeps it when the opt-in column
 * `event_snapshot` is mapped (PdoMachineSchema::COLUMNS_SINCE_2_10); unmapped, an entry read back has
 * none and the handler sees EffectContext::hasEvent() false.
 *
 * @version    2.10.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class EventSnapshot
{
    /**
     * @param string $type Event type
     * @param array<mixed> $payload Event payload
     * @param string|null $actor Who sent it (as given to the runner)
     * @param EventOrigin $origin External or internal
     * @param DateTimeImmutable $occurredAt Instant of the event (its offset kept)
     * @param string|null $idempotencyKey Idempotency key of the event, when given
     */
    public function __construct(
        public readonly string $type,
        public readonly array $payload,
        public readonly ?string $actor,
        public readonly EventOrigin $origin,
        public readonly DateTimeImmutable $occurredAt,
        public readonly ?string $idempotencyKey = null
    ) {
    }

    /**
     * The snapshot of an event sent by $actor.
     */
    public static function of(Event $event, ?string $actor): self
    {
        return new self($event->type, $event->payload, $actor, $event->origin, $event->occurredAt, $event->idempotencyKey);
    }

    /**
     * The JSON-friendly array form (stored in the column `event_snapshot`).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'payload' => $this->payload,
            'actor' => $this->actor,
            'origin' => $this->origin->value,
            'occurred_at' => $this->occurredAt->format(MachineSnapshot::DATE_FORMAT),
            'idempotency_key' => $this->idempotencyKey,
        ];
    }

    /**
     * Rebuilds the snapshot from its array form.
     *
     * @param array<mixed> $data
     * @throws InvalidArgumentException When the array is malformed
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;
        $payload = $data['payload'] ?? [];
        $actor = $data['actor'] ?? null;
        $origin = is_string($data['origin'] ?? null) ? EventOrigin::tryFrom($data['origin']) : null;
        $at = $data['occurred_at'] ?? null;
        $key = $data['idempotency_key'] ?? null;
        if (!is_string($type) || !is_array($payload) || ($actor !== null && !is_string($actor)) || $origin === null
            || !is_string($at) || ($key !== null && !is_string($key))) {
            throw new InvalidArgumentException('An event snapshot needs type, payload, actor, origin, occurred_at and idempotency_key of the right types.');
        }
        try {
            $occurredAt = new DateTimeImmutable($at);
        } catch (\Throwable) {
            throw new InvalidArgumentException(sprintf('The instant "%s" of the event snapshot is not a valid date.', $at));
        }

        return new self($type, $payload, $actor, $origin, $occurredAt, $key);
    }
}
