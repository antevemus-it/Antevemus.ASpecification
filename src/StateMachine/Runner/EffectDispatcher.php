<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Runner;

use Antevemus\ASpecification\StateMachine\Persistence\IEffectOutbox;
use Antevemus\ASpecification\StateMachine\Persistence\OutboxEntry;
use Antevemus\ASpecification\StateMachine\Persistence\PersistenceSupport;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * EffectDispatcher - Delivers the Outbox After the Commit
 *
 * Runs outside the runner's transaction (forward 023, D12, RN-10), typically from a worker or a
 * cron: dispatchPending($limit) delivers up to $limit outbox entries to their handlers
 * (EffectHandlerRegistry), each with its idempotency key, and marks each one delivered or failed
 * (attempts + 1, last error message without stack trace). A failing handler never undoes the
 * transition that produced the effect.
 *
 * Order: per instance, in write order. The dispatcher works in rounds over the queue heads
 * (IEffectOutbox::fetchPending()): an entry is only attempted when every earlier entry of its
 * instance was delivered (or is exhausted), and once an entry of an instance fails, the instance
 * is left alone until the next run, so a later effect never overtakes a failed one. Without
 * $maxAttempts a failing effect holds its instance's later effects back until it succeeds; with
 * it, an entry that reached the limit is exhausted (kept as failed, no longer attempted, no
 * longer blocking).
 *
 * Delivery is at-least-once: two dispatchers running at the same time may deliver the same
 * entry; handlers use the idempotency key to deduplicate.
 *
 * Since 2.10.0 every handler is called through EffectHandlerRegistry::invoke(), so a handler that
 * declares the fourth parameter receives the EffectContext of its entry (with the event of the step,
 * when the outbox kept it).
 *
 * @version    2.10.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Runner
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class EffectDispatcher
{
    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param IEffectOutbox $outbox Outbox to deliver
     * @param EffectHandlerRegistry $handlers Handlers by action name
     * @param int|null $maxAttempts Attempts after which an entry is exhausted; null = retry forever
     * @param (Closure(): DateTimeImmutable)|null $clock Instant recorded for each attempt (default: system clock)
     * @throws InvalidArgumentException When $maxAttempts < 1
     */
    public function __construct(
        private readonly IEffectOutbox $outbox,
        private readonly EffectHandlerRegistry $handlers,
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
     * @return DispatchReport
     */
    public function dispatchPending(int $limit = 100): DispatchReport
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('The limit must be at least 1, %d given.', $limit));
        }
        $delivered = [];
        $failures = [];
        /** @var array<string, true> $blocked instances with a failed attempt in this run */
        $blocked = [];
        $attempted = 0;

        while ($attempted < $limit) {
            $heads = array_values(array_filter(
                $this->outbox->fetchPending($limit - $attempted + count($blocked), $this->maxAttempts),
                static fn(OutboxEntry $e): bool => !isset($blocked[$e->instanceId])
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
                    $failures[$entry->effectKey()] = $error;
                    $blocked[$entry->instanceId] = true;
                }
            }
        }

        return new DispatchReport($delivered, $failures);
    }

    /**
     * Attempts one entry; returns null when delivered, the recorded error message otherwise.
     */
    private function deliver(OutboxEntry $entry): ?string
    {
        if (!$this->handlers->has($entry->effect->action)) {
            $error = sprintf('No handler registered for action "%s".', $entry->effect->action);
            $this->outbox->markFailed($entry, $error, ($this->clock)());

            return $error;
        }
        try {
            $this->handlers->invoke(EffectContext::of($entry));
        } catch (Throwable $e) {
            $error = PersistenceSupport::errorMessage($e);
            $this->outbox->markFailed($entry, $error, ($this->clock)());

            return $error;
        }
        $this->outbox->markDelivered($entry, ($this->clock)());

        return null;
    }
}
