<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

/**
 * InMemoryAsyncTicketStore - Tickets Kept in an Array (2.5.0, forward 048, D28)
 *
 * For tests and for a long-running process that polls in the same memory. A web application keeps its
 * tickets in a table (CallableAsyncTicketStore): this store does not survive the request.
 *
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryAsyncTicketStore implements IAsyncTicketStore
{
    /** @var array<string, AsyncTicket> */
    private array $tickets = [];

    /** {@inheritdoc} */
    public function get(string $key): ?AsyncTicket
    {
        return $this->tickets[$key] ?? null;
    }

    /** {@inheritdoc} */
    public function markSubmitting(string $key): bool
    {
        if (isset($this->tickets[$key])) {
            return false;
        }
        $this->tickets[$key] = AsyncTicket::submitting();

        return true;
    }

    /** {@inheritdoc} */
    public function storeTicket(string $key, string $ticket): void
    {
        $this->tickets[$key] = AsyncTicket::submitted($ticket);
    }

    /** {@inheritdoc} */
    public function clear(string $key): void
    {
        unset($this->tickets[$key]);
    }

    /**
     * Every key known, with what is known about it.
     *
     * @return array<string, AsyncTicket>
     */
    public function all(): array
    {
        return $this->tickets;
    }
}
