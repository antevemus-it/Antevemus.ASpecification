<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use Antevemus\ASpecification\StateMachine\MachineSnapshot;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * MachineInstance - A Persisted Machine Instance
 *
 * What the instance store keeps per instance (forward 023, D13, D16): the id chosen by the
 * caller, the snapshot (pinned to the machine key and version it was born with), the optimistic
 * lock `version` (1 at start, +1 per accepted event or migration; compare-and-set, RN-08) and the
 * instants of creation and last change, both taken from the events (never from a clock) and kept
 * in UTC (PersistenceSupport).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MachineInstance
{
    public readonly string $instanceId;
    public readonly DateTimeImmutable $createdAt;
    public readonly DateTimeImmutable $updatedAt;

    /**
     * @param string $instanceId Instance id (non-empty, at most 120 characters)
     * @param MachineSnapshot $snapshot Current snapshot
     * @param int $version Optimistic lock version (>= 1)
     * @param DateTimeImmutable $createdAt Instant of the start event
     * @param DateTimeImmutable $updatedAt Instant of the last accepted event or migration
     * @throws InvalidArgumentException When the id or the version is invalid
     */
    public function __construct(
        string $instanceId,
        public readonly MachineSnapshot $snapshot,
        public readonly int $version,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt
    ) {
        $this->instanceId = PersistenceSupport::assertInstanceId($instanceId);
        if ($version < 1) {
            throw new InvalidArgumentException(sprintf('The version of machine instance "%s" must be >= 1 (got %d).', $instanceId, $version));
        }
        $this->createdAt = PersistenceSupport::utc($createdAt);
        $this->updatedAt = PersistenceSupport::utc($updatedAt);
    }

    /** Machine key of the snapshot. */
    public function getMachineKey(): string
    {
        return $this->snapshot->machineKey;
    }

    /** Machine version the instance is pinned to (D16). */
    public function getMachineVersion(): int
    {
        return $this->snapshot->machineVersion;
    }

    /**
     * Returns a copy with the next snapshot, version + 1 and the given change instant.
     */
    public function next(MachineSnapshot $snapshot, DateTimeImmutable $updatedAt): self
    {
        return new self($this->instanceId, $snapshot, $this->version + 1, $this->createdAt, $updatedAt);
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
            'version' => $this->version,
            'snapshot' => $this->snapshot->toArray(),
            'created_at' => PersistenceSupport::toText($this->createdAt),
            'updated_at' => PersistenceSupport::toText($this->updatedAt),
        ];
    }
}
