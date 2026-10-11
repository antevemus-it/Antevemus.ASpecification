<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents\State;

use Antevemus\ASpecification\StateMachine\Persistence\InMemory\IInMemoryTransactional;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * InMemoryTriggerStateStore - Trigger States in Memory
 *
 * Same contract as PdoTriggerStateStore (forward 030, D2): rows keyed by trigger, entity class and
 * entity id, compare-and-set on the version, satisfied pages in entity id order (binary string
 * order, as the SQL ORDER BY under a binary collation). It takes part in an
 * InMemoryTransactionManager (IInMemoryTransactional), so a rolled back crossing also rolls its
 * state back.
 *
 * @version    1.12.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents\State
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryTriggerStateStore implements ITriggerStateStore, IInMemoryTransactional
{
    /** @var array<string, array<string, array<string, TriggerState>>> trigger => class => id => state */
    private array $rows = [];

    /** {@inheritdoc} */
    public function find(string $triggerName, string $entityClass, string $entityId): ?TriggerState
    {
        return $this->rows[$triggerName][$entityClass][$entityId] ?? null;
    }

    /** {@inheritdoc} */
    public function findByEntity(string $entityClass, string $entityId): array
    {
        $found = [];
        foreach ($this->rows as $trigger => $byClass) {
            if (isset($byClass[$entityClass][$entityId])) {
                $found[(string) $trigger] = $byClass[$entityClass][$entityId];
            }
        }

        return $found;
    }

    /** {@inheritdoc} */
    public function findMany(string $triggerName, string $entityClass, array $entityIds): array
    {
        $found = [];
        foreach ($entityIds as $id) {
            $state = $this->rows[$triggerName][$entityClass][$id] ?? null;
            if ($state !== null) {
                $found[$id] = $state;
            }
        }

        return $found;
    }

    /** {@inheritdoc} */
    public function findSatisfied(string $triggerName, string $entityClass, int $limit, ?string $afterEntityId = null): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('The limit must be at least 1, %d given.', $limit));
        }
        $ids = [];
        foreach ($this->rows[$triggerName][$entityClass] ?? [] as $id => $state) {
            $id = (string) $id;
            if ($state->satisfied && ($afterEntityId === null || strcmp($id, $afterEntityId) > 0)) {
                $ids[] = $id;
            }
        }
        usort($ids, 'strcmp');

        return array_map(
            fn(string $id): TriggerState => $this->rows[$triggerName][$entityClass][$id],
            array_slice($ids, 0, $limit)
        );
    }

    /** {@inheritdoc} */
    public function compareAndSet(
        string $triggerName,
        string $entityClass,
        string $entityId,
        ?int $expectedVersion,
        bool $satisfied,
        DateTimeImmutable $evaluatedAt
    ): bool {
        TriggerState::assertKey($triggerName, $entityClass, $entityId);
        $current = $this->rows[$triggerName][$entityClass][$entityId] ?? null;
        if ($expectedVersion === null ? $current !== null : $current?->version !== $expectedVersion) {
            return false;
        }
        $this->rows[$triggerName][$entityClass][$entityId] = new TriggerState(
            $triggerName,
            $entityClass,
            $entityId,
            $satisfied,
            $evaluatedAt,
            $current === null ? 1 : $current->version + 1
        );

        return true;
    }

    /** {@inheritdoc} */
    public function compareAndDelete(string $triggerName, string $entityClass, string $entityId, int $expectedVersion): bool
    {
        $current = $this->rows[$triggerName][$entityClass][$entityId] ?? null;
        if ($current === null || $current->version !== $expectedVersion) {
            return false;
        }
        unset($this->rows[$triggerName][$entityClass][$entityId]);

        return true;
    }

    /** {@inheritdoc} */
    public function count(): int
    {
        $count = 0;
        foreach ($this->rows as $byClass) {
            foreach ($byClass as $byId) {
                $count += count($byId);
            }
        }

        return $count;
    }

    /** {@inheritdoc} */
    public function exportState(): array
    {
        return ['rows' => $this->rows];
    }

    /** {@inheritdoc} */
    public function importState(array $state): void
    {
        $this->rows = $state['rows'] ?? [];
    }
}
