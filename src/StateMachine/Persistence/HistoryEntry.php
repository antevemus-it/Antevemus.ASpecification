<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use Antevemus\ASpecification\StateMachine\EventOrigin;
use DateTimeImmutable;

/**
 * HistoryEntry - One Append-Only History Row
 *
 * One row per region moved (or acted on by a targetless transition) by an accepted event, plus
 * the creation rows written at start and the rows of a version migration (forward 023, D15,
 * RN-07). Fields of D15: instance id, machine key and version, region, from/to state, event type,
 * transition id, payload, actor, idempotency key and the instant; plus the position of the row:
 * `instanceVersion` (the instance version the step produced) and `sortOrder` (0-based, region
 * order within the step), which are the primary key and the reading order. The event origin is
 * kept as well (a deadline is internal).
 *
 * Start rows have a null `fromState`, a null `transitionId` and the reserved event type
 * MachineRunner::START_EVENT; migration rows have the reserved type MachineRunner::MIGRATE_EVENT.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class HistoryEntry
{
    public readonly DateTimeImmutable $occurredAt;

    /**
     * @param string $instanceId Instance id
     * @param int $instanceVersion Instance version produced by the step
     * @param int $sortOrder Position of the row within the step (0-based)
     * @param string $machineKey Machine key
     * @param int $machineVersion Machine version the step ran on
     * @param string $region Region name
     * @param string|null $fromState State before (null at start, or for a region new in a migration)
     * @param string $toState State after
     * @param string $eventType Event type (or a reserved runner event type)
     * @param string|null $transitionId Transition id (null at start and in migrations)
     * @param array<mixed> $payload Event payload, as sent
     * @param string|null $actor Who triggered it, when given
     * @param string|null $idempotencyKey Idempotency key of the event, when given
     * @param EventOrigin $origin Event origin
     * @param DateTimeImmutable $occurredAt Instant of the event (stored in UTC)
     */
    public function __construct(
        public readonly string $instanceId,
        public readonly int $instanceVersion,
        public readonly int $sortOrder,
        public readonly string $machineKey,
        public readonly int $machineVersion,
        public readonly string $region,
        public readonly ?string $fromState,
        public readonly string $toState,
        public readonly string $eventType,
        public readonly ?string $transitionId,
        public readonly array $payload,
        public readonly ?string $actor,
        public readonly ?string $idempotencyKey,
        public readonly EventOrigin $origin,
        DateTimeImmutable $occurredAt
    ) {
        $this->occurredAt = PersistenceSupport::utc($occurredAt);
    }

    /**
     * Returns the JSON-friendly array form (column names of the reference DDL).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'instance_version' => $this->instanceVersion,
            'sort_order' => $this->sortOrder,
            'machine_key' => $this->machineKey,
            'machine_version' => $this->machineVersion,
            'region' => $this->region,
            'from_state' => $this->fromState,
            'to_state' => $this->toState,
            'event_type' => $this->eventType,
            'transition_id' => $this->transitionId,
            'payload' => $this->payload,
            'actor' => $this->actor,
            'idempotency_key' => $this->idempotencyKey,
            'event_origin' => $this->origin->value,
            'occurred_at' => PersistenceSupport::toText($this->occurredAt),
        ];
    }
}
