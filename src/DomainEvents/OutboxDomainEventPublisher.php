<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

use Antevemus\ASpecification\DomainEvents\Outbox\DomainEventOutboxEntry;
use Antevemus\ASpecification\DomainEvents\Outbox\IDomainEventOutbox;
use Antevemus\ASpecification\Util\UlidGenerator;

/**
 * OutboxDomainEventPublisher - Writes the Events to a Transactional Outbox
 *
 * The production default (forward 029, D5): each event becomes a pending outbox entry with a ULID
 * event id (its delivery order), written in the caller's transaction; DomainEventDispatcher
 * delivers them after the commit. An event whose idempotency key is already stored is skipped, so
 * re-applying the same write does not duplicate a row.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class OutboxDomainEventPublisher implements IDomainEventPublisher
{
    /**
     * @param IDomainEventOutbox $outbox Outbox written in the caller's transaction
     * @param UlidGenerator|null $ids Generator of event ids (null = the process-wide shared one)
     */
    public function __construct(
        private readonly IDomainEventOutbox $outbox,
        private readonly ?UlidGenerator $ids = null
    ) {
    }

    /** The outbox. */
    public function getOutbox(): IDomainEventOutbox
    {
        return $this->outbox;
    }

    /** {@inheritdoc} */
    public function publish(array $events): int
    {
        if ($events === []) {
            return 0;
        }
        $ids = $this->ids ?? UlidGenerator::shared();

        return $this->outbox->enqueue(array_map(
            static fn(DomainEvent $event): DomainEventOutboxEntry => new DomainEventOutboxEntry($ids->generate(), $event),
            array_values($events)
        ));
    }
}
