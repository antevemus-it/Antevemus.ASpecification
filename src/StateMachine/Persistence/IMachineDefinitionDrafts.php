<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use Antevemus\ASpecification\StateMachine\Calendar\DeadlineCalendarRegistry;
use Antevemus\ASpecification\StateMachine\Exceptions\DefinitionDraftConflictException;
use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\Exceptions\UnknownMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\GuardRegistry;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * IMachineDefinitionDrafts - Draft, Validate and Publish a Definition Edited by a Screen (2.7.0)
 *
 * Opt-in capability of a definition repository (InMemoryMachineDefinitionRepository and
 * PdoMachineDefinitionRepository implement it): one draft per machine key, kept as the array the
 * screen saved, and its publication as the next immutable version.
 *
 * - Every write is a compare-and-set on the draft revision: `$expectedRevision` is the revision the
 *   screen read (null = "there is no draft yet"); a mismatch throws DefinitionDraftConflictException
 *   and writes nothing.
 * - publish() runs in one transaction: revision check, definition built from the draft with the key
 *   and the next version (`latestVersion + 1`; the `version` of the draft is ignored), validation
 *   with the guards (and actions, and calendars) the machine will run with (an error refuses and writes nothing,
 *   the warnings come back), insert of the version (another publication that took the version first
 *   is a DefinitionDraftConflictException), removal of the draft.
 * - A published version is immutable: there is no update nor delete of a version.
 * - The repository reads no clock: every instant comes from the caller.
 *
 * Presentation data of a screen (label, color, icon, reasons) does not belong to the definition: keep
 * it in the application, keyed by machine key and state name or transition id, so that changing a
 * color never creates a version.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IMachineDefinitionDrafts
{
    /**
     * Saves the draft of a machine (creates it with revision 1, or replaces it and increments the
     * revision). The array may be incomplete; its `key`, when present, must be the machine key.
     *
     * @param string $machineKey Machine key
     * @param array<string, mixed> $definition Array form of the definition being edited (JSON-friendly)
     * @param int|null $expectedRevision Revision the screen read; null when it created the draft
     * @param string|null $actor Who saved it
     * @param DateTimeImmutable $at Instant of the save
     * @return DraftInfo
     * @throws DefinitionDraftConflictException When the revision moved (or a draft exists and null was expected)
     * @throws InvalidMachineDefinitionException When the array is not JSON-friendly
     * @throws InvalidArgumentException When the array names another machine key
     */
    public function saveDraft(string $machineKey, array $definition, ?int $expectedRevision, ?string $actor, DateTimeImmutable $at): DraftInfo;

    /**
     * The draft of a machine with the report of the validator, or null when there is none.
     *
     * @param string $machineKey Machine key
     * @param GuardRegistry|null $guards Guards the machine will run with (null = the built-in ones)
     * @param list<string>|null $knownActions Action names with an implementation; null skips the check
     * @param DeadlineCalendarRegistry|null $calendars Calendars the machine will run with; a deadline naming
     *                                                 another one is reported (`unknown_calendar`); null skips the check
     * @return MachineDefinitionDraft|null
     */
    public function draft(string $machineKey, ?GuardRegistry $guards = null, ?array $knownActions = null, ?DeadlineCalendarRegistry $calendars = null): ?MachineDefinitionDraft;

    /**
     * Starts (or replaces) the draft from a published version: its array form becomes the draft.
     *
     * @param string $machineKey Machine key
     * @param int $version Published version to copy
     * @param int|null $expectedRevision Revision of the draft being replaced; null when there is none
     * @param string|null $actor Who started it
     * @param DateTimeImmutable $at Instant
     * @return DraftInfo
     * @throws UnknownMachineDefinitionException When the version is not published
     * @throws DefinitionDraftConflictException When the revision moved
     */
    public function draftFrom(string $machineKey, int $version, ?int $expectedRevision, ?string $actor, DateTimeImmutable $at): DraftInfo;

    /**
     * Discards the draft.
     *
     * @param string $machineKey Machine key
     * @param int $expectedRevision Revision the screen read
     * @throws DefinitionDraftConflictException When the revision moved or there is no draft
     */
    public function discardDraft(string $machineKey, int $expectedRevision): void;

    /**
     * Publishes the draft as the next version (see the class description for the steps).
     *
     * @param string $machineKey Machine key
     * @param int $expectedRevision Revision the screen read
     * @param GuardRegistry $guards Guards the machine will run with (the validator builds every guard)
     * @param list<string>|null $knownActions Action names with an implementation; null skips the check
     * @param string $reason Why it is published (non-blank), kept with the version
     * @param string|null $actor Who publishes it
     * @param DateTimeImmutable $at Instant of the publication
     * @param DeadlineCalendarRegistry|null $calendars Calendars the machine will run with; a deadline naming
     *                                                 another one refuses the publication (`unknown_calendar`);
     *                                                 null skips the check
     * @return PublishedMachineDefinition
     * @throws DefinitionDraftConflictException When the revision moved, there is no draft, or another
     *                                          publication took the version first
     * @throws InvalidMachineDefinitionException When the draft does not build a definition or the validator refuses it
     * @throws InvalidArgumentException When the reason is blank
     */
    public function publish(
        string $machineKey,
        int $expectedRevision,
        GuardRegistry $guards,
        ?array $knownActions,
        string $reason,
        ?string $actor,
        DateTimeImmutable $at,
        ?DeadlineCalendarRegistry $calendars = null
    ): PublishedMachineDefinition;
}
