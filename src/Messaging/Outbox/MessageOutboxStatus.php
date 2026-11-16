<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\Outbox;

/**
 * MessageOutboxStatus - Delivery Status of a Message Outbox Entry
 *
 * `pending` until the relay first sends it; `delivered` once the broker confirmed it (stored it
 * durably); `failed` after a send the broker did not confirm, still eligible for a retry while the
 * relay's attempt limit (if any) is not reached (forward 063, D4, D6; the values of the domain event
 * outbox of 1.11.0).
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\Outbox
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum MessageOutboxStatus: string
{
    /** Written, never sent. */
    case PENDING = 'pending';

    /** Confirmed by the broker. */
    case DELIVERED = 'delivered';

    /** The last send was not confirmed; retried while attempts remain. */
    case FAILED = 'failed';
}
