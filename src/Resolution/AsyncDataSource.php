<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

use Antevemus\ASpecification\Resolution\Exceptions\DataFetchException;
use Antevemus\ASpecification\Resolution\Exceptions\DataPendingException;
use Antevemus\ASpecification\Resolution\Exceptions\DataResolutionException;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * AsyncDataSource - A Source That Submits a Request and Polls Its Result Later (2.5.0, forward 048, L10)
 *
 * For providers that answer in two steps: a credit bureau creates an analysis and returns a ticket, and the
 * result is read later with that ticket. fetch() is still synchronous and never waits: it submits the
 * request once, stores the ticket, polls, and either returns the value or throws DataPendingException; the
 * next resolution (the next request of the user, the next run of a job) polls again (D26).
 *
 * <code>
 * $credit = new AsyncDataSource(
 *     submit: fn(DataRequest $r, string $key): string => $bureau->createAnalysis($r->get('cpf'), idempotencyKey: $key),
 *     poll: fn(string $ticket, DataRequest $r): AsyncPoll => $bureau->isDone($ticket)
 *         ? AsyncPoll::ready($bureau->score($ticket))
 *         : AsyncPoll::pending(retryAfter: 30),
 *     tickets: $ticketStore,
 *     reconcile: fn(DataRequest $r, string $key): ?string => $bureau->findAnalysisByCpf($r->get('cpf')),
 * );
 * </code>
 *
 * fetch(), with the key of the request (DataRequest::getKey()):
 * - nothing stored: claims the key (IAsyncTicketStore::markSubmitting(), "submitting"), calls `submit` with
 *   the request and the key (an idempotency key for a provider that accepts one), stores the ticket and
 *   polls; a key claimed meanwhile by another process is pending;
 * - "submitting" without a ticket (the process stopped between sending and storing the ticket, or the
 *   sending failed half-way): never sent again (D27). `reconcile` (optional) looks the request up at the
 *   provider and returns its ticket; without it, or when it returns null, a DataFetchException "submission
 *   outcome unknown" (fail closed: a paid query is never repeated in silence; clearing the key in the store
 *   allows a new submission);
 * - a ticket: `poll` answers AsyncPoll::ready($value) (the value; cached by the resolver for the TTL, like
 *   any value), AsyncPoll::pending(?retryAfter) (DataPendingException, never cached) or
 *   AsyncPoll::failed($reason) (DataFetchException::sourceFailed; the ticket is kept, unless
 *   `resubmitAfterFailure`, which clears it so the next fetch submits again).
 *
 * The ticket is kept after the value is read, so asking the same question again reads the same result
 * instead of submitting a new one; the application clears it when it wants a new one. At most one
 * submission per key and store is what the library guarantees; exactly once needs an idempotency key
 * accepted by the provider. Any exception of a closure or of the store becomes a DataFetchException (the
 * original as the previous one); like every fetch failure it is the evaluation error of the leaf.
 *
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class AsyncDataSource implements IDataSource
{
    /**
     * @param Closure(DataRequest, string): string $submit Sends the request (with its key) and returns the ticket
     * @param Closure(string, DataRequest): AsyncPoll $poll Asks the provider about a ticket
     * @param IAsyncTicketStore $tickets Where the tickets are kept, by request key
     * @param int $cost Relative cost (lower is cheaper)
     * @param int|null $ttl Seconds a ready value may be cached between resolutions (null or 0: never)
     * @param Closure(DataRequest, string): ?string|null $reconcile Finds the ticket of a request whose
     *        submission outcome is unknown, or null
     * @param bool $resubmitAfterFailure Whether a terminal failure clears the ticket (the next fetch submits again)
     * @throws InvalidArgumentException When the TTL is negative
     */
    public function __construct(
        private readonly Closure $submit,
        private readonly Closure $poll,
        private readonly IAsyncTicketStore $tickets,
        private readonly int $cost = 0,
        private readonly ?int $ttl = null,
        private readonly ?Closure $reconcile = null,
        private readonly bool $resubmitAfterFailure = false
    ) {
        if ($ttl !== null && $ttl < 0) {
            throw new InvalidArgumentException('The TTL of a data source cannot be negative.');
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws DataPendingException When the result is not ready yet
     * @throws DataFetchException When the provider failed, the submission outcome is unknown or a closure raised
     */
    public function fetch(DataRequest $request): mixed
    {
        try {
            return $this->resolve($request);
        } catch (DataResolutionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw DataFetchException::sourceFailed($request->source, $e);
        }
    }

    /** {@inheritdoc} */
    public function getCost(): int
    {
        return $this->cost;
    }

    /** {@inheritdoc} */
    public function getTtl(): ?int
    {
        return $this->ttl;
    }

    /** The store of the tickets. */
    public function getTicketStore(): IAsyncTicketStore
    {
        return $this->tickets;
    }

    private function resolve(DataRequest $request): mixed
    {
        $key = $request->getKey();
        $known = $this->tickets->get($key);

        if ($known === null && !$this->tickets->markSubmitting($key)) {
            // Claimed by another process between get() and markSubmitting(): its ticket, or pending
            $known = $this->tickets->get($key) ?? throw new DataPendingException($request->source);
            if ($known->isSubmitting()) {
                throw new DataPendingException($request->source);
            }
        }

        if ($known === null) {
            // From here on the key stays "submitting" until a ticket is stored: an exception of submit() leaves
            // the outcome unknown, and the request is never sent again by itself (D27)
            $ticket = self::ticketOf(($this->submit)($request, $key), 'submit');
            $this->tickets->storeTicket($key, $ticket);
        } elseif ($known->isSubmitting()) {
            $reconciled = $this->reconcile !== null ? ($this->reconcile)($request, $key) : null;
            if ($reconciled === null) {
                throw DataFetchException::submissionOutcomeUnknown($request->source);
            }
            $ticket = self::ticketOf($reconciled, 'reconcile');
            $this->tickets->storeTicket($key, $ticket);
        } else {
            $ticket = $known->getTicket();
        }

        $answer = ($this->poll)($ticket, $request);
        if (!$answer instanceof AsyncPoll) {
            throw new UnexpectedValueException(sprintf('The poll closure must return an AsyncPoll, %s given.', get_debug_type($answer)));
        }

        if ($answer->isReady()) {
            return $answer->getValue();
        }
        if ($answer->isPending()) {
            throw new DataPendingException($request->source, $ticket, $answer->getRetryAfter());
        }
        if ($this->resubmitAfterFailure) {
            $this->tickets->clear($key);
        }

        throw DataFetchException::sourceFailed($request->source, new RuntimeException((string) $answer->getReason()));
    }

    /**
     * A ticket returned by a closure: a non-empty string.
     *
     * @throws UnexpectedValueException When it is anything else
     */
    private static function ticketOf(mixed $ticket, string $closure): string
    {
        if (!is_string($ticket) || $ticket === '') {
            throw new UnexpectedValueException(sprintf('The %s closure must return a ticket (a non-empty string), %s given.', $closure, get_debug_type($ticket)));
        }

        return $ticket;
    }
}
