<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use Antevemus\ASpecification\StateMachine\Calendar\DeadlineCalendarRegistry;
use Antevemus\ASpecification\StateMachine\DefinitionSupport;
use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\GuardRegistry;
use Antevemus\ASpecification\StateMachine\MachineDefinition;
use Antevemus\ASpecification\StateMachine\MachineDefinitionValidator;
use Antevemus\ASpecification\StateMachine\MachineValidationReport;
use InvalidArgumentException;

/**
 * DefinitionDraftSupport - The Rules Shared by Every IMachineDefinitionDrafts (2.7.0)
 *
 * One place for what a draft is and how it becomes a version, so that the InMemory and the PDO
 * repositories answer the same: the checks of a saved array, the definition a draft would publish
 * (its key and the next version) and its report.
 *
 * @internal
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DefinitionDraftSupport
{
    private function __construct()
    {
    }

    /**
     * Checks the array of a draft: JSON-friendly, and its key (when present) is the machine key.
     *
     * @param array<string, mixed> $definition
     * @throws InvalidMachineDefinitionException When the key is not a name or the array is not JSON-friendly
     * @throws InvalidArgumentException When the array names another machine key
     */
    public static function assertDraft(string $machineKey, array $definition): void
    {
        DefinitionSupport::assertName($machineKey, 'machine key');
        DefinitionSupport::assertJsonFriendly($definition, "the draft of machine '{$machineKey}'");
        if (array_key_exists('key', $definition) && $definition['key'] !== $machineKey) {
            throw new InvalidArgumentException(sprintf(
                'The draft of machine "%s" names another machine key (%s).',
                $machineKey,
                is_string($definition['key']) ? '"' . $definition['key'] . '"' : get_debug_type($definition['key'])
            ));
        }
    }

    /**
     * The definition the draft would publish: its array with the machine key and the given version.
     *
     * @param array<string, mixed> $definition
     * @throws InvalidMachineDefinitionException When the array does not build a definition
     */
    private static function build(string $machineKey, array $definition, int $version): MachineDefinition
    {
        $definition['key'] = $machineKey;
        $definition['version'] = $version;

        return MachineDefinition::fromArray($definition);
    }

    /**
     * The report of the draft: the errors of the array that does not build a definition, or
     * MachineDefinitionValidator::analyze() of the definition with the machine key (the version does
     * not change the report: it is given on publication).
     *
     * @param array<string, mixed> $definition
     * @param list<string>|null $knownActions
     * @param DeadlineCalendarRegistry|null $calendars Calendars the machine runs with; null skips the calendar check
     */
    public static function analyze(string $machineKey, array $definition, GuardRegistry $guards, ?array $knownActions, ?DeadlineCalendarRegistry $calendars = null): MachineValidationReport
    {
        try {
            $built = self::build($machineKey, $definition, 1);
        } catch (InvalidMachineDefinitionException $e) {
            return new MachineValidationReport($e->getErrors(), $e->getWarnings());
        }

        return MachineDefinitionValidator::analyze($built, $guards, $knownActions, $calendars);
    }

    /**
     * Builds and validates the definition of a publication: the warnings, or the exception with every
     * error.
     *
     * @param array<string, mixed> $definition
     * @param list<string>|null $knownActions
     * @return array{MachineDefinition, list<\Antevemus\ASpecification\StateMachine\MachineDefinitionWarning>}
     * @throws InvalidMachineDefinitionException
     * @throws InvalidArgumentException When the reason is blank
     */
    public static function publishable(string $machineKey, array $definition, int $version, GuardRegistry $guards, ?array $knownActions, string $reason, ?DeadlineCalendarRegistry $calendars = null): array
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(sprintf('Publishing machine "%s" needs a reason.', $machineKey));
        }
        $built = self::build($machineKey, $definition, $version);

        return [$built, MachineDefinitionValidator::validate($built, $guards, $knownActions, $calendars)];
    }
}
