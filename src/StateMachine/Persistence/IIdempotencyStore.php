<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use Antevemus\ASpecification\StateMachine\TransitionResult;
use DateTimeImmutable;

/**
 * IIdempotencyStore - Port of the Processed Idempotency Keys
 *
 * The key of an event is unique per instance (forward 023, D13, RN-09). When an accepted event
 * carried a key, the runner records its result here, in the same transaction; a later event with
 * the same key gets the ORIGINAL TransitionResult back (snapshot after the event, transitions,
 * effects) and nothing is written. Rejected and unhandled events are not recorded; events without
 * key are never deduplicated.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IIdempotencyStore
{
    /**
     * The recorded result of the key, or null when the key was never processed for the instance.
     *
     * @param string $instanceId Instance id
     * @param string $idempotencyKey Event idempotency key
     * @return TransitionResult|null
     */
    public function find(string $instanceId, string $idempotencyKey): ?TransitionResult;

    /**
     * Records the accepted result of a keyed event.
     *
     * @param string $instanceId Instance id
     * @param string $idempotencyKey Event idempotency key
     * @param int $instanceVersion Instance version the event produced
     * @param TransitionResult $result Accepted result
     * @param DateTimeImmutable $recordedAt Instant of the event
     * @throws \Antevemus\ASpecification\StateMachine\Exceptions\ConcurrencyConflictException When the key was recorded meanwhile
     */
    public function record(
        string $instanceId,
        string $idempotencyKey,
        int $instanceVersion,
        TransitionResult $result,
        DateTimeImmutable $recordedAt
    ): void;
}
