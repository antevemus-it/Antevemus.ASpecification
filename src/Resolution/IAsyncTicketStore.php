<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

/**
 * IAsyncTicketStore - Where an AsyncDataSource Keeps the Tickets of Its Requests (2.5.0, forward 048, D28)
 *
 * Keyed by the canonical key of the request (DataRequest::getKey(): the source and its normalized
 * parameters), so the same question asked again finds the same ticket instead of being sent again. It must
 * survive the process (the next resolution runs in another request or another run of a job): a table of
 * the application through CallableAsyncTicketStore, or InMemoryAsyncTicketStore in tests.
 *
 * markSubmitting() is a claim: it records "submitting" only when the key is absent and says whether it
 * did, so two processes asking at the same time do not both send (an INSERT on a unique key, or an
 * `INSERT ... ON CONFLICT DO NOTHING`, gives it).
 *
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IAsyncTicketStore
{
    /**
     * What is known about the key, or null when nothing is.
     */
    public function get(string $key): ?AsyncTicket;

    /**
     * Records "submitting" for an absent key, before the request is sent.
     *
     * @return bool True when this call claimed the key; false when the key was already present
     */
    public function markSubmitting(string $key): bool;

    /**
     * Stores the ticket the provider returned for the key.
     */
    public function storeTicket(string $key, string $ticket): void;

    /**
     * Forgets the key: the next fetch of the request sends it again.
     */
    public function clear(string $key): void;
}
