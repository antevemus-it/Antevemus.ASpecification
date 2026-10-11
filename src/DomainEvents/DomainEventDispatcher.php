<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

use Antevemus\ASpecification\DomainEvents\Outbox\DomainEventOutboxEntry;
use Antevemus\ASpecification\DomainEvents\Outbox\DomainEventSupport;
use Antevemus\ASpecification\DomainEvents\Outbox\IDomainEventOutbox;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * DomainEventDispatcher - Delivers the Domain Event Outbox After the Commit
 *
 * Runs outside the write's transaction (forward 029, D5), typically from a worker or a cron, in
 * the design of the 1.8.0 EffectDispatcher: dispatchPending($limit) delivers up to $limit entries
 * to the handlers of their event name (DomainEventHandlerRegistry), each with the event's
 * idempotency key, and marks each one delivered or failed (attempts + 1, last error message
 * without stack trace). A failing handler never undoes the write that produced the event. An
 * event with no handler is a failed attempt ("No handler registered..."), not a silent drop.
 *
 * Order: per entity, in write order (FIFO by event id). The dispatcher works in rounds over the
 * queue heads (IDomainEventOutbox::fetchPending()): an entry is only attempted when every earlier
 * entry of its entity was delivered (or is exhausted), and once an entry of an entity fails, the
 * entity is left alone until the next run. Without $maxAttempts a failing event holds its
 * entity's later events back until it succeeds; with it, an entry that reached the limit is
 * exhausted (kept as failed, no longer attempted, no longer blocking).
 *
 * Delivery is at-least-once: handlers deduplicate by the idempotency key (MachineEventBridge does
 * it through the runner's idempotency store).
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DomainEventDispatcher
{
    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param IDomainEventOutbox $outbox Outbox to deliver
     * @param DomainEventHandlerRegistry $handlers Handlers by event name
     * @param int|null $maxAttempts Attempts after which an entry is exhausted; null = retry forever
     * @param (Closure(): DateTimeImmutable)|null $clock Instant recorded for each attempt (default: system clock)
     * @throws InvalidArgumentException When $maxAttempts < 1
     */
    public function __construct(
        private readonly IDomainEventOutbox $outbox,
        private readonly DomainEventHandlerRegistry $handlers,
        private readonly ?int $maxAttempts = null,
        ?Closure $clock = null
    ) {
        if ($maxAttempts !== null && $maxAttempts < 1) {
            throw new InvalidArgumentException(sprintf('maxAttempts must be at least 1, %d given.', $maxAttempts));
        }
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable('now');
    }

    /**
     * Delivers up to $limit pending entries (each attempt counts, delivered or failed).
     *
     * @param int $limit Maximum number of attempts in this run (>= 1)
     */
    public function dispatchPending(int $limit = 100): DomainEventDispatchReport
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('The limit must be at least 1, %d given.', $limit));
        }
        $delivered = [];
        $failures = [];
        /** @var array<string, true> $blocked entities with a failed attempt in this run */
        $blocked = [];
        $attempted = 0;

        while ($attempted < $limit) {
            $heads = array_values(array_filter(
                $this->outbox->fetchPending($limit - $attempted + count($blocked), $this->maxAttempts),
                static fn(DomainEventOutboxEntry $e): bool => !isset($blocked[$e->queueKey()])
            ));
            if ($heads === []) {
                break;
            }
            foreach ($heads as $entry) {
                if ($attempted >= $limit) {
                    break 2;
                }
                $attempted++;
                $error = $this->deliver($entry);
                if ($error === null) {
                    $delivered[] = $entry;
                } else {
                    $failures[$entry->idempotencyKey()] = $error;
                    $blocked[$entry->queueKey()] = true;
                }
            }
        }

        return new DomainEventDispatchReport($delivered, $failures);
    }

    /**
     * Attempts one entry; returns null when delivered, the recorded error message otherwise.
     */
    private function deliver(DomainEventOutboxEntry $entry): ?string
    {
        $handlers = $this->handlers->handlersFor($entry->event->name);
        if ($handlers === []) {
            $error = sprintf('No handler registered for domain event "%s".', $entry->event->name);
            $this->outbox->markFailed($entry, $error, ($this->clock)());

            return $error;
        }
        try {
            foreach ($handlers as $handler) {
                $handler($entry->event, $entry->idempotencyKey());
            }
        } catch (Throwable $e) {
            $error = DomainEventSupport::errorMessage($e);
            $this->outbox->markFailed($entry, $error, ($this->clock)());

            return $error;
        }
        $this->outbox->markDelivered($entry, ($this->clock)());

        return null;
    }
}
