<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

/**
 * EffectPhase - Moment an Action Belongs To
 *
 * Effects are listed in SCXML order (forward 023, D11): every exit action, then every transition
 * action, then every entry action; within a phase, regions in declaration order and actions in
 * declaration order.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum EffectPhase: string
{
    /** Exit action of the source state. */
    case EXIT = 'exit';

    /** Action declared on the transition. */
    case TRANSITION = 'transition';

    /** Entry action of the target state (or of the initial state at start). */
    case ENTRY = 'entry';
}
