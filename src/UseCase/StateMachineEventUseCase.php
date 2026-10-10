<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\UseCase;

use Antevemus\ASpecification\StateMachine\Event;
use Antevemus\ASpecification\StateMachine\Exceptions\ConcurrencyConflictException;
use Antevemus\ASpecification\StateMachine\Exceptions\MachineInstanceNotFoundException;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;
use Closure;
use InvalidArgumentException;

/**
 * StateMachineEventUseCase - One Event of a State Machine Exposed as a Use Case
 *
 * Ready-made adapter (forward 024, D8): built with the MachineRunner, the event type and a context
 * factory, it takes a MachineEventRequest, sends an external event of that type through the
 * runner and converts the outcome with UseCaseResult::fromTransition():
 * - accepted → SUCCESS, payload = the snapshot after the event (or the original result's snapshot
 *   when the idempotency key was already processed);
 * - rejected → REJECTED with the guards' reason (e.g. QUOTE_PENDING_OPEN);
 * - unhandled (the event does not fit the current state, or the machine is done) → CONFLICT;
 * - ConcurrencyConflictException (stale expectedVersion or lost compare-and-set) → CONFLICT with
 *   the code SM_CONCURRENCY_CONFLICT;
 * - MachineInstanceNotFoundException → NOT_FOUND with the code SM_INSTANCE_NOT_FOUND.
 * Any other exception (synchronous effect veto, persistence failure) propagates to the
 * UseCaseRunner, which returns FAILED without leaking it. When a guard threw while being evaluated,
 * its exception is handed to the optional error reporter (the result keeps a generic message).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage UseCase
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 *
 * @implements IUseCase<MachineEventRequest, \Antevemus\ASpecification\StateMachine\MachineSnapshot>
 */
final class StateMachineEventUseCase implements IUseCase
{
    /** Code of the NOT_FOUND result of an unknown instance id. */
    public const CODE_INSTANCE_NOT_FOUND = 'SM_INSTANCE_NOT_FOUND';

    /** @var (Closure(MachineEventRequest): (object|array<mixed>|null))|null */
    private readonly ?Closure $contextFactory;

    /**
     * @param MachineRunner $runner The state machine's single write port
     * @param string $eventType Event type sent by this use case
     * @param callable(MachineEventRequest): (object|array<mixed>|null)|null $contextFactory Builds the
     *        domain context read by the guards (null = no context)
     * @param IUseCaseErrorReporter|null $reporter Receives the exception of a guard that threw
     * @throws InvalidArgumentException When the event type is blank
     */
    public function __construct(
        private readonly MachineRunner $runner,
        private readonly string $eventType,
        ?callable $contextFactory = null,
        private readonly ?IUseCaseErrorReporter $reporter = null
    ) {
        if ($eventType === '' || trim($eventType) !== $eventType) {
            throw new InvalidArgumentException('The event type must be a non-empty string without surrounding whitespace.');
        }
        $this->contextFactory = $contextFactory === null ? null : $contextFactory(...);
    }

    /** Event type sent by this use case. */
    public function getEventType(): string
    {
        return $this->eventType;
    }

    /**
     * @param MachineEventRequest $request
     * @throws InvalidArgumentException When the request is not a MachineEventRequest
     */
    public function execute(object $request): UseCaseResult
    {
        if (!$request instanceof MachineEventRequest) {
            throw new InvalidArgumentException(sprintf(
                '%s expects a %s request, %s given.',
                self::class,
                MachineEventRequest::class,
                get_class($request)
            ));
        }
        $context = $this->contextFactory === null ? null : ($this->contextFactory)($request);
        $event = Event::external($this->eventType, $request->occurredAt, $request->payload, $request->idempotencyKey);

        try {
            $transition = $this->runner->send($request->instanceId, $event, $context, $request->actor, $request->expectedVersion);
        } catch (ConcurrencyConflictException $conflict) {
            return UseCaseResult::fromConcurrencyConflict($conflict);
        } catch (MachineInstanceNotFoundException) {
            return UseCaseResult::notFound(
                self::CODE_INSTANCE_NOT_FOUND,
                sprintf('Machine instance "%s" does not exist.', $request->instanceId),
                ['instance_id' => $request->instanceId]
            );
        }

        $guardError = $transition->getReason()->exception;
        if ($guardError !== null && $this->reporter !== null) {
            try {
                $this->reporter->report($guardError, $this, $request);
            } catch (\Throwable) {
                // A broken reporter never changes the outcome.
            }
        }

        return UseCaseResult::fromTransition($transition);
    }
}
