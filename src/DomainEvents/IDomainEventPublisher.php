<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

/**
 * IDomainEventPublisher - Where the Events of a Write Go
 *
 * TriggeringRepository hands every event of a write to its publisher, inside the write's
 * transaction when it has one (forward 029, D5). Implementations: InMemoryDomainEventPublisher
 * (tests and simple use) and OutboxDomainEventPublisher (the production default: a transactional
 * outbox delivered after the commit by DomainEventDispatcher). A publisher is idempotent by the
 * event idempotency key: publishing the same event twice keeps one copy.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IDomainEventPublisher
{
    /**
     * Publishes the events of one write, in order.
     *
     * @param list<DomainEvent> $events Events (an empty list is a no-op)
     * @return int Number of events actually published (already published ones are skipped)
     */
    public function publish(array $events): int;
}
