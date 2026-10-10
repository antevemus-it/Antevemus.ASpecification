<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\StateMachine\Exceptions\InvalidSnapshotException;
use DateTimeImmutable;

/**
 * MachineSnapshot - Immutable State of a Machine Instance
 *
 * What the machine keeps between events (forward 023, §5: no machine context, the domain object
 * belongs to the caller): the machine key and the version the instance was born with (D16), the
 * current state of every region (D2), the due instant of each region's deadline (D18, RN-06) and
 * the `done` flag (every region in a final state, D10). Immutable: every change returns a copy
 * through the with* methods (RN-02).
 *
 * Array form: `{"machine_key", "machine_version", "states": {region: state}, "due_at": {region: iso|null}, "done"}`.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MachineSnapshot
{
    /** Serialization format of instants (microseconds and offset, lossless). */
    public const DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

    /** @var array<string, string> */
    private array $states;

    /** @var array<string, DateTimeImmutable|null> */
    private array $dueAt;

    /**
     * @param string $machineKey Key of the machine definition
     * @param int $machineVersion Version of the machine definition
     * @param array<string, string> $states Current state per region, in region order
     * @param array<string, DateTimeImmutable|null> $dueAt Due instant per region (missing = null)
     * @param bool $done Whether every region is in a final state
     * @throws InvalidSnapshotException When the arrays are malformed
     */
    public function __construct(
        public readonly string $machineKey,
        public readonly int $machineVersion,
        array $states,
        array $dueAt = [],
        public readonly bool $done = false
    ) {
        if ($states === []) {
            throw new InvalidSnapshotException('A snapshot must hold the state of at least one region.');
        }
        foreach ($states as $region => $state) {
            if (!is_string($region) || !is_string($state) || $state === '') {
                throw new InvalidSnapshotException('Snapshot states must map region names to state names.');
            }
        }
        $normalizedDue = [];
        foreach ($states as $region => $_) {
            $due = $dueAt[$region] ?? null;
            if ($due !== null && !$due instanceof DateTimeImmutable) {
                throw new InvalidSnapshotException(sprintf('The due instant of region "%s" must be a DateTimeImmutable.', $region));
            }
            $normalizedDue[$region] = $due;
        }
        foreach ($dueAt as $region => $_) {
            if (!isset($states[$region])) {
                throw new InvalidSnapshotException(sprintf('Due instant given for unknown region "%s".', (string) $region));
            }
        }
        $this->states = $states;
        $this->dueAt = $normalizedDue;
    }

    /** Machine key. */
    public function getMachineKey(): string
    {
        return $this->machineKey;
    }

    /** Machine version the instance is pinned to. */
    public function getMachineVersion(): int
    {
        return $this->machineVersion;
    }

    /**
     * Current state per region, in region order.
     *
     * @return array<string, string>
     */
    public function getStates(): array
    {
        return $this->states;
    }

    /**
     * Region names, in order.
     *
     * @return list<string>
     */
    public function regions(): array
    {
        return array_keys($this->states);
    }

    /**
     * Current state of a region.
     *
     * @throws InvalidSnapshotException When the region is unknown
     */
    public function stateOf(string $region): string
    {
        if (!isset($this->states[$region])) {
            throw new InvalidSnapshotException(sprintf('The snapshot has no region "%s".', $region));
        }

        return $this->states[$region];
    }

    /**
     * True when the region is in the state. Accepts `isIn('region', 'state')` or `isIn('region.state')`.
     *
     * @param string $regionOrPath Region name, or "region.state"
     * @param string|null $state State name when the first argument is a region
     * @return bool
     */
    public function isIn(string $regionOrPath, ?string $state = null): bool
    {
        if ($state === null) {
            $parts = explode('.', $regionOrPath, 2);
            if (count($parts) !== 2) {
                return false;
            }
            [$regionOrPath, $state] = $parts;
        }

        return ($this->states[$regionOrPath] ?? null) === $state;
    }

    /**
     * Due instant of the region's deadline, or null.
     */
    public function dueAt(string $region): ?DateTimeImmutable
    {
        return $this->dueAt[$region] ?? null;
    }

    /**
     * Due instants per region (null when the current state has no deadline).
     *
     * @return array<string, DateTimeImmutable|null>
     */
    public function getDueAt(): array
    {
        return $this->dueAt;
    }

    /** True when every region is in a final state. */
    public function isDone(): bool
    {
        return $this->done;
    }

    /**
     * Returns a copy with the region in the given state (and the given due instant).
     *
     * @param string $region Region name
     * @param string $state New state
     * @param DateTimeImmutable|null $dueAt Due instant of the new state's deadline
     * @return self
     */
    public function withState(string $region, string $state, ?DateTimeImmutable $dueAt = null): self
    {
        $this->stateOf($region);
        $states = $this->states;
        $states[$region] = $state;
        $due = $this->dueAt;
        $due[$region] = $dueAt;

        return new self($this->machineKey, $this->machineVersion, $states, $due, $this->done);
    }

    /**
     * Returns a copy with the region's due instant replaced.
     */
    public function withDueAt(string $region, ?DateTimeImmutable $dueAt): self
    {
        $this->stateOf($region);
        $due = $this->dueAt;
        $due[$region] = $dueAt;

        return new self($this->machineKey, $this->machineVersion, $this->states, $due, $this->done);
    }

    /**
     * Returns a copy with the done flag replaced.
     */
    public function withDone(bool $done): self
    {
        return new self($this->machineKey, $this->machineVersion, $this->states, $this->dueAt, $done);
    }

    /**
     * Returns the JSON-friendly array form.
     *
     * @return array{machine_key: string, machine_version: int, states: array<string, string>, due_at: array<string, string|null>, done: bool}
     */
    public function toArray(): array
    {
        return [
            'machine_key' => $this->machineKey,
            'machine_version' => $this->machineVersion,
            'states' => $this->states,
            'due_at' => array_map(
                static fn(?DateTimeImmutable $d): ?string => $d?->format(self::DATE_FORMAT),
                $this->dueAt
            ),
            'done' => $this->done,
        ];
    }

    /**
     * Hydrates a snapshot from its array form.
     *
     * @param array<string, mixed> $data
     * @return self
     * @throws InvalidSnapshotException
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['machine_key'], $data['machine_version'], $data['states'])
            || !is_string($data['machine_key']) || !is_int($data['machine_version']) || !is_array($data['states'])) {
            throw new InvalidSnapshotException('A snapshot array needs machine_key (string), machine_version (int) and states (object).');
        }
        $due = [];
        foreach ((array) ($data['due_at'] ?? []) as $region => $value) {
            if ($value === null) {
                $due[$region] = null;
                continue;
            }
            if (!is_string($value)) {
                throw new InvalidSnapshotException(sprintf('The due instant of region "%s" must be a string.', (string) $region));
            }
            try {
                $due[$region] = new DateTimeImmutable($value);
            } catch (\Throwable) {
                throw new InvalidSnapshotException(sprintf('The due instant of region "%s" is not a valid date: %s', (string) $region, $value));
            }
        }

        return new self(
            $data['machine_key'],
            $data['machine_version'],
            $data['states'],
            $due,
            (bool) ($data['done'] ?? false)
        );
    }
}
