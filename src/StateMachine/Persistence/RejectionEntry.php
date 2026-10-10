<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use Antevemus\ASpecification\StateMachine\EventOrigin;
use Antevemus\ASpecification\StateMachine\TransitionStatus;
use DateTimeImmutable;

/**
 * RejectionEntry - One Row of the Optional Rejection Log
 *
 * Rejected and unhandled events never reach the history (forward 023, RN-07); when the optional
 * IMachineRejectionLog is configured (off by default) the runner records them here, with the
 * reason in its array form (codes, messages, severities; never a stack trace).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class RejectionEntry
{
    public readonly DateTimeImmutable $occurredAt;

    /**
     * @param string $rejectionId Unique id of the row (a ULID by default)
     * @param string $instanceId Instance id
     * @param int $instanceVersion Instance version the event was evaluated against
     * @param string $machineKey Machine key
     * @param int $machineVersion Machine version
     * @param TransitionStatus $status Rejected or unhandled
     * @param string $eventType Event type
     * @param array<mixed> $payload Event payload, as sent
     * @param string|null $actor Who triggered it, when given
     * @param string|null $idempotencyKey Idempotency key of the event, when given
     * @param EventOrigin $origin Event origin
     * @param array<string, mixed> $reason Array form of the reason (TransitionResult::toArray()['reason'])
     * @param DateTimeImmutable $occurredAt Instant of the event (stored in UTC)
     */
    public function __construct(
        public readonly string $rejectionId,
        public readonly string $instanceId,
        public readonly int $instanceVersion,
        public readonly string $machineKey,
        public readonly int $machineVersion,
        public readonly TransitionStatus $status,
        public readonly string $eventType,
        public readonly array $payload,
        public readonly ?string $actor,
        public readonly ?string $idempotencyKey,
        public readonly EventOrigin $origin,
        public readonly array $reason,
        DateTimeImmutable $occurredAt
    ) {
        $this->occurredAt = PersistenceSupport::utc($occurredAt);
    }

    /**
     * Returns the JSON-friendly array form.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rejection_id' => $this->rejectionId,
            'instance_id' => $this->instanceId,
            'instance_version' => $this->instanceVersion,
            'machine_key' => $this->machineKey,
            'machine_version' => $this->machineVersion,
            'status' => $this->status->value,
            'event_type' => $this->eventType,
            'payload' => $this->payload,
            'actor' => $this->actor,
            'idempotency_key' => $this->idempotencyKey,
            'event_origin' => $this->origin->value,
            'reason' => $this->reason,
            'occurred_at' => PersistenceSupport::toText($this->occurredAt),
        ];
    }
}
