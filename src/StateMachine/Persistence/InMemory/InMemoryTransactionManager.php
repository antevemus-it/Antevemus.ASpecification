<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\InMemory;

use Antevemus\ASpecification\StateMachine\Persistence\ITransactionManager;
use Closure;
use Throwable;

/**
 * InMemoryTransactionManager - All-or-Nothing Unit of Work over the In-Memory Stores
 *
 * Exports the state of every participant when the outermost transaction begins and imports it
 * back when the work throws, so a failed send() leaves the in-memory stores exactly as they were
 * (forward 023, D12, D13). Nested calls join the outermost transaction. Single-threaded by nature:
 * it gives atomicity, not isolation between concurrent PHP processes (there are none in memory).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\InMemory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryTransactionManager implements ITransactionManager
{
    /** @var list<IInMemoryTransactional> */
    private array $participants;

    private int $depth = 0;

    /**
     * @param list<IInMemoryTransactional> $participants Stores rolled back together
     */
    public function __construct(array $participants = [])
    {
        $this->participants = array_values($participants);
    }

    /**
     * Adds a participant (before any transaction is open).
     */
    public function addParticipant(IInMemoryTransactional $participant): self
    {
        $this->participants[] = $participant;

        return $this;
    }

    /** True while a transaction is open. */
    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    /** {@inheritdoc} */
    public function transactional(Closure $work): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $work();
            } finally {
                $this->depth--;
            }
        }

        $saved = array_map(static fn(IInMemoryTransactional $p): array => $p->exportState(), $this->participants);
        $this->depth = 1;
        try {
            $result = $work();
        } catch (Throwable $e) {
            foreach ($this->participants as $i => $participant) {
                $participant->importState($saved[$i]);
            }
            throw $e;
        } finally {
            $this->depth = 0;
        }

        return $result;
    }
}
