<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;
use DateInterval;
use DateTimeImmutable;

/**
 * DeadlineDefinition - Persisted Deadline Declared by a State
 *
 * A state may declare a deadline: an ISO-8601 duration (`after`, e.g. `P3D`, `PT12H`) and the
 * event delivered when it expires (forward 023, D18). There is no in-memory timer: entering the
 * state records `dueAt = occurredAt + after` for the region in the snapshot, leaving it clears it
 * and reentering recomputes it (RN-06). The deadline event is internal: sent from outside it is
 * rejected (D19), and it is only accepted once `dueAt <= occurredAt` (an implicit guard, so late
 * or repeated deliveries are harmless).
 *
 * Array form: `{"after": "P3D", "event": "QUOTE_EXPIRED"}`.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DeadlineDefinition
{
    public readonly string $after;
    public readonly string $event;

    /**
     * @param string $after ISO-8601 duration (DateInterval syntax)
     * @param string $event Event type delivered when the deadline expires
     * @throws InvalidMachineDefinitionException
     */
    public function __construct(string $after, string $event)
    {
        try {
            new DateInterval($after);
        } catch (\Throwable) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                sprintf('The deadline "after" value "%s" is not a valid ISO-8601 duration.', $after)
            );
        }
        $this->after = $after;
        $this->event = DefinitionSupport::assertName($event, 'deadline event type');
    }

    /**
     * Computes the due instant from the instant the state was entered.
     *
     * @param DateTimeImmutable $enteredAt Instant of the event that entered the state
     * @return DateTimeImmutable
     */
    public function dueFrom(DateTimeImmutable $enteredAt): DateTimeImmutable
    {
        return $enteredAt->add(new DateInterval($this->after));
    }

    /**
     * Returns the canonical array form.
     *
     * @return array{after: string, event: string}
     */
    public function toArray(): array
    {
        return ['after' => $this->after, 'event' => $this->event];
    }

    /**
     * Hydrates a deadline from its array form.
     *
     * @param array<string, mixed> $data
     * @return self
     * @throws InvalidMachineDefinitionException
     */
    public static function fromArray(array $data): self
    {
        return new self(
            DefinitionSupport::requireString($data, 'after', 'a deadline'),
            DefinitionSupport::requireString($data, 'event', 'a deadline')
        );
    }
}
