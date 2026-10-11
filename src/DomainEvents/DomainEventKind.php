<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\DomainEvents;

/**
 * DomainEventKind - Direction of a Specification Crossing
 *
 * `enter` when an entity starts to satisfy a trigger's specification, `exit` when it stops
 * (forward 029, D2, D4).
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage DomainEvents
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum DomainEventKind: string
{
    /** Not satisfied before (or created), satisfied after. */
    case ENTER = 'enter';

    /** Satisfied before, not satisfied after (or removed). */
    case EXIT = 'exit';
}
