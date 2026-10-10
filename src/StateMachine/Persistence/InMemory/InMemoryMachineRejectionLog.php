<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\InMemory;

use Antevemus\ASpecification\StateMachine\Persistence\IMachineRejectionLog;
use Antevemus\ASpecification\StateMachine\Persistence\RejectionEntry;

/**
 * InMemoryMachineRejectionLog - Optional Rejection Log in Memory
 *
 * Same contract as PdoMachineRejectionLog (forward 023, RN-07).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\InMemory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryMachineRejectionLog implements IMachineRejectionLog, IInMemoryTransactional
{
    /** @var array<string, list<RejectionEntry>> */
    private array $rows = [];

    /** {@inheritdoc} */
    public function record(RejectionEntry $entry): void
    {
        $this->rows[$entry->instanceId][] = $entry;
    }

    /** {@inheritdoc} */
    public function findByInstance(string $instanceId): array
    {
        return $this->rows[$instanceId] ?? [];
    }

    /** {@inheritdoc} */
    public function exportState(): array
    {
        return $this->rows;
    }

    /** {@inheritdoc} */
    public function importState(array $state): void
    {
        $this->rows = $state;
    }
}
