<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents\Outbox;

use Antevemus\ASpecification\DomainEvents\Exceptions\DomainEventPersistenceException;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\IInMemoryTransactional;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * InMemoryDomainEventOutbox - Domain Event Outbox in Memory
 *
 * Same contract as PdoDomainEventOutbox (forward 029, D5): entries keyed by event id, one row per
 * idempotency key, queue heads per entity, delivered/failed marks with attempts and the last error
 * message. It takes part in an InMemoryTransactionManager (IInMemoryTransactional), so a rolled
 * back write also rolls its events back.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents\Outbox
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryDomainEventOutbox implements IDomainEventOutbox, IInMemoryTransactional
{
    /** @var array<string, DomainEventOutboxEntry> event id => entry */
    private array $entries = [];

    /** @var array<string, string> idempotency key => event id */
    private array $keys = [];

    /** {@inheritdoc} */
    public function enqueue(array $entries): int
    {
        $written = 0;
        foreach ($entries as $entry) {
            $key = $entry->idempotencyKey();
            if (isset($this->keys[$key])) {
                continue;
            }
            if (isset($this->entries[$entry->eventId])) {
                throw new DomainEventPersistenceException(sprintf('Domain event outbox entry %s already exists.', $entry->eventId));
            }
            $this->entries[$entry->eventId] = $entry;
            $this->keys[$key] = $entry->eventId;
            $written++;
        }

        return $written;
    }

    /** {@inheritdoc} */
    public function fetchPending(int $limit = 100, ?int $maxAttempts = null): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('The limit must be at least 1, %d given.', $limit));
        }
        $heads = [];
        foreach ($this->ordered() as $entry) {
            $queue = $entry->queueKey();
            if (isset($heads[$queue])) {
                continue;
            }
            if ($entry->status !== DomainEventOutboxStatus::DELIVERED && ($maxAttempts === null || $entry->attempts < $maxAttempts)) {
                $heads[$queue] = $entry;
            }
        }

        return array_slice(array_values($heads), 0, $limit);
    }

    /** {@inheritdoc} */
    public function markDelivered(DomainEventOutboxEntry $entry, DateTimeImmutable $at): void
    {
        $this->entries[$this->mustExist($entry)] = $this->entries[$entry->eventId]->withDelivered($at);
    }

    /** {@inheritdoc} */
    public function markFailed(DomainEventOutboxEntry $entry, string $error, DateTimeImmutable $at): void
    {
        $this->entries[$this->mustExist($entry)] = $this->entries[$entry->eventId]->withFailed($error, $at);
    }

    /** {@inheritdoc} */
    public function findByEntity(string $entityClass, string $entityId): array
    {
        return array_values(array_filter(
            $this->ordered(),
            static fn(DomainEventOutboxEntry $e): bool => $e->event->entityClass === $entityClass && $e->event->entityId === $entityId
        ));
    }

    /** {@inheritdoc} */
    public function findByIdempotencyKey(string $idempotencyKey): ?DomainEventOutboxEntry
    {
        $id = $this->keys[$idempotencyKey] ?? null;

        return $id === null ? null : $this->entries[$id];
    }

    /** {@inheritdoc} */
    public function count(): int
    {
        return count($this->entries);
    }

    /** {@inheritdoc} */
    public function exportState(): array
    {
        return ['entries' => $this->entries, 'keys' => $this->keys];
    }

    /** {@inheritdoc} */
    public function importState(array $state): void
    {
        $this->entries = $state['entries'] ?? [];
        $this->keys = $state['keys'] ?? [];
    }

    /**
     * Entries in event id order (binary string order, as the SQL ORDER BY under a binary collation).
     *
     * @return list<DomainEventOutboxEntry>
     */
    private function ordered(): array
    {
        $entries = $this->entries;
        uksort($entries, static fn($a, $b): int => strcmp((string) $a, (string) $b));

        return array_values($entries);
    }

    private function mustExist(DomainEventOutboxEntry $entry): string
    {
        if (!isset($this->entries[$entry->eventId])) {
            throw new DomainEventPersistenceException(sprintf('Domain event outbox entry %s does not exist.', $entry->eventId));
        }

        return $entry->eventId;
    }
}
