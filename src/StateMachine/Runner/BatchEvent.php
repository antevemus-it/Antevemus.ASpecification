<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Runner;

use Antevemus\ASpecification\StateMachine\Event;
use InvalidArgumentException;

/**
 * BatchEvent - One Event of a MachineRunner::sendMany() Batch
 *
 * The arguments send() takes for one event (2.10.0): the instance, the event, the domain context read
 * by the guards, the actor (null = the default actor given to sendMany()) and the version the caller
 * saw (null = no check beyond the compare-and-set).
 *
 * @version    2.10.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Runner
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class BatchEvent
{
    /**
     * @param string $instanceId Instance id
     * @param Event $event Event (its idempotency key, when given, deduplicates per instance)
     * @param object|array<mixed>|null $context Domain context read by the guards
     * @param string|null $actor Who triggered it; null = the default actor of sendMany()
     * @param int|null $expectedVersion Version the caller saw; null = no check beyond the CAS
     * @throws InvalidArgumentException When the event type is reserved by the runner
     */
    public function __construct(
        public readonly string $instanceId,
        public readonly Event $event,
        public readonly object|array|null $context = null,
        public readonly ?string $actor = null,
        public readonly ?int $expectedVersion = null
    ) {
        if (in_array($event->type, MachineRunner::RESERVED_EVENTS, true)) {
            throw new InvalidArgumentException(sprintf('Event type "%s" is reserved by the machine runner.', $event->type));
        }
    }
}
