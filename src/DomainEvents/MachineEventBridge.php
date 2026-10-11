<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

use Antevemus\ASpecification\StateMachine\Event;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;
use Antevemus\ASpecification\StateMachine\TransitionResult;
use Closure;
use InvalidArgumentException;
use LogicException;

/**
 * MachineEventBridge - Delivers Domain Events to a State Machine
 *
 * A ready-made domain event handler (forward 029, D7): it turns a domain event into a machine
 * event and sends it through a MachineRunner, so that "every document validated" moves the
 * process without a click.
 *
 * - The machine event type comes from a map (domain event name => machine event type).
 * - The instance is resolved by a callable `fn(DomainEvent $event): ?string` (the instance id, or
 *   null when the event concerns no instance: nothing is sent).
 * - The machine event has INTERNAL origin, the domain event's `occurredAt` as its instant, a
 *   payload with the domain event's minimal payload plus its name and kind (never the entity), and
 *   the domain event's idempotency key, so a redelivery returns the original result from the
 *   runner's idempotency store and does not move the instance again.
 * - An optional context provider `fn(DomainEvent $event, string $instanceId): object|array|null`
 *   gives the guards their context; an optional actor is recorded in the history.
 *
 * The runner's exceptions (unknown instance, concurrency conflict, synchronous effect failure)
 * propagate: under DomainEventDispatcher the entry is marked failed and retried. A rejected or
 * unhandled machine event is a normal outcome (returned by deliver(), not an error): retrying it
 * would not change the machine's answer.
 *
 * <code>
 * $bridge = new MachineEventBridge($runner, ['documents.validated' => 'VALIDATE'], fn(DomainEvent $e) => 'quote-' . $e->entityId);
 * $bridge->registerIn($handlers);   // DomainEventHandlerRegistry
 * </code>
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MachineEventBridge
{
    /** @var array<string, string> domain event name => machine event type */
    private readonly array $eventTypes;

    /**
     * @param MachineRunner $runner Runner that receives the machine events
     * @param array<string, string> $eventTypes Domain event name => machine event type
     * @param Closure(DomainEvent): ?string $instanceResolver Instance id of an event, or null
     * @param (Closure(DomainEvent, string): (object|array<mixed>|null))|null $context Context of the guards
     * @param string|null $actor Who is recorded in the history (null = nobody)
     * @throws InvalidArgumentException When the map is empty or has an empty name or type
     */
    public function __construct(
        private readonly MachineRunner $runner,
        array $eventTypes,
        private readonly Closure $instanceResolver,
        private readonly ?Closure $context = null,
        private readonly ?string $actor = null
    ) {
        if ($eventTypes === []) {
            throw new InvalidArgumentException('The bridge needs at least one domain event name mapped to a machine event type.');
        }
        foreach ($eventTypes as $name => $type) {
            if (!is_string($name) || $name === '' || !is_string($type) || $type === '' || trim($type) !== $type) {
                throw new InvalidArgumentException('Every mapping must be a non-empty domain event name => non-empty machine event type.');
            }
        }
        $this->eventTypes = $eventTypes;
    }

    /**
     * Registers the bridge as a handler of every mapped domain event name.
     */
    public function registerIn(DomainEventHandlerRegistry $handlers): DomainEventHandlerRegistry
    {
        foreach (array_keys($this->eventTypes) as $name) {
            $handlers->register($name, $this);
        }

        return $handlers;
    }

    /**
     * Handler form (`function (DomainEvent $event, string $idempotencyKey): void`).
     */
    public function __invoke(DomainEvent $event, string $idempotencyKey): void
    {
        $this->deliver($event, $idempotencyKey);
    }

    /**
     * Sends the domain event to its instance.
     *
     * @param DomainEvent $event Domain event
     * @param string|null $idempotencyKey Key handed by the dispatcher (null = the event's own)
     * @return TransitionResult|null The machine's result; null when the event concerns no instance
     * @throws LogicException When the event name is not mapped (a registration mistake)
     */
    public function deliver(DomainEvent $event, ?string $idempotencyKey = null): ?TransitionResult
    {
        $type = $this->eventTypes[$event->name]
            ?? throw new LogicException(sprintf('Domain event "%s" is not mapped to a machine event type.', $event->name));
        $instanceId = ($this->instanceResolver)($event);
        if ($instanceId === null) {
            return null;
        }
        $machineEvent = Event::internal(
            $type,
            $event->occurredAt,
            $event->getPayload() + ['domain_event' => $event->name, 'kind' => $event->kind->value],
            $idempotencyKey ?? $event->getIdempotencyKey()
        );
        $context = $this->context === null ? null : ($this->context)($event, $instanceId);

        return $this->runner->send($instanceId, $machineEvent, $context, $this->actor);
    }

    /**
     * The map of domain event names to machine event types.
     *
     * @return array<string, string>
     */
    public function getEventTypes(): array
    {
        return $this->eventTypes;
    }
}
