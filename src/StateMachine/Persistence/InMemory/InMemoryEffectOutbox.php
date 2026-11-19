<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\InMemory;

use Antevemus\ASpecification\StateMachine\Exceptions\MachinePersistenceException;
use Antevemus\ASpecification\StateMachine\Persistence\IEffectOutbox;
use Antevemus\ASpecification\StateMachine\Persistence\IEffectOutboxRetention;
use Antevemus\ASpecification\StateMachine\Persistence\OutboxEntry;
use Antevemus\ASpecification\StateMachine\Persistence\OutboxStatus;
use Antevemus\ASpecification\StateMachine\Persistence\PersistenceSupport;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * InMemoryEffectOutbox - Transactional Outbox in Memory
 *
 * Same contract as PdoEffectOutbox (forward 023, D12, RN-10): entries keyed by their position,
 * queue heads per instance, delivered/failed marks with attempts and the last error message.
 *
 * Since 2.16.0 (forward 065): a failure mark never overwrites a delivered entry (D11), and the delivered
 * entries can be purged (IEffectOutboxRetention, D12).
 *
 * @version    2.16.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\InMemory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryEffectOutbox implements IEffectOutbox, IEffectOutboxRetention, IInMemoryTransactional
{
    /** @var array<string, array<string, OutboxEntry>> instance id => "version:order" => entry */
    private array $entries = [];

    /** {@inheritdoc} */
    public function enqueue(array $entries): void
    {
        foreach ($entries as $entry) {
            $position = self::position($entry);
            if (isset($this->entries[$entry->instanceId][$position])) {
                throw new MachinePersistenceException(sprintf('Outbox entry %s already exists.', $entry->effectKey()));
            }
            $this->entries[$entry->instanceId][$position] = $entry;
        }
    }

    /** {@inheritdoc} */
    public function fetchPending(int $limit = 100, ?int $maxAttempts = null): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('The limit must be at least 1, %d given.', $limit));
        }
        $heads = [];
        foreach (array_keys($this->entries) as $instanceId) {
            foreach ($this->findByInstance((string) $instanceId) as $entry) {
                if ($entry->status !== OutboxStatus::DELIVERED && ($maxAttempts === null || $entry->attempts < $maxAttempts)) {
                    $heads[] = $entry;
                    break;
                }
            }
        }
        // Binary string order, as the SQL ORDER BY under a binary collation (numeric-looking ids too).
        usort($heads, static fn(OutboxEntry $a, OutboxEntry $b): int => strcmp(PersistenceSupport::toText($a->enqueuedAt), PersistenceSupport::toText($b->enqueuedAt))
            ?: strcmp($a->instanceId, $b->instanceId));

        return array_slice($heads, 0, $limit);
    }

    /** {@inheritdoc} */
    public function markDelivered(OutboxEntry $entry, DateTimeImmutable $at): void
    {
        $this->replace($entry, fn(OutboxEntry $stored): OutboxEntry => $stored->withDelivered($at));
    }

    /** {@inheritdoc} */
    public function markFailed(OutboxEntry $entry, string $error, DateTimeImmutable $at): void
    {
        $this->replace($entry, fn(OutboxEntry $stored): OutboxEntry => $stored->isDelivered() ? $stored : $stored->withFailed($error, $at));
    }

    /** {@inheritdoc} */
    public function purgeDeliveredBefore(DateTimeImmutable $before, int $limit = 1000): int
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('The limit must be at least 1, %d given.', $limit));
        }
        $before = PersistenceSupport::toText($before);
        $purgeable = [];
        foreach ($this->entries as $positions) {
            foreach ($positions as $entry) {
                // The text form of the instants, compared as the SQL of PdoEffectOutbox does
                $deliveredAt = $entry->deliveredAt === null ? null : PersistenceSupport::toText($entry->deliveredAt);
                if ($entry->isDelivered() && $deliveredAt !== null && $deliveredAt < $before) {
                    $purgeable[] = [$deliveredAt, $entry];
                }
            }
        }
        // ORDER BY delivered_at, instance_id, instance_version, sort_order: texts in binary order, numbers as numbers
        usort($purgeable, static fn(array $a, array $b): int => strcmp($a[0], $b[0])
            ?: strcmp($a[1]->instanceId, $b[1]->instanceId)
            ?: [$a[1]->instanceVersion, $a[1]->sortOrder] <=> [$b[1]->instanceVersion, $b[1]->sortOrder]);
        $purged = 0;
        foreach (array_slice($purgeable, 0, $limit) as [, $entry]) {
            unset($this->entries[$entry->instanceId][self::position($entry)]);
            if ($this->entries[$entry->instanceId] === []) {
                unset($this->entries[$entry->instanceId]);
            }
            $purged++;
        }

        return $purged;
    }

    /** {@inheritdoc} */
    public function findByInstance(string $instanceId): array
    {
        $entries = array_values($this->entries[$instanceId] ?? []);
        usort($entries, static fn(OutboxEntry $a, OutboxEntry $b): int => [$a->instanceVersion, $a->sortOrder] <=> [$b->instanceVersion, $b->sortOrder]);

        return $entries;
    }

    /**
     * @param \Closure(OutboxEntry): OutboxEntry $change
     */
    private function replace(OutboxEntry $entry, \Closure $change): void
    {
        $position = self::position($entry);
        $stored = $this->entries[$entry->instanceId][$position] ?? null;
        if ($stored === null) {
            throw new MachinePersistenceException(sprintf('Outbox entry %s does not exist.', $entry->effectKey()));
        }
        $this->entries[$entry->instanceId][$position] = $change($stored);
    }

    private static function position(OutboxEntry $entry): string
    {
        return $entry->instanceVersion . ':' . $entry->sortOrder;
    }

    /** {@inheritdoc} */
    public function exportState(): array
    {
        return $this->entries;
    }

    /** {@inheritdoc} */
    public function importState(array $state): void
    {
        $this->entries = $state;
    }
}
