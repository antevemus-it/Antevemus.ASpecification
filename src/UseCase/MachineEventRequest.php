<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\UseCase;

use Antevemus\ASpecification\Attributes\ValidateRule;
use DateTimeImmutable;

/**
 * MachineEventRequest - Request of a StateMachineEventUseCase
 *
 * Everything the MachineRunner::send() needs besides the event type and the domain context
 * (forward 024, D8): the instance id, the event payload, the instant the event happened (the
 * machine never reads the clock), an optional idempotency key, an optional actor (recorded in the
 * history) and an optional expected version (optimistic lock across a UI round trip). A blank
 * instance id is caught by the UseCaseRunner validation (#[ValidateRule], code
 * SM_INSTANCE_ID_REQUIRED) and gives INVALID.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage UseCase
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MachineEventRequest
{
    /**
     * @param string $instanceId Machine instance id
     * @param DateTimeImmutable $occurredAt Instant the event happened
     * @param array<mixed> $payload Event payload (motive, observation, amounts...)
     * @param string|null $idempotencyKey Optional idempotency key (deduplicates per instance)
     * @param string|null $actor Who triggered it, recorded in the history
     * @param int|null $expectedVersion Version the caller saw; null = no check beyond the runner's CAS
     */
    public function __construct(
        #[ValidateRule('not_blank', code: 'SM_INSTANCE_ID_REQUIRED', message: 'The machine instance id is required.')]
        public readonly string $instanceId,
        public readonly DateTimeImmutable $occurredAt,
        public readonly array $payload = [],
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $actor = null,
        public readonly ?int $expectedVersion = null
    ) {
    }
}
