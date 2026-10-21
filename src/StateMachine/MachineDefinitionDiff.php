<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use InvalidArgumentException;

/**
 * MachineDefinitionDiff - Structural Difference Between Two Versions of a Machine (2.7.0)
 *
 * What changed from one published version to another (MachineDefinition::diff()): the regions,
 * states and transitions added, removed or changed. A screen that edits a definition shows it before
 * publishing, and it decides the migration of the live instances: when no state they are in was
 * removed, MachineMigrations::byStateName() moves them without code.
 *
 * Identity: a region by name, a state by "region.state", a transition by id (ids are unique in the
 * machine). "Changed" compares the canonical array form: a region whose initial state changed; a
 * state whose final flag, actions, deadline or code changed (its transitions are compared on their
 * own); a transition whose event, target, guard, actions, internal flag or place (region and state)
 * changed. Lists keep the declaration order of the version that has the item.
 *
 * Presentation data (label, color, icon) is not part of a definition, so it never shows here.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MachineDefinitionDiff
{
    /**
     * @param string $machineKey Machine key
     * @param int $fromVersion Version compared from
     * @param int $toVersion Version compared to
     * @param list<string> $addedRegions Regions only in the target
     * @param list<string> $removedRegions Regions only in the source
     * @param list<string> $changedRegions Regions in both whose initial state differs
     * @param list<string> $addedStates "region.state" only in the target
     * @param list<string> $removedStates "region.state" only in the source
     * @param list<string> $changedStates "region.state" in both whose definition (transitions aside) differs
     * @param list<string> $addedTransitions Transition ids only in the target
     * @param list<string> $removedTransitions Transition ids only in the source
     * @param list<string> $changedTransitions Transition ids in both whose definition or place differs
     */
    public function __construct(
        public readonly string $machineKey,
        public readonly int $fromVersion,
        public readonly int $toVersion,
        public readonly array $addedRegions = [],
        public readonly array $removedRegions = [],
        public readonly array $changedRegions = [],
        public readonly array $addedStates = [],
        public readonly array $removedStates = [],
        public readonly array $changedStates = [],
        public readonly array $addedTransitions = [],
        public readonly array $removedTransitions = [],
        public readonly array $changedTransitions = []
    ) {
    }

    /**
     * Compares two versions of the same machine.
     *
     * @param MachineDefinition $from Source version
     * @param MachineDefinition $to Target version
     * @return self
     * @throws InvalidArgumentException When the two definitions have different keys
     */
    public static function between(MachineDefinition $from, MachineDefinition $to): self
    {
        if ($from->key !== $to->key) {
            throw new InvalidArgumentException(sprintf(
                'A diff compares two versions of one machine: "%s" and "%s" are different machines.',
                $from->key,
                $to->key
            ));
        }

        $fromRegions = self::regions($from);
        $toRegions = self::regions($to);
        $fromStates = self::states($from);
        $toStates = self::states($to);
        $fromTransitions = self::transitions($from);
        $toTransitions = self::transitions($to);

        return new self(
            $from->key,
            $from->version,
            $to->version,
            self::only($toRegions, $fromRegions),
            self::only($fromRegions, $toRegions),
            self::changed($fromRegions, $toRegions),
            self::only($toStates, $fromStates),
            self::only($fromStates, $toStates),
            self::changed($fromStates, $toStates),
            self::only($toTransitions, $fromTransitions),
            self::only($fromTransitions, $toTransitions),
            self::changed($fromTransitions, $toTransitions)
        );
    }

    /** True when the two versions are structurally the same. */
    public function isEmpty(): bool
    {
        return $this->addedRegions === [] && $this->removedRegions === [] && $this->changedRegions === []
            && $this->addedStates === [] && $this->removedStates === [] && $this->changedStates === []
            && $this->addedTransitions === [] && $this->removedTransitions === [] && $this->changedTransitions === [];
    }

    /**
     * Returns the JSON-friendly array form.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'machine_key' => $this->machineKey,
            'from_version' => $this->fromVersion,
            'to_version' => $this->toVersion,
            'regions' => ['added' => $this->addedRegions, 'removed' => $this->removedRegions, 'changed' => $this->changedRegions],
            'states' => ['added' => $this->addedStates, 'removed' => $this->removedStates, 'changed' => $this->changedStates],
            'transitions' => ['added' => $this->addedTransitions, 'removed' => $this->removedTransitions, 'changed' => $this->changedTransitions],
        ];
    }

    /**
     * @return array<string, string> Region name => canonical JSON of what identifies its changes
     */
    private static function regions(MachineDefinition $definition): array
    {
        $out = [];
        foreach ($definition->regions as $region) {
            $out[$region->name] = DefinitionSupport::canonicalJson(['initial' => $region->initial]);
        }

        return $out;
    }

    /**
     * @return array<string, string> "region.state" => canonical JSON of the state without its transitions
     */
    private static function states(MachineDefinition $definition): array
    {
        $out = [];
        foreach ($definition->regions as $region) {
            foreach ($region->states as $state) {
                $array = $state->toArray();
                unset($array['transitions']);
                $out[$region->name . '.' . $state->name] = DefinitionSupport::canonicalJson($array);
            }
        }

        return $out;
    }

    /**
     * @return array<string, string> Transition id => canonical JSON of the transition and its place
     */
    private static function transitions(MachineDefinition $definition): array
    {
        $out = [];
        foreach ($definition->allTransitions() as $entry) {
            $out[$entry['transition']->id] = DefinitionSupport::canonicalJson([
                'region' => $entry['region'],
                'state' => $entry['state'],
                'transition' => $entry['transition']->toArray(),
            ]);
        }

        return $out;
    }

    /**
     * Keys of the first map missing from the second, in the order of the first.
     *
     * @param array<string, string> $in
     * @param array<string, string> $notIn
     * @return list<string>
     */
    private static function only(array $in, array $notIn): array
    {
        // Names are never integer texts (DefinitionSupport::assertName()), so the keys stay strings
        return array_keys(array_diff_key($in, $notIn));
    }

    /**
     * Keys present in both maps whose values differ, in the order of the source.
     *
     * @param array<string, string> $from
     * @param array<string, string> $to
     * @return list<string>
     */
    private static function changed(array $from, array $to): array
    {
        $changed = [];
        foreach ($from as $key => $value) {
            if (isset($to[$key]) && $to[$key] !== $value) {
                $changed[] = $key;
            }
        }

        return $changed;
    }
}
