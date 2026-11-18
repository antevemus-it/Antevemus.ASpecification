<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

/**
 * A way a broker breaks the contract of the messaging hook, planted by Module49FaultyBench (2.15.0, forward 064): the
 * contract battery must reject each one, through the scenario of the item it breaks (Module 49).
 *
 * @version 2.15.0
 */
enum Module49Fault: string
{
    /** send() confirms every message and stores none. */
    case CONFIRMS_WITHOUT_STORING = 'confirms_without_storing';
    /** send() stores the message without its headers. */
    case DROPS_HEADERS = 'drops_headers';
    /** The broker drops a copy of a message id it already stored (a deduplication the inbox owns). */
    case DROPS_DUPLICATES = 'drops_duplicates';
    /** Every source of the bench shares one queue: consumers compete instead of each getting every message. */
    case NO_FAN_OUT = 'no_fan_out';
    /** A receive hands its deliveries out in reverse order. */
    case REVERSES_A_RECEIVE = 'reverses_a_receive';
    /** A delivery rejected with requeue goes to the back of the queue. */
    case REQUEUES_TO_THE_BACK = 'requeues_to_the_back';
    /** Every delivery comes with a delivery count of 1. */
    case COUNT_STUCK_AT_ONE = 'count_stuck_at_one';
    /** The dead letter queue does not keep the reason given to reject(). */
    case LOSES_THE_DEAD_LETTER_REASON = 'loses_the_dead_letter_reason';
    /** A body that is not a message is acknowledged and dropped. */
    case DROPS_INVALID_BODIES = 'drops_invalid_bodies';
    /** A dead consumer's deliveries in flight are lost (acknowledged) instead of given to the next one. */
    case LOSES_IN_FLIGHT_ON_DEATH = 'loses_in_flight_on_death';
    /** The lost connection stores the batch before it throws. */
    case LOST_CONNECTION_STORES = 'lost_connection_stores';
    /** The dead letter reason carries the payload. */
    case REASON_CARRIES_THE_PAYLOAD = 'reason_carries_the_payload';
    /** An acknowledgement of a delivery that is not in flight is ignored. */
    case SILENT_DOUBLE_ACK = 'silent_double_ack';
    /** Every bench of the process shares one broker. */
    case SHARED_WORLD = 'shared_world';
}
