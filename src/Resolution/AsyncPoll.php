<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

use InvalidArgumentException;

/**
 * AsyncPoll - What the Provider Answered About a Ticket (2.5.0, forward 048, D26)
 *
 * Returned by the `poll` closure of an AsyncDataSource: the result is ready (its value), still pending
 * (with the delay the provider suggests, informative) or failed for good (a reason for the log).
 *
 * <code>
 * fn(string $ticket, DataRequest $request): AsyncPoll => match ($api->status($ticket)) {
 *     'done' => AsyncPoll::ready($api->result($ticket)),
 *     'error' => AsyncPoll::failed('analysis rejected by the bureau'),
 *     default => AsyncPoll::pending(retryAfter: 30),
 * };
 * </code>
 *
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class AsyncPoll
{
    private const READY = 'ready';
    private const PENDING = 'pending';
    private const FAILED = 'failed';

    private function __construct(
        private readonly string $state,
        private readonly mixed $value = null,
        private readonly ?int $retryAfter = null,
        private readonly ?string $reason = null
    ) {
    }

    /** The result is ready: its value is what the inner specification of the leaf evaluates. */
    public static function ready(mixed $value): self
    {
        return new self(self::READY, $value);
    }

    /**
     * The result is still being produced.
     *
     * @param int|null $retryAfter Seconds the provider suggests before asking again (informative)
     * @throws InvalidArgumentException When $retryAfter is negative
     */
    public static function pending(?int $retryAfter = null): self
    {
        if ($retryAfter !== null && $retryAfter < 0) {
            throw new InvalidArgumentException('The retry delay of a pending request cannot be negative.');
        }

        return new self(self::PENDING, null, $retryAfter);
    }

    /**
     * The provider gave up on the request (a terminal failure).
     *
     * @param string $reason Reason, kept as the previous exception of the DataFetchException (for the log)
     */
    public static function failed(string $reason): self
    {
        return new self(self::FAILED, null, null, $reason);
    }

    public function isReady(): bool
    {
        return $this->state === self::READY;
    }

    public function isPending(): bool
    {
        return $this->state === self::PENDING;
    }

    public function isFailed(): bool
    {
        return $this->state === self::FAILED;
    }

    /** The value of a ready result (null otherwise). */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /** The delay suggested by a pending answer, or null. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /** The reason of a failed answer, or null. */
    public function getReason(): ?string
    {
        return $this->reason;
    }
}
