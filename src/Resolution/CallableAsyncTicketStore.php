<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

use Closure;
use UnexpectedValueException;

/**
 * CallableAsyncTicketStore - Tickets Kept by the Application, Through Closures (2.5.0, forward 048, D28)
 *
 * The application already records what it asked a provider (an analysis with its query number, a document
 * check with its protocol): four closures map the store onto that table, with no table of the library.
 *
 * <code>
 * $tickets = new CallableAsyncTicketStore(
 *     get: fn(string $key): ?AsyncTicket => ($row = $analyses->findByKey($key))
 *         ? ($row->query_number ? AsyncTicket::submitted($row->query_number) : AsyncTicket::submitting())
 *         : null,
 *     markSubmitting: fn(string $key): bool => $analyses->insertIfAbsent($key),   // unique key on request_key
 *     storeTicket: fn(string $key, string $ticket) => $analyses->setQueryNumber($key, $ticket),
 *     clear: fn(string $key) => $analyses->deleteByKey($key),
 * );
 * </code>
 *
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class CallableAsyncTicketStore implements IAsyncTicketStore
{
    /**
     * @param Closure(string): ?AsyncTicket $get
     * @param Closure(string): bool $markSubmitting True when the key was absent and is now "submitting"
     * @param Closure(string, string): mixed $storeTicket
     * @param Closure(string): mixed $clear
     */
    public function __construct(
        private readonly Closure $get,
        private readonly Closure $markSubmitting,
        private readonly Closure $storeTicket,
        private readonly Closure $clear
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * @throws UnexpectedValueException When the closure returns something else than an AsyncTicket or null
     */
    public function get(string $key): ?AsyncTicket
    {
        $ticket = ($this->get)($key);
        if ($ticket !== null && !$ticket instanceof AsyncTicket) {
            throw new UnexpectedValueException(sprintf('The get closure of a ticket store must return an AsyncTicket or null, %s given.', get_debug_type($ticket)));
        }

        return $ticket;
    }

    /**
     * {@inheritdoc}
     *
     * @throws UnexpectedValueException When the closure does not return a boolean
     */
    public function markSubmitting(string $key): bool
    {
        $claimed = ($this->markSubmitting)($key);
        if (!is_bool($claimed)) {
            throw new UnexpectedValueException(sprintf('The markSubmitting closure of a ticket store must return a boolean, %s given.', get_debug_type($claimed)));
        }

        return $claimed;
    }

    /** {@inheritdoc} */
    public function storeTicket(string $key, string $ticket): void
    {
        ($this->storeTicket)($key, $ticket);
    }

    /** {@inheritdoc} */
    public function clear(string $key): void
    {
        ($this->clear)($key);
    }
}
