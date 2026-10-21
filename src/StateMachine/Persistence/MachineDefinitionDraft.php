<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use Antevemus\ASpecification\StateMachine\MachineValidationReport;

/**
 * MachineDefinitionDraft - A Definition Being Edited, With What the Validator Says (2.7.0)
 *
 * The draft is kept as the array the screen saved, not as a MachineDefinition: a graph half done (no
 * initial state, a target that does not exist yet) must be saved and reported, not refused on load.
 * The report is MachineDefinitionValidator::analyze() over the draft as it would be published (its
 * key, the next version), with every error and warning located by region, state and transition; an
 * array that does not even build a definition reports that as its errors.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MachineDefinitionDraft
{
    /**
     * @param DraftInfo $info Revision and authorship
     * @param array<string, mixed> $definition The array saved (array form of a MachineDefinition, maybe incomplete)
     * @param MachineValidationReport $report Errors and warnings of the draft as it would be published
     */
    public function __construct(
        public readonly DraftInfo $info,
        public readonly array $definition,
        public readonly MachineValidationReport $report
    ) {
    }

    /** Revision to send back on the next save, discard or publication. */
    public function revision(): int
    {
        return $this->info->revision;
    }

    /** True when the draft has no error: publish() would accept it (with the same guards and actions). */
    public function isPublishable(): bool
    {
        return $this->report->isValid();
    }
}
