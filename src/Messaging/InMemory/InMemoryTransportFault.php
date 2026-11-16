<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging\InMemory;

/**
 * InMemoryTransportFault - What the In-Memory Broker Does Wrong With One Message
 *
 * The faults InMemoryMessageTransport can be told to inject, message by message, so that the relay
 * and the consumer are tested against the failures a real broker has (forward 063, D13):
 * - `NONE`: stored and confirmed
 * - `FAIL`: not stored, reported failed (the broker refused it, the connection dropped before it)
 * - `LOSE_CONFIRM`: stored, but the confirmation never comes (the result says nothing about it):
 *   the relay sends it again, and the broker ends up with two copies
 * - `DUPLICATE`: stored twice and confirmed (a broker that redelivers on its own)
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging\InMemory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum InMemoryTransportFault: string
{
    case NONE = 'none';
    case FAIL = 'fail';
    case LOSE_CONFIRM = 'lose_confirm';
    case DUPLICATE = 'duplicate';
}
