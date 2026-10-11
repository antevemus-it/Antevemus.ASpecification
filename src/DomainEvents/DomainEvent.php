<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

use Antevemus\ASpecification\DomainEvents\Outbox\DomainEventSupport;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * DomainEvent - Immutable Record of a Specification Crossing
 *
 * Emitted by the TriggerEvaluator when an entity starts (`enter`) or stops (`exit`) satisfying a
 * trigger's specification (forward 029, D4). It carries the event name, the trigger, the kind, the
 * entity class and id, the instant (`occurredAt`, UTC) and a minimal payload: the trigger and the
 * entity id, never the entity itself, so that no personal data travels to the outbox, a queue or
 * a log. A handler that needs the entity loads it by id.
 *
 * The idempotency key is deterministic: `de:` followed by the SHA-256 (hex) of the trigger, the
 * entity class, the entity id, the kind and the UTC instant. The same crossing stamped at the same
 * instant always yields the same key, so the outbox keeps one row for it and a machine receiving it
 * through MachineEventBridge moves once. The key has 67 characters, below the 190 of the
 * idempotency columns of both reference DDLs.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DomainEvent
{
    /** Prefix of every domain event idempotency key. */
    public const KEY_PREFIX = 'de:';

    public readonly string $name;
    public readonly string $triggerName;
    public readonly string $entityClass;
    public readonly string $entityId;
    public readonly DateTimeImmutable $occurredAt;
    private readonly string $idempotencyKey;

    /**
     * @param string $name Event name (the trigger's onEnter or onExit)
     * @param string $triggerName Trigger that emitted it
     * @param DomainEventKind $kind Enter or exit
     * @param string $entityClass Class of the entity
     * @param string $entityId Entity id, in text form (at most 190 characters)
     * @param DateTimeImmutable $occurredAt Instant of the write (stored in UTC)
     * @throws InvalidArgumentException When a name, the class or the id is invalid
     */
    public function __construct(
        string $name,
        string $triggerName,
        public readonly DomainEventKind $kind,
        string $entityClass,
        string $entityId,
        DateTimeImmutable $occurredAt
    ) {
        $this->name = DomainEventSupport::assertName($name, 'domain event name');
        $this->triggerName = DomainEventSupport::assertName($triggerName, 'trigger name');
        if ($entityClass === '' || strlen($entityClass) > DomainEventSupport::MAX_ENTITY_CLASS_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The entity class must be a non-empty string of at most %d characters.',
                DomainEventSupport::MAX_ENTITY_CLASS_LENGTH
            ));
        }
        if ($entityId === '' || mb_strlen($entityId) > DomainEventSupport::MAX_ENTITY_ID_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The entity id of a domain event must be a non-empty string of at most %d characters.',
                DomainEventSupport::MAX_ENTITY_ID_LENGTH
            ));
        }
        $this->entityClass = $entityClass;
        $this->entityId = $entityId;
        $this->occurredAt = DomainEventSupport::utc($occurredAt);
        $this->idempotencyKey = self::KEY_PREFIX . hash('sha256', implode("\n", [
            $this->triggerName,
            $this->entityClass,
            $this->entityId,
            $this->kind->value,
            DomainEventSupport::toText($this->occurredAt),
        ]));
    }

    /** Event name. */
    public function getName(): string
    {
        return $this->name;
    }

    /** Trigger that emitted the event. */
    public function getTriggerName(): string
    {
        return $this->triggerName;
    }

    /** Enter or exit. */
    public function getKind(): DomainEventKind
    {
        return $this->kind;
    }

    /** True for an enter event. */
    public function isEnter(): bool
    {
        return $this->kind === DomainEventKind::ENTER;
    }

    /** True for an exit event. */
    public function isExit(): bool
    {
        return $this->kind === DomainEventKind::EXIT;
    }

    /** Class of the entity. */
    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    /** Entity id, in text form. */
    public function getEntityId(): string
    {
        return $this->entityId;
    }

    /** Instant of the write that produced it (UTC). */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** Deterministic idempotency key (see the class description). */
    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    /**
     * Minimal payload: the trigger and the entity id. Never the entity (D4).
     *
     * @return array{trigger: string, entity_id: string}
     */
    public function getPayload(): array
    {
        return ['trigger' => $this->triggerName, 'entity_id' => $this->entityId];
    }

    /**
     * JSON-friendly array form.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'trigger' => $this->triggerName,
            'kind' => $this->kind->value,
            'entity_class' => $this->entityClass,
            'entity_id' => $this->entityId,
            'occurred_at' => DomainEventSupport::toText($this->occurredAt),
            'idempotency_key' => $this->idempotencyKey,
            'payload' => $this->getPayload(),
        ];
    }
}
