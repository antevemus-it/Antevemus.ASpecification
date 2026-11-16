<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging;

use Antevemus\ASpecification\StateMachine\Event;
use Antevemus\ASpecification\StateMachine\EventOrigin;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;
use Antevemus\ASpecification\StateMachine\TransitionResult;
use Closure;
use InvalidArgumentException;
use LogicException;

/**
 * MessageMachineBridge - Delivers Messages to a State Machine
 *
 * A ready-made message handler (forward 063, D9), the counterpart of MachineEventBridge (1.11.0):
 * registered in a MessageHandlerRegistry, it turns a message from another module into a machine
 * event and sends it through a MachineRunner, so that "the payment was received" moves the process
 * of this module without a click.
 *
 * - The machine event type comes from a map (message name => machine event type).
 * - The instance is resolved by `fn(Message $message): ?string` (null = the message concerns no
 *   instance: nothing is sent).
 * - The machine event has the message's `occurredAt` as its instant, its payload as is (the guards
 *   read it), the origin given (EXTERNAL by default: the fact comes from outside this module) and the
 *   message's idempotency key, so a redelivery returns the original result from the runner's
 *   idempotency store and does not move the instance again, consumer inbox or not.
 * - With `scopeByTenant` (the default) the message's tenant is handed to MachineRunner::send(), which
 *   then loads the instance of that tenant only (a tenant-scoped instance store is needed when
 *   messages carry tenants).
 * - An optional context provider `fn(Message, string $instanceId): object|array|null` gives the
 *   guards their context; an optional actor is recorded in the history.
 *
 * The runner's exceptions propagate: under MessageConsumer the transaction rolls back and the
 * delivery is retried. A rejected or unhandled machine event is a normal outcome (returned by
 * deliver()), not an error: retrying it would not change the machine's answer. The machine's history
 * keeps the payload in its own columns: a payload larger than their VARCHAR(4000) needs the wide
 * columns of resources/sql/state_machine.ansi.sql.
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MessageMachineBridge
{
    /** @var array<string, string> message name => machine event type */
    private readonly array $eventTypes;

    /**
     * @param MachineRunner $runner Runner that receives the machine events
     * @param array<string, string> $eventTypes Message name => machine event type
     * @param Closure(Message): ?string $instanceResolver Instance id of a message, or null
     * @param (Closure(Message, string): (object|array<mixed>|null))|null $context Context of the guards
     * @param string|null $actor Who is recorded in the history (null = nobody)
     * @param bool $scopeByTenant Hand the message's tenant to the runner
     * @param EventOrigin $origin Origin of the machine events
     * @throws InvalidArgumentException When the map is empty, a name is not a message name or a type is empty
     */
    public function __construct(
        private readonly MachineRunner $runner,
        array $eventTypes,
        private readonly Closure $instanceResolver,
        private readonly ?Closure $context = null,
        private readonly ?string $actor = null,
        private readonly bool $scopeByTenant = true,
        private readonly EventOrigin $origin = EventOrigin::EXTERNAL
    ) {
        if ($eventTypes === []) {
            throw new InvalidArgumentException('The bridge needs at least one message name mapped to a machine event type.');
        }
        foreach ($eventTypes as $name => $type) {
            if (!is_string($name) || !Message::isValidName($name) || !is_string($type) || $type === '' || trim($type) !== $type) {
                throw new InvalidArgumentException('Every mapping must be a message name (module.fact.vN) => a non-empty machine event type.');
            }
        }
        $this->eventTypes = $eventTypes;
    }

    /**
     * Registers the bridge as a handler of every mapped message name.
     */
    public function registerIn(MessageHandlerRegistry $handlers): MessageHandlerRegistry
    {
        foreach (array_keys($this->eventTypes) as $name) {
            $handlers->register((string) $name, $this);
        }

        return $handlers;
    }

    /**
     * Handler form (`function (Message $message): void`).
     */
    public function __invoke(Message $message): void
    {
        $this->deliver($message);
    }

    /**
     * Sends the message to its instance.
     *
     * @return TransitionResult|null The machine's result; null when the message concerns no instance
     * @throws LogicException When the message name is not mapped (a registration mistake)
     */
    public function deliver(Message $message): ?TransitionResult
    {
        $type = $this->eventTypes[$message->name]
            ?? throw new LogicException(sprintf('Message "%s" is not mapped to a machine event type.', $message->name));
        $instanceId = ($this->instanceResolver)($message);
        if ($instanceId === null) {
            return null;
        }
        $event = new Event($type, $message->occurredAt, $message->payload, $message->idempotencyKey(), $this->origin);
        $context = $this->context === null ? null : ($this->context)($message, $instanceId);

        return $this->runner->send($instanceId, $event, $context, $this->actor, null, $this->scopeByTenant ? $message->tenant : null);
    }

    /**
     * The map of message names to machine event types.
     *
     * @return array<string, string>
     */
    public function getEventTypes(): array
    {
        return $this->eventTypes;
    }
}
