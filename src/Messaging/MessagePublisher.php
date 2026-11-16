<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging;

use Antevemus\ASpecification\Messaging\Outbox\IMessageOutbox;
use Antevemus\ASpecification\Messaging\Outbox\MessageOutboxEntry;
use Antevemus\ASpecification\Messaging\Support\MessagingSupport;
use Antevemus\ASpecification\Util\UlidGenerator;
use Closure;
use DateTimeImmutable;

/**
 * MessagePublisher - The Only Way to Publish: Writes Messages to the Outbox
 *
 * publish() writes each message as a pending outbox entry, within the caller's transaction (the same
 * PDO connection as the write that produced the fact, as OutboxDomainEventPublisher does), and never
 * talks to the broker (forward 063, D3): the write and its messages commit or roll back together,
 * and OutboxRelay sends them after the commit. There is no second path, so there is no dual write
 * (RN-02).
 *
 * Each entry gets a ULID entry id, its place in the order of its ordering key, and the instant of the
 * clock; a message whose id is already in the outbox is skipped, so publishing the same message again
 * (a retried request, a redelivered domain event through DomainEventMessageBridge) stores nothing.
 *
 * <code>
 * $publisher = new MessagePublisher(new PdoMessageOutbox($pdo));
 * $transactions->transactional(function () use ($repository, $quote, $publisher): void {
 *     $repository->put($quote);
 *     $publisher->publish(new Message('underwriting.quote_submitted.v1', ['quote_id' => $quote->id], orderingKey: 'quote:' . $quote->id));
 * });
 * </code>
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MessagePublisher
{
    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param IMessageOutbox $outbox Outbox written in the caller's transaction
     * @param UlidGenerator|null $ids Generator of entry ids (null = the process-wide shared one)
     * @param (Closure(): DateTimeImmutable)|null $clock Instant of each publication (null = now)
     */
    public function __construct(
        private readonly IMessageOutbox $outbox,
        private readonly ?UlidGenerator $ids = null,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? MessagingSupport::now(...);
    }

    /** The outbox. */
    public function getOutbox(): IMessageOutbox
    {
        return $this->outbox;
    }

    /**
     * Writes the messages to the outbox, in the order given.
     *
     * @return int Number of messages written (those already in the outbox are skipped)
     */
    public function publish(Message ...$messages): int
    {
        if ($messages === []) {
            return 0;
        }
        $ids = $this->ids ?? UlidGenerator::shared();
        $at = ($this->clock)();

        return $this->outbox->enqueue(array_map(
            static fn(Message $message): MessageOutboxEntry => new MessageOutboxEntry($ids->generate(), $message, $at),
            array_values($messages)
        ));
    }
}
