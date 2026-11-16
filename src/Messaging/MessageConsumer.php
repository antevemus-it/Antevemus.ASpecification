<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Messaging;

use Antevemus\ASpecification\Messaging\Exceptions\InvalidMessagePayloadException;
use Antevemus\ASpecification\Messaging\Exceptions\MessagingPersistenceException;
use Antevemus\ASpecification\Messaging\Inbox\IProcessedMessageStore;
use Antevemus\ASpecification\Messaging\Inbox\ProcessedMessage;
use Antevemus\ASpecification\Messaging\Support\MessagingSupport;
use Antevemus\ASpecification\Persistence\ITransactionManager;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * MessageConsumer - Hands the Deliveries of a Source to the Handlers, Once per Consumer
 *
 * The idempotent consumer of forward 063 (D8, D10). consume($max, $waitMs) receives up to $max
 * deliveries from the IMessageSource and, for each one, in the order received:
 *
 * 1. an invalid delivery (the broker delivered something that is not a message) is rejected without
 *    requeue: `dead_lettered`;
 * 2. a consumer restricted to a tenant acknowledges a message of another tenant (or of none) without
 *    running anything: `other_tenant` (D10);
 * 3. a message whose ordering key had a delivery retried earlier in this run is rejected with requeue
 *    without running: `held_back` (the source gives the earlier one again first, so the key keeps its
 *    order, RN-04);
 * 4. a message the inbox already has for this consumer is acknowledged: `duplicate` (RN-03);
 * 5. a message whose name has no handler is acknowledged with a warning: `unknown_name`;
 * 6. a delivery whose count is above maxDeliveries is rejected without requeue without running (a
 *    message that kills the process before it answers): `dead_lettered`;
 * 7. otherwise the inbox row and the handlers run in ONE transaction of the ITransactionManager (the
 *    row first, so that a concurrent process of the same consumer collides on the primary key):
 *    committed, the delivery is acknowledged (`handled`); a handler that throws rolls the
 *    transaction back, and the delivery is rejected with requeue (`retried`, its key held back for
 *    the run), or without requeue when the handler threw InvalidMessagePayloadException or this was
 *    delivery maxDeliveries (`dead_lettered`).
 *
 * The effect of a handler is exactly once per consumer when the handler writes through the
 * connection of the transaction manager (and of the inbox): the row and the effect commit together,
 * and a redelivery finds the row. Without a transaction manager the handlers run first and the row is
 * written after them, without atomicity: a process that dies between the two runs the handlers again
 * on the redelivery (at-least-once). A dead-lettered message unblocks its key: a later message of the
 * key is handled, and the report declares the dead letter (RN-04).
 *
 * What does not belong to a message propagates and stops the run, leaving the rest in flight to be
 * delivered again: a failure of ack()/reject() or of the inbox outside a handler (the database went
 * away). The one exception is a race of two processes of the same consumer on the inbox (an integrity
 * violation of its primary key): the delivery is requeued (`retried`), and its next delivery finds it
 * handled.
 *
 * Secrecy (RN-05): a reason names the message (its name, id and ordering key), the consumer and the
 * class of the exception of a handler, never the message of that exception, which may quote the
 * payload. Give `onHandlerError` (`fn(Message, Throwable, int $deliveryCount): void`) to log the
 * exception itself; whatever it throws is ignored.
 *
 * @version    2.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Messaging
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MessageConsumer
{
    /** Default number of deliveries after which a failing message goes to the dead letter queue. */
    public const DEFAULT_MAX_DELIVERIES = 5;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    private readonly string $consumer;

    /**
     * @param string $consumer Name of the consumer (its partition of the inbox: lowercase, at most 120 characters)
     * @param IMessageSource $source Deliveries of the broker to this consumer
     * @param MessageHandlerRegistry $handlers Handlers by message name
     * @param IProcessedMessageStore $inbox The inbox
     * @param ITransactionManager|null $transactions Transaction of the inbox row and the handlers (null = none)
     * @param int $maxDeliveries Deliveries after which a failing message is dead-lettered (>= 1)
     * @param string|null $tenant The only tenant this consumer handles (null = every message)
     * @param (Closure(): DateTimeImmutable)|null $clock Instant of the inbox rows (null = now)
     * @param (Closure(Message, Throwable, int): void)|null $onHandlerError Receives the exception of a handler
     * @throws InvalidArgumentException When the consumer, maxDeliveries or the tenant is invalid
     */
    public function __construct(
        string $consumer,
        private readonly IMessageSource $source,
        private readonly MessageHandlerRegistry $handlers,
        private readonly IProcessedMessageStore $inbox,
        private readonly ?ITransactionManager $transactions = null,
        private readonly int $maxDeliveries = self::DEFAULT_MAX_DELIVERIES,
        private readonly ?string $tenant = null,
        ?Closure $clock = null,
        private readonly ?Closure $onHandlerError = null
    ) {
        $this->consumer = ProcessedMessage::assertConsumer($consumer);
        if ($maxDeliveries < 1) {
            throw new InvalidArgumentException(sprintf('maxDeliveries must be at least 1, %d given.', $maxDeliveries));
        }
        if ($tenant !== null && ($tenant === '' || trim($tenant) !== $tenant || mb_strlen($tenant) > Message::MAX_TENANT_LENGTH)) {
            throw new InvalidArgumentException(sprintf('The tenant of a consumer must be null or a non-empty text without surrounding whitespace, at most %d characters.', Message::MAX_TENANT_LENGTH));
        }
        $this->clock = $clock ?? MessagingSupport::now(...);
    }

    /** Name of the consumer. */
    public function getConsumer(): string
    {
        return $this->consumer;
    }

    /** The only tenant this consumer handles (null = every message). */
    public function getTenant(): ?string
    {
        return $this->tenant;
    }

    /** Deliveries after which a failing message is dead-lettered. */
    public function getMaxDeliveries(): int
    {
        return $this->maxDeliveries;
    }

    /**
     * Receives up to $max deliveries and handles them (see the class description).
     *
     * @param int $max Maximum number of deliveries (>= 1)
     * @param int $waitMs Longest wait for the first delivery, in milliseconds (>= 0)
     * @throws InvalidArgumentException When $max < 1 or $waitMs < 0
     */
    public function consume(int $max = 100, int $waitMs = 0): ConsumeReport
    {
        if ($max < 1) {
            throw new InvalidArgumentException(sprintf('consume() takes at least 1 delivery, %d given.', $max));
        }
        if ($waitMs < 0) {
            throw new InvalidArgumentException(sprintf('consume() cannot wait a negative time, %d given.', $waitMs));
        }
        $entries = [];
        /** @var array<string, true> $held ordering keys with a delivery retried in this run */
        $held = [];
        foreach ($this->source->receive($max, $waitMs) as $delivery) {
            $entries[] = $this->handle($delivery, $held);
        }

        return new ConsumeReport($entries);
    }

    /**
     * @param array<string, true> $held
     */
    private function handle(ReceivedMessage $delivery, array &$held): ConsumeEntry
    {
        if (!$delivery->isValid()) {
            $reason = (string) $delivery->invalidReason();
            $this->source->reject($delivery, false, $reason);

            return new ConsumeEntry($delivery->brokerId, null, null, null, $delivery->deliveryCount, ConsumeOutcome::DEAD_LETTERED, $reason);
        }
        $message = $delivery->message();
        $entry = fn(ConsumeOutcome $outcome, ?string $reason = null): ConsumeEntry => new ConsumeEntry(
            $delivery->brokerId,
            $message->messageId,
            $message->name,
            $message->orderingKey,
            $delivery->deliveryCount,
            $outcome,
            $reason
        );
        if ($this->tenant !== null && $message->tenant !== $this->tenant) {
            $this->source->ack($delivery);

            return $entry(ConsumeOutcome::OTHER_TENANT, sprintf(
                'Consumer "%s" handles tenant "%s" only; %s is %s: acknowledged without handling.',
                $this->consumer,
                $this->tenant,
                $message->describe(),
                $message->tenant === null ? 'of no tenant' : sprintf('of tenant "%s"', $message->tenant)
            ));
        }
        $key = $message->orderingKey;
        if ($key !== null && isset($held[$key])) {
            $reason = sprintf('An earlier message of ordering key "%s" was retried in this run: %s held back.', $key, $message->describe());
            $this->source->reject($delivery, true, $reason);

            return $entry(ConsumeOutcome::HELD_BACK, $reason);
        }
        if ($this->inbox->has($this->consumer, $message->idempotencyKey())) {
            $this->source->ack($delivery);

            return $entry(ConsumeOutcome::DUPLICATE);
        }
        $handlers = $this->handlers->handlersFor($message->name);
        if ($handlers === []) {
            $this->source->ack($delivery);

            return $entry(ConsumeOutcome::UNKNOWN_NAME, sprintf('Consumer "%s" has no handler for %s: acknowledged without handling.', $this->consumer, $message->describe()));
        }
        if ($delivery->deliveryCount > $this->maxDeliveries) {
            $reason = sprintf('%s was delivered %d times to consumer "%s", more than maxDeliveries (%d).', ucfirst($message->describe()), $delivery->deliveryCount, $this->consumer, $this->maxDeliveries);
            $this->source->reject($delivery, false, $reason);

            return $entry(ConsumeOutcome::DEAD_LETTERED, $reason);
        }

        $failure = null;
        try {
            $written = $this->run($message, $handlers, $failure);
        } catch (Throwable $e) {
            if ($e !== $failure) {
                if (!$e instanceof MessagingPersistenceException || !$e->isIntegrityViolation()) {
                    throw $e;
                }
                $reason = sprintf('The inbox of consumer "%s" got %s from another process at the same time: requeued.', $this->consumer, $message->describe());
                $this->source->reject($delivery, true, $reason);
                if ($key !== null) {
                    $held[$key] = true;
                }

                return $entry(ConsumeOutcome::RETRIED, $reason);
            }
            $this->reportHandlerError($message, $e, $delivery->deliveryCount);
            $final = $e instanceof InvalidMessagePayloadException || $delivery->deliveryCount >= $this->maxDeliveries;
            $reason = sprintf(
                'A handler of consumer "%s" failed for %s (%s) on delivery %d of at most %d%s.',
                $this->consumer,
                $message->describe(),
                MessagingSupport::shortClass($e),
                $delivery->deliveryCount,
                $this->maxDeliveries,
                $e instanceof InvalidMessagePayloadException ? '; the payload was refused' : ''
            );
            $this->source->reject($delivery, !$final, $reason);
            if ($final) {
                return $entry(ConsumeOutcome::DEAD_LETTERED, $reason);
            }
            if ($key !== null) {
                $held[$key] = true;
            }

            return $entry(ConsumeOutcome::RETRIED, $reason);
        }
        $this->source->ack($delivery);

        return $entry($written ? ConsumeOutcome::HANDLED : ConsumeOutcome::DUPLICATE);
    }

    /**
     * Runs the inbox row and the handlers; false when, inside the transaction, the row was already
     * there (nothing ran). Without a transaction manager the handlers run first and true is returned.
     *
     * @param list<Closure> $handlers
     * @param Throwable|null $failure Set to the exception of a handler, when one throws
     */
    private function run(Message $message, array $handlers, ?Throwable &$failure): bool
    {
        $handle = static function () use ($message, $handlers, &$failure): void {
            try {
                foreach ($handlers as $handler) {
                    $handler($message);
                }
            } catch (Throwable $e) {
                $failure = $e;
                throw $e;
            }
        };
        if ($this->transactions === null) {
            $handle();
            $this->inbox->markProcessed($this->consumer, $message, ($this->clock)());

            return true;
        }

        return (bool) $this->transactions->transactional(function () use ($message, $handle): bool {
            if (!$this->inbox->markProcessed($this->consumer, $message, ($this->clock)())) {
                return false;
            }
            $handle();

            return true;
        });
    }

    private function reportHandlerError(Message $message, Throwable $error, int $deliveryCount): void
    {
        if ($this->onHandlerError === null) {
            return;
        }
        try {
            ($this->onHandlerError)($message, $error, $deliveryCount);
        } catch (Throwable) {
            // A broken logger never changes the outcome of a delivery.
        }
    }
}
