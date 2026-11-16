<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\Inbox;

use Antevemus\ASpecification\Messaging\Message;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\IInMemoryTransactional;
use DateTimeImmutable;

/**
 * InMemoryProcessedMessageStore - Inbox in Memory
 *
 * Same contract as PdoProcessedMessageStore (forward 063, D8), checked by the same battery. It takes
 * part in an InMemoryTransactionManager (IInMemoryTransactional), so a handler that throws rolls its
 * inbox row back together with the in-memory effects of the same transaction.
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\Inbox
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryProcessedMessageStore implements IProcessedMessageStore, IInMemoryTransactional
{
    /** @var array<string, array<string, ProcessedMessage>> consumer => key => row */
    private array $rows = [];

    /** {@inheritdoc} */
    public function has(string $consumer, string $idempotencyKey): bool
    {
        return isset($this->rows[$consumer][$idempotencyKey]);
    }

    /** {@inheritdoc} */
    public function markProcessed(string $consumer, Message $message, DateTimeImmutable $at): bool
    {
        $row = ProcessedMessage::of($consumer, $message, $at);
        if ($this->has($consumer, $row->idempotencyKey)) {
            return false;
        }
        $this->rows[$consumer][$row->idempotencyKey] = $row;

        return true;
    }

    /** {@inheritdoc} */
    public function find(string $consumer, string $idempotencyKey): ?ProcessedMessage
    {
        return $this->rows[$consumer][$idempotencyKey] ?? null;
    }

    /** {@inheritdoc} */
    public function count(?string $consumer = null): int
    {
        if ($consumer !== null) {
            return count($this->rows[$consumer] ?? []);
        }

        return array_sum(array_map('count', $this->rows));
    }

    /** {@inheritdoc} */
    public function purgeProcessedBefore(string $consumer, DateTimeImmutable $before): int
    {
        $deleted = 0;
        foreach ($this->rows[$consumer] ?? [] as $key => $row) {
            if ($row->processedAt < $before) {
                unset($this->rows[$consumer][$key]);
                $deleted++;
            }
        }

        return $deleted;
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
