<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

/**
 * IMachineRejectionLog - Optional Port of Rejected and Unhandled Events
 *
 * Off by default (forward 023, RN-07): the history only holds accepted steps. When configured,
 * the runner records each rejected or unhandled event here with its reason, inside the same
 * transaction (which then commits nothing else).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IMachineRejectionLog
{
    /**
     * Records a rejected or unhandled event.
     *
     * @param RejectionEntry $entry Row to record
     */
    public function record(RejectionEntry $entry): void;

    /**
     * Rows of an instance, in the order they were recorded.
     *
     * @param string $instanceId Instance id
     * @return list<RejectionEntry>
     */
    public function findByInstance(string $instanceId): array;
}
