<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\Helpers\PropertyAccessor;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Event - Immutable Event Sent to a State Machine
 *
 * The API is event-first (forward 023, D1): callers send an event and the target state is a
 * consequence of the definition. The event carries everything the pure core needs: its type, a
 * payload (motive, observation, amounts... whatever the guards read), the instant it happened
 * (`occurredAt`: guards and deadlines never read the clock, D4/RN-02), an optional idempotency key
 * (used by the MachineRunner, D13) and its origin (D19).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class Event
{
    public readonly string $type;

    /**
     * @param string $type Event type
     * @param DateTimeImmutable $occurredAt Instant the event happened ("now" of the transition)
     * @param array<mixed> $payload Event payload
     * @param string|null $idempotencyKey Optional idempotency key
     * @param EventOrigin $origin External (default) or internal
     * @throws InvalidArgumentException When the type is empty
     */
    public function __construct(
        string $type,
        public readonly DateTimeImmutable $occurredAt,
        public readonly array $payload = [],
        public readonly ?string $idempotencyKey = null,
        public readonly EventOrigin $origin = EventOrigin::EXTERNAL
    ) {
        if ($type === '' || trim($type) !== $type) {
            throw new InvalidArgumentException('The event type must be a non-empty string without surrounding whitespace.');
        }
        $this->type = $type;
    }

    /**
     * Creates an external event (sent by a caller).
     *
     * @param string $type Event type
     * @param DateTimeImmutable $occurredAt Instant the event happened
     * @param array<mixed> $payload Event payload
     * @param string|null $idempotencyKey Optional idempotency key
     * @return self
     */
    public static function external(
        string $type,
        DateTimeImmutable $occurredAt,
        array $payload = [],
        ?string $idempotencyKey = null
    ): self {
        return new self($type, $occurredAt, $payload, $idempotencyKey, EventOrigin::EXTERNAL);
    }

    /**
     * Creates an internal event (deadline expiry, runner infrastructure).
     *
     * @param string $type Event type
     * @param DateTimeImmutable $occurredAt Instant the event happened
     * @param array<mixed> $payload Event payload
     * @param string|null $idempotencyKey Optional idempotency key
     * @return self
     */
    public static function internal(
        string $type,
        DateTimeImmutable $occurredAt,
        array $payload = [],
        ?string $idempotencyKey = null
    ): self {
        return new self($type, $occurredAt, $payload, $idempotencyKey, EventOrigin::INTERNAL);
    }

    /** Event type. */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Event payload.
     *
     * @return array<mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** Instant the event happened. */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** Idempotency key, when given. */
    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    /** Origin of the event. */
    public function getOrigin(): EventOrigin
    {
        return $this->origin;
    }

    /** True when the event comes from the machine's own infrastructure. */
    public function isInternal(): bool
    {
        return $this->origin === EventOrigin::INTERNAL;
    }

    /**
     * Reads a payload value by key or dot-notation path; null when absent.
     *
     * @param string $path Key or dot path ('motive', 'amount.value')
     * @return mixed
     */
    public function payloadValue(string $path): mixed
    {
        return PropertyAccessor::getValue($this->payload, $path);
    }

    /**
     * Returns a JSON-friendly array form (occurredAt with microseconds and offset).
     *
     * @return array{type: string, occurred_at: string, payload: array<mixed>, idempotency_key: string|null, origin: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'occurred_at' => $this->occurredAt->format(MachineSnapshot::DATE_FORMAT),
            'payload' => $this->payload,
            'idempotency_key' => $this->idempotencyKey,
            'origin' => $this->origin->value,
        ];
    }

    /**
     * Hydrates an event from its array form (the inverse of toArray(); used by the runner's
     * persistence to give back a recorded result).
     *
     * @param array<string, mixed> $data
     * @return self
     * @throws InvalidArgumentException When a field is missing or malformed
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['type'], $data['occurred_at']) || !is_string($data['type']) || !is_string($data['occurred_at'])) {
            throw new InvalidArgumentException('An event array needs type (string) and occurred_at (string).');
        }
        $payload = $data['payload'] ?? [];
        $key = $data['idempotency_key'] ?? null;
        $origin = EventOrigin::tryFrom((string) ($data['origin'] ?? EventOrigin::EXTERNAL->value));
        if (!is_array($payload) || ($key !== null && !is_string($key)) || $origin === null) {
            throw new InvalidArgumentException('An event array has a malformed payload, idempotency_key or origin.');
        }
        try {
            $occurredAt = new DateTimeImmutable($data['occurred_at']);
        } catch (\Throwable) {
            throw new InvalidArgumentException(sprintf('The event instant "%s" is not a valid date.', $data['occurred_at']));
        }

        return new self($data['type'], $occurredAt, $payload, $key, $origin);
    }
}
