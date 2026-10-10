<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;

/**
 * MachineDefinition - Serializable Definition of a Spec-Driven State Machine
 *
 * The machine is data (forward 023, D3): a stable `key`, an integer `version` and its ordered
 * regions. It is built with the fluent MachineBuilder (Spec::machine()) or loaded from an array /
 * JSON, and `fromArray(toArray($d)) == $d` without loss (RN-11). Guards and actions are referenced
 * by name plus parameters, never by closure. The fingerprint is a hash of the canonical array
 * (key order independent) that detects a definition changed without a version bump.
 *
 * Structural invariants checked on construction: at least one region, region names unique,
 * state names unique within a region, transition ids unique within the machine, version >= 1.
 * Semantic checks (targets, initial states, guards, final states...) belong to
 * MachineDefinitionValidator, so that a faulty definition can be loaded and fully reported.
 *
 * Features:
 * - Lossless array/JSON round-trip, JSON-friendly by construction
 * - Deterministic fingerprint (sha256 of the canonical JSON)
 * - Lookup of regions and transitions by name/id
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MachineDefinition
{
    public readonly string $key;
    public readonly int $version;

    /** @var list<RegionDefinition> */
    public readonly array $regions;

    /** @var array<string, RegionDefinition> */
    private array $regionsByName = [];

    /** @var array<string, array{region: string, state: string|null, transition: TransitionDefinition}> */
    private array $transitionsById = [];

    /**
     * @param string $key Stable machine key
     * @param int $version Definition version (>= 1)
     * @param list<RegionDefinition> $regions Regions, in declaration order (at least one)
     * @throws InvalidMachineDefinitionException
     */
    public function __construct(string $key, int $version, array $regions)
    {
        $this->key = DefinitionSupport::assertName($key, 'machine key');
        if ($version < 1) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('The version of machine "%s" must be >= 1 (got %d).', $key, $version)
            );
        }
        $this->version = $version;
        if ($regions === []) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('Machine "%s" must declare at least one region.', $key)
            );
        }

        foreach ($regions as $region) {
            if (!$region instanceof RegionDefinition) {
                throw InvalidMachineDefinitionException::single(
                    MachineDefinitionError::KIND_MALFORMED,
                    sprintf('The regions of machine "%s" must be RegionDefinition instances.', $key)
                );
            }
            if (isset($this->regionsByName[$region->name])) {
                throw InvalidMachineDefinitionException::single(
                    MachineDefinitionError::KIND_DUPLICATE,
                    sprintf('Machine "%s" declares region "%s" twice.', $key, $region->name),
                    $region->name
                );
            }
            $this->regionsByName[$region->name] = $region;

            foreach ($region->states as $state) {
                foreach ($state->transitions as $transition) {
                    $this->indexTransition($region->name, $state->name, $transition);
                }
            }
            foreach ($region->transitions as $transition) {
                $this->indexTransition($region->name, null, $transition);
            }
        }
        $this->regions = array_values($regions);
    }

    /**
     * Returns the region with the given name, or null.
     */
    public function getRegion(string $name): ?RegionDefinition
    {
        return $this->regionsByName[$name] ?? null;
    }

    /**
     * Returns the region names, in declaration order.
     *
     * @return list<string>
     */
    public function regionNames(): array
    {
        return array_map(static fn(RegionDefinition $r): string => $r->name, $this->regions);
    }

    /**
     * Returns the transition with the given id, or null.
     */
    public function getTransition(string $id): ?TransitionDefinition
    {
        return ($this->transitionsById[$id] ?? null)['transition'] ?? null;
    }

    /**
     * Returns every transition with its location, in declaration order (per region: the state
     * transitions in state order, then the region-level ones).
     *
     * @return list<array{region: string, state: string|null, transition: TransitionDefinition}>
     */
    public function allTransitions(): array
    {
        return array_values($this->transitionsById);
    }

    /**
     * Returns the canonical array form (JSON-friendly).
     *
     * @return array{key: string, version: int, regions: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'regions' => array_map(static fn(RegionDefinition $r): array => $r->toArray(), $this->regions),
        ];
    }

    /**
     * Encodes the definition as JSON (canonical array form, pretty printed by default).
     *
     * @param bool $pretty Whether to pretty print
     * @return string
     */
    public function toJson(bool $pretty = true): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        return json_encode($this->toArray(), $pretty ? $flags | JSON_PRETTY_PRINT : $flags);
    }

    /**
     * Deterministic fingerprint: sha256 of the canonical JSON of toArray(). Independent of the key
     * order of the source array; any semantic change (a state, a guard parameter, the order of
     * transitions) changes it.
     *
     * @return string 64 hexadecimal characters
     */
    public function fingerprint(): string
    {
        return hash('sha256', DefinitionSupport::canonicalJson($this->toArray()));
    }

    /**
     * Hydrates a definition from its array form. Missing optional fields take their defaults and
     * transitions without id receive a deterministic generated id (`region.state.EVENT`).
     *
     * @param array<string, mixed> $data
     * @return self
     * @throws InvalidMachineDefinitionException
     */
    public static function fromArray(array $data): self
    {
        $key = DefinitionSupport::requireString($data, 'key', 'a machine definition');
        $version = $data['version'] ?? null;
        if (!is_int($version)) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('The version of machine "%s" is required and must be an integer.', $key)
            );
        }

        $regionsData = DefinitionSupport::optionalList($data, 'regions', "machine '{$key}'");
        $takenIds = self::explicitIds($regionsData);

        $regions = [];
        foreach ($regionsData as $region) {
            if (!is_array($region)) {
                throw InvalidMachineDefinitionException::single(
                    MachineDefinitionError::KIND_MALFORMED,
                    sprintf('A region of machine "%s" must be an object.', $key)
                );
            }
            $regions[] = RegionDefinition::fromArray($region, $takenIds);
        }

        return new self($key, $version, $regions);
    }

    /**
     * Hydrates a definition from JSON.
     *
     * @param string $json JSON document
     * @return self
     * @throws InvalidMachineDefinitionException
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                'The machine definition is not valid JSON: ' . $e->getMessage()
            );
        }
        if (!is_array($data)) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                'The machine definition JSON must be an object.'
            );
        }

        return self::fromArray($data);
    }

    /**
     * Collects the transition ids declared explicitly, so generated ids never collide with them.
     *
     * @param list<mixed> $regionsData
     * @return array<string, true>
     */
    private static function explicitIds(array $regionsData): array
    {
        $ids = [];
        $collect = static function (mixed $transitions) use (&$ids): void {
            if (!is_array($transitions)) {
                return;
            }
            foreach ($transitions as $t) {
                if (is_array($t) && isset($t['id']) && is_string($t['id'])) {
                    $ids[$t['id']] = true;
                }
            }
        };
        foreach ($regionsData as $region) {
            if (!is_array($region)) {
                continue;
            }
            $collect($region['transitions'] ?? null);
            foreach ((is_array($region['states'] ?? null) ? $region['states'] : []) as $state) {
                if (is_array($state)) {
                    $collect($state['transitions'] ?? null);
                }
            }
        }

        return $ids;
    }

    private function indexTransition(string $region, ?string $state, TransitionDefinition $transition): void
    {
        if (isset($this->transitionsById[$transition->id])) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_DUPLICATE,
                sprintf('Machine "%s" declares transition id "%s" more than once.', $this->key, $transition->id),
                $region,
                $state,
                $transition->id
            );
        }
        $this->transitionsById[$transition->id] = ['region' => $region, 'state' => $state, 'transition' => $transition];
    }
}
