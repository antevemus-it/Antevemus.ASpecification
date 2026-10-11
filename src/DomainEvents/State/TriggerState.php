<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents\State;

use Antevemus\ASpecification\DomainEvents\Outbox\DomainEventSupport;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * TriggerState - Last Known Satisfaction of One Trigger by One Entity
 *
 * One row of an ITriggerStateStore (forward 030, D2): the trigger, the entity class and id, whether
 * the entity satisfied the trigger at the last evaluation that was recorded (`satisfied`), the instant
 * of that evaluation (`evaluatedAt`, UTC) and a version incremented on every change, which the
 * compare-and-set writes of the store compare. An entity without a row has never been recorded and
 * counts as "not satisfied" for the sweeper (D4).
 *
 * @version    1.12.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents\State
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class TriggerState
{
    public readonly DateTimeImmutable $evaluatedAt;

    /**
     * @param string $triggerName Trigger name
     * @param string $entityClass Class of the entity
     * @param string $entityId Entity id, in text form
     * @param bool $satisfied Satisfaction at the last recorded evaluation
     * @param DateTimeImmutable $evaluatedAt Instant of that evaluation (stored in UTC)
     * @param int $version Version of the row (1 on insert, + 1 on every change)
     * @throws InvalidArgumentException When a name, the class, the id or the version is invalid
     */
    public function __construct(
        public readonly string $triggerName,
        public readonly string $entityClass,
        public readonly string $entityId,
        public readonly bool $satisfied,
        DateTimeImmutable $evaluatedAt,
        public readonly int $version
    ) {
        self::assertKey($triggerName, $entityClass, $entityId);
        if ($version < 1) {
            throw new InvalidArgumentException(sprintf('The version of a trigger state must be at least 1, %d given.', $version));
        }
        $this->evaluatedAt = DomainEventSupport::utc($evaluatedAt);
    }

    /**
     * Validates the key of a row against the reference DDL columns.
     *
     * @throws InvalidArgumentException
     */
    public static function assertKey(string $triggerName, string $entityClass, string $entityId): void
    {
        DomainEventSupport::assertName($triggerName, 'trigger name');
        if ($entityClass === '' || strlen($entityClass) > DomainEventSupport::MAX_ENTITY_CLASS_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The entity class must be a non-empty string of at most %d characters.',
                DomainEventSupport::MAX_ENTITY_CLASS_LENGTH
            ));
        }
        if ($entityId === '' || mb_strlen($entityId) > DomainEventSupport::MAX_ENTITY_ID_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The entity id of a trigger state must be a non-empty string of at most %d characters.',
                DomainEventSupport::MAX_ENTITY_ID_LENGTH
            ));
        }
    }

    /**
     * Array form (instants as fixed-width UTC text).
     *
     * @return array{trigger: string, entity_class: string, entity_id: string, satisfied: bool, evaluated_at: string, version: int}
     */
    public function toArray(): array
    {
        return [
            'trigger' => $this->triggerName,
            'entity_class' => $this->entityClass,
            'entity_id' => $this->entityId,
            'satisfied' => $this->satisfied,
            'evaluated_at' => DomainEventSupport::toText($this->evaluatedAt),
            'version' => $this->version,
        ];
    }
}
