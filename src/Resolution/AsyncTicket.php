<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

use InvalidArgumentException;

/**
 * AsyncTicket - What an IAsyncTicketStore Knows About One Request (2.5.0, forward 048, D28)
 *
 * Either "submitting" (the request was marked before it was sent and no ticket was stored yet: it is being
 * sent, or the process stopped between sending it and storing the ticket) or "submitted" with the ticket
 * the provider returned.
 *
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class AsyncTicket
{
    private function __construct(private readonly ?string $ticket)
    {
    }

    /** Marked before sending, no ticket stored. */
    public static function submitting(): self
    {
        return new self(null);
    }

    /**
     * Sent, with the ticket of the provider.
     *
     * @throws InvalidArgumentException When the ticket is empty
     */
    public static function submitted(string $ticket): self
    {
        if ($ticket === '') {
            throw new InvalidArgumentException('The ticket of a submitted request cannot be empty.');
        }

        return new self($ticket);
    }

    /** True while no ticket is stored. */
    public function isSubmitting(): bool
    {
        return $this->ticket === null;
    }

    /** The ticket, or null while submitting. */
    public function getTicket(): ?string
    {
        return $this->ticket;
    }
}
