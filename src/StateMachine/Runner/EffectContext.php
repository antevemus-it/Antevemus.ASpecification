<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Runner;

use Antevemus\ADeepPropertyAccess\PropertyAccess;
use Antevemus\ASpecification\StateMachine\Effect;
use Antevemus\ASpecification\StateMachine\Persistence\EventSnapshot;
use Antevemus\ASpecification\StateMachine\Persistence\OutboxEntry;
use DateTimeImmutable;

/**
 * EffectContext - Everything an Effect Handler May Need, in One Argument
 *
 * Since 2.10.0 a handler that declares a fourth parameter receives it:
 * `function (Effect $effect, string $idempotencyKey, string $instanceId, EffectContext $context): void`.
 * A handler with the three-parameter signature keeps working as before (EffectHandlerRegistry looks
 * at the signature once, when the handler is registered).
 *
 * Besides the effect, its idempotency key and the instance (the three arguments of before), it carries
 * the machine and instance versions of the step and, when the outbox kept it, the event of the step
 * (EventSnapshot): payload, actor, origin and instant. hasEvent() is false for an entry written by a
 * store that does not keep it (the PDO outbox without the opt-in column `event_snapshot`); the
 * accessors then answer an empty payload and nulls.
 *
 * @version    2.10.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Runner
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class EffectContext
{
    /**
     * @param Effect $effect The effect
     * @param string $idempotencyKey `{instanceId}:{instanceVersion}:{sortOrder}`, stable across retries
     * @param string $instanceId Instance id
     * @param string $machineKey Machine key
     * @param int $machineVersion Machine version the instance is pinned to
     * @param int $instanceVersion Instance version the step produced
     * @param EventSnapshot|null $event The event of the step, when the outbox kept it
     */
    public function __construct(
        public readonly Effect $effect,
        public readonly string $idempotencyKey,
        public readonly string $instanceId,
        public readonly string $machineKey,
        public readonly int $machineVersion,
        public readonly int $instanceVersion,
        public readonly ?EventSnapshot $event = null
    ) {
    }

    /**
     * The context of an outbox entry.
     */
    public static function of(OutboxEntry $entry): self
    {
        return new self(
            $entry->effect,
            $entry->effectKey(),
            $entry->instanceId,
            $entry->machineKey,
            $entry->machineVersion,
            $entry->instanceVersion,
            $entry->event
        );
    }

    /** True when the event of the step is known. */
    public function hasEvent(): bool
    {
        return $this->event !== null;
    }

    /** Type of the event of the step, or null when unknown. */
    public function eventType(): ?string
    {
        return $this->event?->type;
    }

    /**
     * Payload of the event of the step (empty when unknown).
     *
     * @return array<mixed>
     */
    public function payload(): array
    {
        return $this->event === null ? [] : $this->event->payload;
    }

    /**
     * A payload value by key or dot path (`motive`, `approval.level`); null when absent or unknown.
     */
    public function payloadValue(string $path): mixed
    {
        return $this->event === null ? null : PropertyAccess::getValue($this->event->payload, $path);
    }

    /** Who sent the event of the step, or null (unknown, or sent without an actor). */
    public function actor(): ?string
    {
        return $this->event?->actor;
    }

    /** Instant of the event of the step, or null when unknown. */
    public function occurredAt(): ?DateTimeImmutable
    {
        return $this->event?->occurredAt;
    }
}
