<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

use Antevemus\ASpecification\StateMachine\Persistence\InMemory\IInMemoryTransactional;

/**
 * InMemoryDomainEventPublisher - Collects the Published Events in Memory
 *
 * For tests and simple use (forward 029, D5): keeps every published event, in order, one per
 * idempotency key. It delivers nothing by itself: read published() (or drain() it) and react.
 * It takes part in an InMemoryTransactionManager, so a rolled back write drops its events.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryDomainEventPublisher implements IDomainEventPublisher, IInMemoryTransactional
{
    /** @var array<string, DomainEvent> idempotency key => event, in publication order */
    private array $events = [];

    /** {@inheritdoc} */
    public function publish(array $events): int
    {
        $published = 0;
        foreach ($events as $event) {
            $key = $event->getIdempotencyKey();
            if (!isset($this->events[$key])) {
                $this->events[$key] = $event;
                $published++;
            }
        }

        return $published;
    }

    /**
     * Every published event, in publication order.
     *
     * @return list<DomainEvent>
     */
    public function published(): array
    {
        return array_values($this->events);
    }

    /**
     * Names of the published events, in order (assertions, logs).
     *
     * @return list<string>
     */
    public function publishedNames(): array
    {
        return array_map(static fn(DomainEvent $e): string => $e->name, array_values($this->events));
    }

    /**
     * Returns the published events and forgets them.
     *
     * @return list<DomainEvent>
     */
    public function drain(): array
    {
        $events = array_values($this->events);
        $this->events = [];

        return $events;
    }

    /** {@inheritdoc} */
    public function exportState(): array
    {
        return $this->events;
    }

    /** {@inheritdoc} */
    public function importState(array $state): void
    {
        $this->events = $state;
    }
}
