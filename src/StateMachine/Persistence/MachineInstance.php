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
 * Since 2.7.0 an instance may belong to a tenant: an opaque text chosen by the application (at most
 * 120 characters; a unit id 7 goes as "7"), fixed at start and kept by every next step and migration.
 * The tenant is whose instance it is, not who acts on it (that is the actor of each step). Null is an
 * instance without tenant, as before 2.7.0.
 *
 * @version    2.7.0
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

    /** Tenant the instance belongs to (2.7.0), null when it has none. */
    public readonly ?string $tenant;

    /**
     * @param string $instanceId Instance id (non-empty, at most 120 characters)
     * @param MachineSnapshot $snapshot Current snapshot
     * @param int $version Optimistic lock version (>= 1)
     * @param DateTimeImmutable $createdAt Instant of the start event
     * @param DateTimeImmutable $updatedAt Instant of the last accepted event or migration
     * @param string|null $tenant Tenant (2.7.0): non-empty, at most 120 characters; null = none
     * @throws InvalidArgumentException When the id, the version or the tenant is invalid
     */
    public function __construct(
        string $instanceId,
        public readonly MachineSnapshot $snapshot,
        public readonly int $version,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        ?string $tenant = null
    ) {
        $this->instanceId = PersistenceSupport::assertInstanceId($instanceId);
        if ($version < 1) {
            throw new InvalidArgumentException(sprintf('The version of machine instance "%s" must be >= 1 (got %d).', $instanceId, $version));
        }
        $this->createdAt = PersistenceSupport::utc($createdAt);
        $this->updatedAt = PersistenceSupport::utc($updatedAt);
        $this->tenant = $tenant === null ? null : PersistenceSupport::assertTenant($tenant);
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
     * Returns a copy with the next snapshot, version + 1 and the given change instant (the tenant is kept).
     */
    public function next(MachineSnapshot $snapshot, DateTimeImmutable $updatedAt): self
    {
        return new self($this->instanceId, $snapshot, $this->version + 1, $this->createdAt, $updatedAt, $this->tenant);
    }

    /**
     * Returns the JSON-friendly array form.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [
            'instance_id' => $this->instanceId,
            'version' => $this->version,
            'snapshot' => $this->snapshot->toArray(),
            'created_at' => PersistenceSupport::toText($this->createdAt),
            'updated_at' => PersistenceSupport::toText($this->updatedAt),
        ];
        if ($this->tenant !== null) {
            $array['tenant'] = $this->tenant;
        }

        return $array;
    }
}
