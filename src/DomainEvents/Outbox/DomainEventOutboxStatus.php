<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents\Outbox;

/**
 * DomainEventOutboxStatus - Delivery Status of a Domain Event Outbox Entry
 *
 * `pending` until the first delivery attempt; `delivered` once every handler ran without
 * throwing; `failed` after an attempt that threw, still eligible for retry while the dispatcher's
 * attempt limit (if any) is not reached (forward 029, D5; the values of the 1.8.0 outbox).
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents\Outbox
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum DomainEventOutboxStatus: string
{
    /** Written, never attempted. */
    case PENDING = 'pending';

    /** Every handler ran without throwing. */
    case DELIVERED = 'delivered';

    /** The last attempt threw; retried while attempts remain. */
    case FAILED = 'failed';
}
