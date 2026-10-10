<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use Antevemus\ASpecification\StateMachine\Effect;
use DateTimeImmutable;

/**
 * OutboxEntry - One Effect Waiting for (or Done with) Delivery
 *
 * Written by the runner in the same transaction as the snapshot and the history (forward 023,
 * D12, RN-10). Its position (`instanceVersion`, `sortOrder`) is the primary key and the delivery
 * order within the instance; `effectKey` (`{instanceId}:{instanceVersion}:{sortOrder}`) is the
 * idempotency key handed to the handler, stable across retries. Failures increment `attempts`
 * and keep only the last error message, never a stack trace.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class OutboxEntry
{
    public readonly DateTimeImmutable $enqueuedAt;
    public readonly ?DateTimeImmutable $deliveredAt;
    public readonly ?DateTimeImmutable $lastAttemptAt;

    /**
     * @param string $instanceId Instance id
     * @param int $instanceVersion Instance version produced by the step that wrote it
     * @param int $sortOrder Position of the effect within the step (0-based, SCXML order)
     * @param string $machineKey Machine key
     * @param int $machineVersion Machine version
     * @param Effect $effect The effect
     * @param OutboxStatus $status Delivery status
     * @param int $attempts Delivery attempts made
     * @param string|null $lastError Message of the last failure (no stack trace)
     * @param DateTimeImmutable $enqueuedAt Instant of the event that produced it (stored in UTC)
     * @param DateTimeImmutable|null $deliveredAt Instant of delivery
     * @param DateTimeImmutable|null $lastAttemptAt Instant of the last attempt
     */
    public function __construct(
        public readonly string $instanceId,
        public readonly int $instanceVersion,
        public readonly int $sortOrder,
        public readonly string $machineKey,
        public readonly int $machineVersion,
        public readonly Effect $effect,
        public readonly OutboxStatus $status,
        public readonly int $attempts,
        public readonly ?string $lastError,
        DateTimeImmutable $enqueuedAt,
        ?DateTimeImmutable $deliveredAt = null,
        ?DateTimeImmutable $lastAttemptAt = null
    ) {
        $this->enqueuedAt = PersistenceSupport::utc($enqueuedAt);
        $this->deliveredAt = $deliveredAt === null ? null : PersistenceSupport::utc($deliveredAt);
        $this->lastAttemptAt = $lastAttemptAt === null ? null : PersistenceSupport::utc($lastAttemptAt);
    }

    /**
     * Idempotency key of the effect, handed to its handler: `{instanceId}:{instanceVersion}:{sortOrder}`.
     */
    public function effectKey(): string
    {
        return sprintf('%s:%d:%d', $this->instanceId, $this->instanceVersion, $this->sortOrder);
    }

    /** True when the entry was delivered. */
    public function isDelivered(): bool
    {
        return $this->status === OutboxStatus::DELIVERED;
    }

    /**
     * Returns a delivered copy (attempts + 1).
     */
    public function withDelivered(DateTimeImmutable $at): self
    {
        return new self(
            $this->instanceId, $this->instanceVersion, $this->sortOrder, $this->machineKey, $this->machineVersion,
            $this->effect, OutboxStatus::DELIVERED, $this->attempts + 1, $this->lastError,
            $this->enqueuedAt, $at, $at
        );
    }

    /**
     * Returns a failed copy (attempts + 1, last error replaced).
     */
    public function withFailed(string $error, DateTimeImmutable $at): self
    {
        return new self(
            $this->instanceId, $this->instanceVersion, $this->sortOrder, $this->machineKey, $this->machineVersion,
            $this->effect, OutboxStatus::FAILED, $this->attempts + 1, $error,
            $this->enqueuedAt, null, $at
        );
    }

    /**
     * Returns the JSON-friendly array form.
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
            'effect_key' => $this->effectKey(),
            'effect' => $this->effect->toArray(),
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'last_error' => $this->lastError,
            'enqueued_at' => PersistenceSupport::toText($this->enqueuedAt),
            'delivered_at' => $this->deliveredAt === null ? null : PersistenceSupport::toText($this->deliveredAt),
            'last_attempt_at' => $this->lastAttemptAt === null ? null : PersistenceSupport::toText($this->lastAttemptAt),
        ];
    }
}
