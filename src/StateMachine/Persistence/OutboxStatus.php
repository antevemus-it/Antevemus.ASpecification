<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

/**
 * OutboxStatus - Delivery Status of an Outbox Entry
 *
 * `pending` until the first delivery attempt; `delivered` once a handler ran without throwing
 * (synchronous effects are written already delivered); `failed` after an attempt that threw, and
 * still eligible for retry while the dispatcher's attempt limit (if any) is not reached
 * (forward 023, D12, RN-10).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum OutboxStatus: string
{
    /** Written, never attempted. */
    case PENDING = 'pending';

    /** A handler ran without throwing. */
    case DELIVERED = 'delivered';

    /** The last attempt threw; retried while attempts remain. */
    case FAILED = 'failed';
}
