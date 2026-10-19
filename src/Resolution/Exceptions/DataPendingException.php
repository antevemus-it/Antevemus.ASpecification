<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution\Exceptions;

use InvalidArgumentException;
use Throwable;

/**
 * DataPendingException - The Source Accepted the Request; Its Result Is Not Ready Yet (2.5.0)
 *
 * Thrown by a data source that works in two steps (submit, then poll: a credit bureau analysis, a
 * document check) when the result of a request it already holds is still being produced (forward 048,
 * D25). It is not a fourth truth value: under evaluate() the external data leaf answers the evaluation
 * error it answers for any fetch failure, so a pending datum never approves nor refuses, NOT does not
 * invert it, AND propagates it and OR follows its usual left-to-right rule. The caller tells it apart
 * through SpecificationResult::isPending(), RuleEngineVerdict::isPending() and
 * ResolvedData::getPendingRequests(), and evaluates again later (the next request, the next run of a job):
 * nothing in the library waits or loops.
 *
 * Like every exception of the resolution, the message carries neither the ticket nor a parameter value.
 *
 * @version    2.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DataPendingException extends DataResolutionException
{
    /**
     * @param string $source Name of the data source
     * @param string|null $ticket Identifier the provider gave to the request, when known
     * @param int|null $retryAfter Seconds the provider suggests before asking again (informative)
     * @param Throwable|null $previous Original exception, when any
     * @throws InvalidArgumentException When $retryAfter is negative
     */
    public function __construct(
        private readonly string $source,
        private readonly ?string $ticket = null,
        private readonly ?int $retryAfter = null,
        ?Throwable $previous = null
    ) {
        if ($retryAfter !== null && $retryAfter < 0) {
            throw new InvalidArgumentException('The retry delay of a pending request cannot be negative.');
        }
        parent::__construct(sprintf('Data source "%s" accepted the request; its result is not ready yet.', $source), 0, $previous);
    }

    /** Name of the data source. */
    public function getSource(): string
    {
        return $this->source;
    }

    /** Identifier the provider gave to the request, or null. */
    public function getTicket(): ?string
    {
        return $this->ticket;
    }

    /** Seconds the provider suggests before asking again, or null (informative: nothing waits). */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * Whether a throwable, or one of its previous exceptions, is a DataPendingException.
     */
    public static function isPendingThrowable(?Throwable $throwable): bool
    {
        for ($current = $throwable; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof self) {
                return true;
            }
        }

        return false;
    }
}
