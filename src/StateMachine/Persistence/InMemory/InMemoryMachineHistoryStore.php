<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\InMemory;

use Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException;
use Antevemus\ASpecification\StateMachine\Persistence\HistoryEntry;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineHistoryStore;

/**
 * InMemoryMachineHistoryStore - Append-Only History in Memory
 *
 * Same contract as PdoMachineHistoryStore (forward 023, D15): rows are appended, never replaced
 * (a duplicate position is refused like a primary key violation) and read in write order.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\InMemory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryMachineHistoryStore implements IMachineHistoryStore, IInMemoryTransactional
{
    /** @var array<string, array<string, HistoryEntry>> instance id => "version:order" => row */
    private array $rows = [];

    /** {@inheritdoc} */
    public function append(array $entries): void
    {
        foreach ($entries as $entry) {
            $position = $entry->instanceVersion . ':' . $entry->sortOrder;
            if (isset($this->rows[$entry->instanceId][$position])) {
                throw new MachinePersistenceException(sprintf(
                    'History row %s of machine instance "%s" already exists: the history is append-only.',
                    $position,
                    $entry->instanceId
                ));
            }
            $this->rows[$entry->instanceId][$position] = $entry;
        }
    }

    /** {@inheritdoc} */
    public function findByInstance(string $instanceId): array
    {
        $rows = array_values($this->rows[$instanceId] ?? []);
        usort($rows, static fn(HistoryEntry $a, HistoryEntry $b): int => [$a->instanceVersion, $a->sortOrder] <=> [$b->instanceVersion, $b->sortOrder]);

        return $rows;
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
