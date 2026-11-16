<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging;

/**
 * ConsumeOutcome - What MessageConsumer Did With One Delivery
 *
 * (forward 063, D8, D10):
 * - `handled`: the handlers ran, the inbox row and their effects committed, acknowledged
 * - `duplicate`: the inbox already had the message for this consumer; acknowledged, nothing ran
 * - `unknown_name`: no handler for the name (the consumer does not want it); acknowledged, with a
 *   warning in the report
 * - `other_tenant`: the consumer is restricted to a tenant and the message is not of it;
 *   acknowledged without running anything
 * - `retried`: a handler threw; rolled back and rejected with requeue
 * - `held_back`: an earlier message of the same ordering key was retried in this run; rejected with
 *   requeue without running, so that the key keeps its order
 * - `dead_lettered`: rejected without requeue (to the dead letter queue): the delivery is invalid, a
 *   handler refused the payload (InvalidMessagePayloadException), the handlers failed on the last
 *   allowed delivery, or the delivery count is above maxDeliveries
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum ConsumeOutcome: string
{
    case HANDLED = 'handled';
    case DUPLICATE = 'duplicate';
    case UNKNOWN_NAME = 'unknown_name';
    case OTHER_TENANT = 'other_tenant';
    case RETRIED = 'retried';
    case HELD_BACK = 'held_back';
    case DEAD_LETTERED = 'dead_lettered';

    /** True when the delivery was acknowledged (it will not come again). */
    public function isAcknowledged(): bool
    {
        return match ($this) {
            self::HANDLED, self::DUPLICATE, self::UNKNOWN_NAME, self::OTHER_TENANT => true,
            self::RETRIED, self::HELD_BACK, self::DEAD_LETTERED => false,
        };
    }
}
