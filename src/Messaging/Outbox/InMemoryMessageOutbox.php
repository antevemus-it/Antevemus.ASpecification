<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\Outbox;

use Antevemus\ASpecification\Messaging\Exceptions\MessagingPersistenceException;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\IInMemoryTransactional;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * InMemoryMessageOutbox - Message Outbox in Memory
 *
 * Same contract as PdoMessageOutbox (forward 063, D4), checked by the same battery: entries keyed
 * by entry id, one row per message id, queue heads per ordering key, delivered/failed marks with
 * attempts and the last reason. It takes part in an InMemoryTransactionManager
 * (IInMemoryTransactional), so a rolled back write also rolls its messages back.
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\Outbox
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryMessageOutbox implements IMessageOutbox, IInMemoryTransactional
{
    /** @var array<string, MessageOutboxEntry> entry id => entry */
    private array $entries = [];

    /** @var array<string, string> message id => entry id */
    private array $messages = [];

    /** {@inheritdoc} */
    public function enqueue(array $entries): int
    {
        $written = 0;
        foreach ($entries as $entry) {
            if (isset($this->messages[$entry->messageId()])) {
                continue;
            }
            if (isset($this->entries[$entry->entryId])) {
                throw new MessagingPersistenceException(sprintf('Message outbox entry %s already exists.', $entry->entryId));
            }
            $this->entries[$entry->entryId] = $entry;
            $this->messages[$entry->messageId()] = $entry->entryId;
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
            if (isset($heads[$queue]) || $entry->isDelivered() || ($maxAttempts !== null && $entry->attempts >= $maxAttempts)) {
                continue;
            }
            $heads[$queue] = $entry;
            if (count($heads) === $limit) {
                break;
            }
        }

        return array_values($heads);
    }

    /** {@inheritdoc} */
    public function markDelivered(MessageOutboxEntry $entry, DateTimeImmutable $at): void
    {
        $id = $this->mustExist($entry);
        $this->entries[$id] = $this->entries[$id]->withDelivered($at);
    }

    /** {@inheritdoc} */
    public function markFailed(MessageOutboxEntry $entry, string $reason, DateTimeImmutable $at): void
    {
        $id = $this->mustExist($entry);
        $this->entries[$id] = $this->entries[$id]->withFailed($reason, $at);
    }

    /** {@inheritdoc} */
    public function find(string $messageId): ?MessageOutboxEntry
    {
        $id = $this->messages[$messageId] ?? null;

        return $id === null ? null : $this->entries[$id];
    }

    /** {@inheritdoc} */
    public function findByOrderingKey(string $orderingKey): array
    {
        return array_values(array_filter(
            $this->ordered(),
            static fn(MessageOutboxEntry $e): bool => $e->message->orderingKey === $orderingKey
        ));
    }

    /** {@inheritdoc} */
    public function count(): int
    {
        return count($this->entries);
    }

    /** {@inheritdoc} */
    public function exportState(): array
    {
        return ['entries' => $this->entries, 'messages' => $this->messages];
    }

    /** {@inheritdoc} */
    public function importState(array $state): void
    {
        $this->entries = $state['entries'] ?? [];
        $this->messages = $state['messages'] ?? [];
    }

    /**
     * Entries in entry id order (binary string order, as the SQL ORDER BY of ULIDs).
     *
     * @return list<MessageOutboxEntry>
     */
    private function ordered(): array
    {
        $entries = $this->entries;
        uksort($entries, static fn($a, $b): int => strcmp((string) $a, (string) $b));

        return array_values($entries);
    }

    private function mustExist(MessageOutboxEntry $entry): string
    {
        if (!isset($this->entries[$entry->entryId])) {
            throw new MessagingPersistenceException(sprintf('Message outbox entry %s (%s) does not exist.', $entry->entryId, $entry->message->describe()));
        }

        return $entry->entryId;
    }
}
