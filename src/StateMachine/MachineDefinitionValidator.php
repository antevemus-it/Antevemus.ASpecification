<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\Comparison\AbstractComparableValueBoundSpecification;
use Antevemus\ASpecification\Specifications\Comparison\InSpecification;
use Antevemus\ASpecification\Specifications\Comparison\IsNullSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotNullSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;

/**
 * MachineDefinitionValidator - Load-Time Validation of a Machine Definition
 *
 * Refuses a definition with the complete list of its errors and reports non-blocking warnings
 * (forward 023, D17). Pure: no I/O, no clock; guard factories are called to build the guard
 * specifications (the algebra checks need them), never to evaluate them.
 *
 * Errors (InvalidMachineDefinitionException listing all of them):
 * - region without initial state; initial state that is not a state of the region
 * - transition target that does not exist in its region (cross-region target reported as such,
 *   for state-level and region-level transitions alike)
 * - inState guard naming a region or state that does not exist
 * - guard without factory in the GuardRegistry, or whose factory fails with the declared params
 * - action not in the list of known actions (only when the list is given)
 * - final state with outgoing transitions (final states are inert)
 *
 * Warnings (list of MachineDefinitionWarning):
 * - state unreachable from the initial state of its region
 * - non-final state without exit (no transition to another state, own or region-level)
 * - two guarded transitions for the same event in the same list (a state's transitions, or the
 *   region-level ones) whose guards are both in the algebra's decidable fragment and are not
 *   proven disjoint: the declaration order decides
 * - transition without guard (or with a guard proven a tautology) followed by another for the
 *   same event in the same list: the later one never runs
 * - declared guard proven a tautology by the algebra (always passes, e.g. an empty role list)
 *
 * The decidable fragment: AlwaysTrue/AlwaysFalse, the built-in inState and eventOrigin leaves,
 * comparison and set leaves (equalTo, in, ranges, null checks), property restrictions over those,
 * and and/or/not of decidable operands. Outside it (closures, method calls, custom classes) the
 * algebra cannot decide and no overlap warning is issued.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MachineDefinitionValidator
{
    private function __construct()
    {
    }

    /**
     * Validates the definition: throws with every error, returns the warnings otherwise.
     *
     * @param MachineDefinition $definition Definition to validate
     * @param GuardRegistry $guards Guard factories the definition will run with
     * @param list<string>|null $knownActions Action names that have an implementation; null skips the check
     * @return list<MachineDefinitionWarning>
     * @throws InvalidMachineDefinitionException When the definition has at least one error
     */
    public static function validate(
        MachineDefinition $definition,
        GuardRegistry $guards,
        ?array $knownActions = null
    ): array {
        $report = self::analyze($definition, $guards, $knownActions);
        if (!$report->isValid()) {
            throw new InvalidMachineDefinitionException(
                $report->errors,
                $report->warnings,
                sprintf("machine '%s' v%d", $definition->key, $definition->version)
            );
        }

        return $report->warnings;
    }

    /**
     * Analyzes the definition without throwing.
     *
     * @param MachineDefinition $definition Definition to analyze
     * @param GuardRegistry $guards Guard factories the definition will run with
     * @param list<string>|null $knownActions Action names that have an implementation; null skips the check
     * @return MachineValidationReport
     */
    public static function analyze(
        MachineDefinition $definition,
        GuardRegistry $guards,
        ?array $knownActions = null
    ): MachineValidationReport {
        $errors = [];
        $warnings = [];
        $actionSet = $knownActions === null ? null : array_fill_keys($knownActions, true);

        foreach ($definition->regions as $region) {
            self::checkInitial($region, $errors);

            foreach ($region->states as $state) {
                if ($state->final && $state->transitions !== []) {
                    $errors[] = new MachineDefinitionError(
                        MachineDefinitionError::KIND_FINAL_STATE_WITH_EXIT,
                        sprintf(
                            "Final state '%s' of region '%s' declares %d transition(s) (%s): final states are inert; model a reopening as a non-final state.",
                            $state->name,
                            $region->name,
                            count($state->transitions),
                            implode(', ', array_map(static fn(TransitionDefinition $t): string => $t->id, $state->transitions))
                        ),
                        $region->name,
                        $state->name
                    );
                }
                foreach ([...$state->entry, ...$state->exit] as $action) {
                    self::checkAction($action, $actionSet, $region->name, $state->name, null, $errors);
                }
                foreach ($state->transitions as $transition) {
                    self::checkTransition($definition, $region, $state->name, $transition, $guards, $actionSet, $errors);
                }
            }
            foreach ($region->transitions as $transition) {
                self::checkTransition($definition, $region, null, $transition, $guards, $actionSet, $errors);
            }
        }

        // Warnings only make sense on a structurally sound definition, but they are computed
        // regardless so the author sees everything at once; algebra checks skip unbuildable guards.
        $compiled = self::compileGuards($definition, $guards);
        foreach ($definition->regions as $region) {
            self::warnReachability($region, $warnings);
            self::warnDeadEnds($region, $warnings);
            foreach ($region->states as $state) {
                self::warnOrdering($region, $state->name, $state->transitions, $compiled, $warnings);
            }
            self::warnOrdering($region, null, $region->transitions, $compiled, $warnings);
            self::warnTautologies($region, $compiled, $warnings);
        }

        return new MachineValidationReport($errors, $warnings);
    }

    /**
     * True when the specification belongs to the fragment the algebra can decide (see class doc).
     *
     * @param ISpecification $specification Specification to inspect
     * @return bool
     */
    public static function isDecidable(ISpecification $specification): bool
    {
        return match (true) {
            $specification instanceof AlwaysTrueSpecification,
            $specification instanceof AlwaysFalseSpecification,
            $specification instanceof InStateSpecification,
            $specification instanceof EventOriginSpecification,
            $specification instanceof AbstractComparableValueBoundSpecification,
            $specification instanceof InSpecification,
            $specification instanceof IsNullSpecification,
            $specification instanceof NotNullSpecification => true,
            $specification instanceof NotSpecification => self::isDecidable($specification->getSpecification()),
            $specification instanceof AndSpecification,
            $specification instanceof OrSpecification => self::isDecidable($specification->getLeftSide())
                && self::isDecidable($specification->getRightSide()),
            $specification instanceof PropertySpecification => self::isDecidable($specification->getPropertySpecification())
                && self::isDecidable($specification->getBaseSpecification()),
            default => false,
        };
    }

    /**
     * @param list<MachineDefinitionError> $errors
     */
    private static function checkInitial(RegionDefinition $region, array &$errors): void
    {
        if ($region->initial === null || $region->initial === '') {
            $errors[] = new MachineDefinitionError(
                MachineDefinitionError::KIND_MISSING_INITIAL,
                sprintf("Region '%s' declares no initial state.", $region->name),
                $region->name
            );
        } elseif (!$region->hasState($region->initial)) {
            $errors[] = new MachineDefinitionError(
                MachineDefinitionError::KIND_UNKNOWN_INITIAL,
                sprintf("The initial state '%s' of region '%s' is not one of its states.", $region->initial, $region->name),
                $region->name,
                $region->initial
            );
        }
    }

    /**
     * @param array<string, true>|null $actionSet
     * @param list<MachineDefinitionError> $errors
     */
    private static function checkAction(
        ActionRef $action,
        ?array $actionSet,
        string $region,
        ?string $state,
        ?string $transitionId,
        array &$errors
    ): void {
        if ($actionSet !== null && !isset($actionSet[$action->name])) {
            $errors[] = new MachineDefinitionError(
                MachineDefinitionError::KIND_UNKNOWN_ACTION,
                sprintf(
                    "Action '%s' (%s) has no registered implementation.",
                    $action->name,
                    $transitionId !== null ? "transition '{$transitionId}'" : "state '{$region}.{$state}'"
                ),
                $region,
                $state,
                $transitionId
            );
        }
    }

    /**
     * @param array<string, true>|null $actionSet
     * @param list<MachineDefinitionError> $errors
     */
    private static function checkTransition(
        MachineDefinition $definition,
        RegionDefinition $region,
        ?string $state,
        TransitionDefinition $transition,
        GuardRegistry $guards,
        ?array $actionSet,
        array &$errors
    ): void {
        if ($transition->target !== null && !$region->hasState($transition->target)) {
            $owner = null;
            foreach ($definition->regions as $other) {
                if ($other->name !== $region->name && $other->hasState($transition->target)) {
                    $owner = $other->name;
                    break;
                }
            }
            $errors[] = $owner !== null
                ? new MachineDefinitionError(
                    MachineDefinitionError::KIND_CROSS_REGION_TARGET,
                    sprintf(
                        "Transition '%s' of region '%s' targets state '%s', which belongs to region '%s': a transition cannot leave its region.",
                        $transition->id,
                        $region->name,
                        $transition->target,
                        $owner
                    ),
                    $region->name,
                    $state,
                    $transition->id
                )
                : new MachineDefinitionError(
                    MachineDefinitionError::KIND_UNKNOWN_TARGET,
                    sprintf(
                        "Transition '%s' of region '%s' targets unknown state '%s'.",
                        $transition->id,
                        $region->name,
                        $transition->target
                    ),
                    $region->name,
                    $state,
                    $transition->id
                );
        }

        foreach ($transition->actions as $action) {
            self::checkAction($action, $actionSet, $region->name, $state, $transition->id, $errors);
        }

        if ($transition->guard === null) {
            return;
        }

        foreach ($transition->guard->refs() as $ref) {
            if (!$guards->has($ref->name)) {
                $errors[] = new MachineDefinitionError(
                    MachineDefinitionError::KIND_UNKNOWN_GUARD,
                    sprintf("Guard '%s' of transition '%s' has no registered factory.", $ref->name, $transition->id),
                    $region->name,
                    $state,
                    $transition->id
                );
                continue;
            }
            if ($ref->name === GuardRegistry::IN_STATE) {
                $path = $ref->params['state'] ?? null;
                $parts = is_string($path) ? explode('.', $path) : [];
                $target = count($parts) === 2 ? $definition->getRegion($parts[0]) : null;
                if ($target === null || !$target->hasState($parts[1])) {
                    $errors[] = new MachineDefinitionError(
                        MachineDefinitionError::KIND_UNKNOWN_IN_STATE,
                        sprintf(
                            "Guard inState('%s') of transition '%s' references a region or state that does not exist.",
                            is_string($path) ? $path : json_encode($path),
                            $transition->id
                        ),
                        $region->name,
                        $state,
                        $transition->id
                    );
                    continue;
                }
            }
            try {
                $guards->build($ref->name, $ref->params);
            } catch (\Throwable $e) {
                $errors[] = new MachineDefinitionError(
                    MachineDefinitionError::KIND_GUARD_BUILD_FAILED,
                    sprintf(
                        "Guard '%s' of transition '%s' cannot be built with its parameters: %s",
                        $ref->name,
                        $transition->id,
                        $e->getMessage()
                    ),
                    $region->name,
                    $state,
                    $transition->id
                );
            }
        }
    }

    /**
     * Compiles every buildable guard once, keyed by transition id.
     *
     * @return array<string, ISpecification>
     */
    private static function compileGuards(MachineDefinition $definition, GuardRegistry $guards): array
    {
        $compiled = [];
        foreach ($definition->allTransitions() as $entry) {
            $transition = $entry['transition'];
            if ($transition->guard === null) {
                continue;
            }
            try {
                $compiled[$transition->id] = $guards->compile($transition->guard);
            } catch (\Throwable) {
                // Reported as an error by checkTransition(); no algebra check on it.
            }
        }

        return $compiled;
    }

    /**
     * @param list<MachineDefinitionWarning> $warnings
     */
    private static function warnReachability(RegionDefinition $region, array &$warnings): void
    {
        if ($region->initial === null || !$region->hasState($region->initial)) {
            return;
        }

        $reachable = [$region->initial => true];
        $queue = [$region->initial];
        $regionTargetsAdded = false;
        while ($queue !== []) {
            $name = array_shift($queue);
            $state = $region->getState($name);
            if ($state === null) {
                continue;
            }
            $targets = [];
            foreach ($state->transitions as $t) {
                if ($t->target !== null) {
                    $targets[] = $t->target;
                }
            }
            if (!$state->final && !$regionTargetsAdded) {
                // Region-level transitions apply from any non-final state.
                $regionTargetsAdded = true;
                foreach ($region->transitions as $t) {
                    if ($t->target !== null) {
                        $targets[] = $t->target;
                    }
                }
            }
            foreach ($targets as $target) {
                if (!isset($reachable[$target]) && $region->hasState($target)) {
                    $reachable[$target] = true;
                    $queue[] = $target;
                }
            }
        }

        foreach ($region->states as $state) {
            if (!isset($reachable[$state->name])) {
                $warnings[] = new MachineDefinitionWarning(
                    MachineDefinitionWarning::KIND_UNREACHABLE_STATE,
                    sprintf(
                        "State '%s' of region '%s' cannot be reached from the initial state '%s'.",
                        $state->name,
                        $region->name,
                        $region->initial
                    ),
                    $region->name,
                    $state->name
                );
            }
        }
    }

    /**
     * @param list<MachineDefinitionWarning> $warnings
     */
    private static function warnDeadEnds(RegionDefinition $region, array &$warnings): void
    {
        $regionExit = static fn(string $state): bool => array_filter(
            $region->transitions,
            static fn(TransitionDefinition $t): bool => $t->target !== null && $t->target !== $state
        ) !== [];

        foreach ($region->states as $state) {
            if ($state->final) {
                continue;
            }
            $ownExit = array_filter(
                $state->transitions,
                static fn(TransitionDefinition $t): bool => $t->target !== null && $t->target !== $state->name
            ) !== [];
            if (!$ownExit && !$regionExit($state->name)) {
                $warnings[] = new MachineDefinitionWarning(
                    MachineDefinitionWarning::KIND_DEAD_END_STATE,
                    sprintf(
                        "State '%s' of region '%s' is not final and has no transition leaving it: an instance that enters it gets stuck.",
                        $state->name,
                        $region->name
                    ),
                    $region->name,
                    $state->name
                );
            }
        }
    }

    /**
     * Shadowed transitions and overlapping guards, within one ordered list of transitions.
     *
     * @param list<TransitionDefinition> $transitions
     * @param array<string, ISpecification> $compiled
     * @param list<MachineDefinitionWarning> $warnings
     */
    private static function warnOrdering(
        RegionDefinition $region,
        ?string $state,
        array $transitions,
        array $compiled,
        array &$warnings
    ): void {
        $where = $state === null ? "the region-level transitions of '{$region->name}'" : "state '{$region->name}.{$state}'";
        $count = count($transitions);
        for ($j = 1; $j < $count; $j++) {
            $later = $transitions[$j];
            for ($i = 0; $i < $j; $i++) {
                $earlier = $transitions[$i];
                if ($earlier->event !== $later->event) {
                    continue;
                }
                $earlierAlwaysPasses = $earlier->guard === null
                    || (isset($compiled[$earlier->id]) && $compiled[$earlier->id]->isTautology());
                if ($earlierAlwaysPasses) {
                    $warnings[] = new MachineDefinitionWarning(
                        MachineDefinitionWarning::KIND_SHADOWED_TRANSITION,
                        sprintf(
                            "In %s, transition '%s' for event '%s' %s and precedes transition '%s': '%s' never runs.",
                            $where,
                            $earlier->id,
                            $later->event,
                            $earlier->guard === null ? 'has no guard' : 'has a guard that always passes',
                            $later->id,
                            $later->id
                        ),
                        $region->name,
                        $state,
                        $later->id,
                        $later->event
                    );
                    break;
                }
                if ($later->guard === null || !isset($compiled[$earlier->id], $compiled[$later->id])) {
                    continue;
                }
                $a = $compiled[$earlier->id];
                $b = $compiled[$later->id];
                if (self::isDecidable($a) && self::isDecidable($b) && !$a->isDisjointWith($b) && !$b->isDisjointWith($a)) {
                    $warnings[] = new MachineDefinitionWarning(
                        MachineDefinitionWarning::KIND_OVERLAPPING_GUARDS,
                        sprintf(
                            "In %s, the guards of transitions '%s' and '%s' for event '%s' are not disjoint: when both pass, the declaration order picks '%s'.",
                            $where,
                            $earlier->id,
                            $later->id,
                            $later->event,
                            $earlier->id
                        ),
                        $region->name,
                        $state,
                        $later->id,
                        $later->event
                    );
                }
            }
        }
    }

    /**
     * @param array<string, ISpecification> $compiled
     * @param list<MachineDefinitionWarning> $warnings
     */
    private static function warnTautologies(RegionDefinition $region, array $compiled, array &$warnings): void
    {
        $lists = [];
        foreach ($region->states as $state) {
            $lists[] = [$state->name, $state->transitions];
        }
        $lists[] = [null, $region->transitions];

        foreach ($lists as [$state, $transitions]) {
            foreach ($transitions as $transition) {
                if (isset($compiled[$transition->id]) && $compiled[$transition->id]->isTautology()) {
                    $warnings[] = new MachineDefinitionWarning(
                        MachineDefinitionWarning::KIND_TAUTOLOGICAL_GUARD,
                        sprintf(
                            "The guard of transition '%s' (event '%s') always passes: anyone may take it. Make sure this is intended.",
                            $transition->id,
                            $transition->event
                        ),
                        $region->name,
                        $state,
                        $transition->id,
                        $transition->event
                    );
                }
            }
        }
    }
}
