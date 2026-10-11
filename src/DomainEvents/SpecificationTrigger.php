<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\DomainEvents\Outbox\DomainEventSupport;
use InvalidArgumentException;

/**
 * SpecificationTrigger - A Specification Bound to Domain Events
 *
 * When an entity starts to satisfy the specification the trigger emits its enter event; when it
 * stops, its exit event (forward 029, D1). At least one of the two events is required; a trigger
 * with only one of them is silent on the other crossing.
 *
 * <code>
 * $late = new SpecificationTrigger(
 *     'policy.delinquent',
 *     Spec::property('daysOverdue', Spec::greaterThan(30)),
 *     onEnter: 'delinquency.started',
 *     onExit: 'delinquency.ended'
 * );
 * </code>
 *
 * Names (trigger and events) are non-empty, without surrounding whitespace and at most 120
 * characters (the columns of `resources/sql/domain_events.ansi.sql`).
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class SpecificationTrigger
{
    public readonly string $name;
    public readonly ?string $onEnter;
    public readonly ?string $onExit;

    /**
     * @param string $name Trigger name, unique in its TriggerSet
     * @param ISpecification $specification Specification watched on every write
     * @param string|null $onEnter Event emitted when the entity starts to satisfy it
     * @param string|null $onExit Event emitted when the entity stops satisfying it
     * @throws InvalidArgumentException When a name is invalid or both events are null
     */
    public function __construct(
        string $name,
        public readonly ISpecification $specification,
        ?string $onEnter = null,
        ?string $onExit = null
    ) {
        $this->name = DomainEventSupport::assertName($name, 'trigger name');
        if ($onEnter === null && $onExit === null) {
            throw new InvalidArgumentException(sprintf('Trigger "%s" must declare an enter event, an exit event or both.', $name));
        }
        $this->onEnter = $onEnter === null ? null : DomainEventSupport::assertName($onEnter, 'enter event name');
        $this->onExit = $onExit === null ? null : DomainEventSupport::assertName($onExit, 'exit event name');
    }

    /** Trigger name. */
    public function getName(): string
    {
        return $this->name;
    }

    /** Watched specification. */
    public function getSpecification(): ISpecification
    {
        return $this->specification;
    }

    /** Event name emitted for the given crossing, or null when the trigger is silent on it. */
    public function eventFor(DomainEventKind $kind): ?string
    {
        return $kind === DomainEventKind::ENTER ? $this->onEnter : $this->onExit;
    }
}
