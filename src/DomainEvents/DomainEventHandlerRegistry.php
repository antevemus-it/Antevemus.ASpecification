<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

use Closure;
use InvalidArgumentException;

/**
 * DomainEventHandlerRegistry - Handlers of Domain Events by Event Name
 *
 * A handler is `function (DomainEvent $event, string $idempotencyKey): void` (forward 029, D5).
 * An event name may have several handlers; DomainEventDispatcher runs them in registration order
 * and stops at the first that throws (the entry is marked failed and retried whole, so every
 * handler deduplicates by the idempotency key: delivery is at-least-once).
 *
 * To reach an external bus (Kafka, RabbitMQ...), publish from a handler (D8: the library ships no
 * bus client).
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DomainEventHandlerRegistry
{
    /** @var array<string, list<Closure>> */
    private array $handlers = [];

    /**
     * Adds a handler for an event name.
     *
     * @param string $eventName Domain event name
     * @param callable $handler function (DomainEvent $event, string $idempotencyKey): void
     * @return self
     * @throws InvalidArgumentException When the name is empty or has surrounding whitespace
     */
    public function register(string $eventName, callable $handler): self
    {
        if ($eventName === '' || trim($eventName) !== $eventName) {
            throw new InvalidArgumentException('The domain event name must be a non-empty string without surrounding whitespace.');
        }
        $this->handlers[$eventName][] = Closure::fromCallable($handler);

        return $this;
    }

    /** True when the event name has at least one handler. */
    public function has(string $eventName): bool
    {
        return isset($this->handlers[$eventName]);
    }

    /**
     * Handlers of an event name, in registration order (empty when none).
     *
     * @return list<Closure>
     */
    public function handlersFor(string $eventName): array
    {
        return $this->handlers[$eventName] ?? [];
    }

    /**
     * Event names with handlers, in registration order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map('strval', array_keys($this->handlers));
    }
}
