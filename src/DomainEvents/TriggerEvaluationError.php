<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

use Throwable;

/**
 * TriggerEvaluationError - A Trigger That Could Not Be Evaluated on One Write
 *
 * Produced by the TriggerEvaluator when a trigger's specification throws on the before or the
 * after state, or when the entity id needed to stamp an event cannot be read (forward 029, D3);
 * since 1.12.0 also by TriggerSweeper (side `sweep`) when a time-based trigger cannot be swept: its
 * specification factory or crossing instant calculator threw, or an entity cannot be keyed.
 * The trigger emits nothing for that write; the error is returned with the events, never
 * swallowed. getMessage() is safe to show or count: it names the trigger, the side and the entity,
 * never the message of the exception, which stays in $exception for the log (the 1.8.0 rule).
 *
 * @version    1.12.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class TriggerEvaluationError
{
    /** The specification threw on the state before the write. */
    public const SIDE_BEFORE = 'before';

    /** The specification threw on the state after the write. */
    public const SIDE_AFTER = 'after';

    /** The specification was evaluated, but the entity id needed to stamp the event could not be read. */
    public const SIDE_IDENTITY = 'identity';

    /** TriggerSweeper could not sweep the trigger, or one entity of it (since 1.12.0). */
    public const SIDE_SWEEP = 'sweep';

    /**
     * @param string $triggerName Trigger that could not be evaluated
     * @param string $side SIDE_BEFORE, SIDE_AFTER, SIDE_IDENTITY or SIDE_SWEEP
     * @param string $entityClass Class of the entity
     * @param string|null $entityId Entity id in text form, when it could be read
     * @param Throwable $exception The exception (for the log only)
     */
    public function __construct(
        public readonly string $triggerName,
        public readonly string $side,
        public readonly string $entityClass,
        public readonly ?string $entityId,
        public readonly Throwable $exception
    ) {
    }

    /** Safe message: trigger, side and entity; never the exception message. */
    public function getMessage(): string
    {
        $entity = $this->entityClass . ($this->entityId === null ? '' : '#' . $this->entityId);

        return match ($this->side) {
            self::SIDE_IDENTITY => sprintf('Trigger "%s" crossed on %s, but the entity id could not be read to stamp the event.', $this->triggerName, $entity),
            self::SIDE_SWEEP => sprintf('Trigger "%s" could not be swept on %s.', $this->triggerName, $entity),
            default => sprintf('Trigger "%s" could not be evaluated on the %s state of %s.', $this->triggerName, $this->side, $entity),
        };
    }

    /** The exception, for the log. */
    public function getException(): Throwable
    {
        return $this->exception;
    }

    /**
     * Array form without the exception message (class of the exception only).
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'trigger' => $this->triggerName,
            'side' => $this->side,
            'entity_class' => $this->entityClass,
            'entity_id' => $this->entityId,
            'exception_class' => get_class($this->exception),
            'message' => $this->getMessage(),
        ];
    }
}
