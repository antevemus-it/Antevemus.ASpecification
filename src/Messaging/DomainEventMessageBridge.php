<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging;

use Antevemus\ASpecification\DomainEvents\DomainEvent;
use Antevemus\ASpecification\DomainEvents\DomainEventHandlerRegistry;
use Antevemus\ASpecification\Messaging\Exceptions\InvalidMessageException;
use Antevemus\ASpecification\Util\UlidGenerator;
use Closure;
use InvalidArgumentException;
use LogicException;

/**
 * DomainEventMessageBridge - Sends Domain Events Out as Messages
 *
 * A ready-made domain event handler (forward 063, D9): registered in a DomainEventHandlerRegistry, it
 * turns each domain event of a specification trigger ("an invoice became overdue") into a Message and
 * publishes it through a MessagePublisher, so that the triggers of 1.11.0 and 1.12.0 reach the other
 * modules by the same path as every other fact.
 *
 * - The message name is the event name when it is already `module.fact.vN`, or the one a map gives
 *   (domain event name => message name); a name that cannot be a message name is refused when the
 *   bridge is built, not when an event arrives.
 * - The payload is the data of the event, never the entity: `trigger`, `kind` (`enter`/`exit`),
 *   `entity_class` and `entity_id` (the keys of DomainEvent::toArray()).
 * - The ordering key is the queue of the entity in the domain event outbox: `<entity class>:<entity
 *   id>`, or `entity:` + the SHA-256 of both when that is longer than 190 characters.
 * - The idempotency is inherited: the message id is a ULID derived from the idempotency key of the
 *   event (time: the instant of the event; randomness: the first 80 bits of the SHA-256 of the key),
 *   so a redelivery of the event by the DomainEventDispatcher publishes the same message id, which the
 *   outbox skips; the header `domain-event-key` carries the key of the event.
 * - The tenant comes from an optional resolver `fn(DomainEvent): ?string` (a domain event has none).
 *
 * Under DomainEventDispatcher the bridge runs after the commit of the write; give the publisher the
 * outbox of the same database, and the event and its message are one more at-least-once hop.
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DomainEventMessageBridge
{
    /** Header that carries the idempotency key of the domain event. */
    public const HEADER_DOMAIN_EVENT_KEY = 'domain-event-key';

    /** @var array<string, string> domain event name => message name */
    private readonly array $names;

    /**
     * @param MessagePublisher $publisher Publisher of the messages
     * @param array<int|string, string> $names Domain event names (each already a message name), or a
     *                                         map domain event name => message name
     * @param (Closure(DomainEvent): ?string)|null $tenantResolver Tenant of an event (null = none)
     * @throws InvalidArgumentException When the list is empty, or a name cannot be a message name
     */
    public function __construct(
        private readonly MessagePublisher $publisher,
        array $names,
        private readonly ?Closure $tenantResolver = null
    ) {
        if ($names === []) {
            throw new InvalidArgumentException('The bridge needs at least one domain event name.');
        }
        $map = [];
        foreach ($names as $event => $message) {
            $event = is_int($event) ? $message : $event;
            if (!is_string($message) || !Message::isValidName($message) || $event === '') {
                throw new InvalidMessageException(sprintf(
                    'Every domain event of the bridge must map to a message name, module.fact.vN, at most %d characters (give a map domain event name => message name).',
                    Message::MAX_NAME_LENGTH
                ));
            }
            $map[$event] = $message;
        }
        $this->names = $map;
    }

    /**
     * Registers the bridge as a handler of every domain event name it maps.
     */
    public function registerIn(DomainEventHandlerRegistry $handlers): DomainEventHandlerRegistry
    {
        foreach (array_keys($this->names) as $event) {
            $handlers->register((string) $event, $this);
        }

        return $handlers;
    }

    /**
     * Handler form (`function (DomainEvent $event, string $idempotencyKey): void`): publishes the message.
     */
    public function __invoke(DomainEvent $event, string $idempotencyKey): void
    {
        $this->publisher->publish($this->toMessage($event, $idempotencyKey));
    }

    /**
     * The message of a domain event.
     *
     * @param string|null $idempotencyKey Key handed by the dispatcher (null = the event's own)
     * @throws LogicException When the event name is not mapped (a registration mistake)
     */
    public function toMessage(DomainEvent $event, ?string $idempotencyKey = null): Message
    {
        $name = $this->names[$event->name]
            ?? throw new LogicException(sprintf('Domain event "%s" is not mapped to a message name.', $event->name));
        $key = $idempotencyKey ?? $event->getIdempotencyKey();

        return new Message(
            $name,
            ['trigger' => $event->triggerName, 'kind' => $event->kind->value, 'entity_class' => $event->entityClass, 'entity_id' => $event->entityId],
            self::orderingKeyOf($event),
            $this->tenantResolver === null ? null : ($this->tenantResolver)($event),
            [self::HEADER_DOMAIN_EVENT_KEY => $key],
            self::messageIdOf($key, (int) $event->occurredAt->format('Uv')),
            $event->occurredAt
        );
    }

    /**
     * The map of domain event names to message names.
     *
     * @return array<string, string>
     */
    public function getNames(): array
    {
        return $this->names;
    }

    /** The ordering key of the messages of an entity. */
    public static function orderingKeyOf(DomainEvent $event): string
    {
        $key = $event->entityClass . ':' . $event->entityId;

        return mb_strlen($key) <= Message::MAX_ORDERING_KEY_LENGTH ? $key : 'entity:' . hash('sha256', $event->entityClass . "\0" . $event->entityId);
    }

    /**
     * The ULID derived from an idempotency key: the 48-bit time of the instant (milliseconds since
     * the epoch, clamped to the ULID range), then the first 80 bits of the SHA-256 of the key.
     */
    public static function messageIdOf(string $idempotencyKey, int $milliseconds): string
    {
        $time = max(0, min($milliseconds, UlidGenerator::MAX_TIME));
        $text = '';
        for ($i = 9; $i >= 0; $i--) {
            $text .= UlidGenerator::ALPHABET[($time >> ($i * 5)) & 31];
        }
        $bits = '';
        foreach (str_split(substr(hash('sha256', $idempotencyKey, true), 0, 10)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 5) as $chunk) {
            $text .= UlidGenerator::ALPHABET[bindec($chunk)];
        }

        return $text;
    }
}
