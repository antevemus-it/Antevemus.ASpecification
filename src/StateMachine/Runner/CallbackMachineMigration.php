<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Runner;

use Antevemus\ASpecification\StateMachine\MachineSnapshot;
use Closure;

/**
 * CallbackMachineMigration - IMachineMigration over a Closure
 *
 * Adapter used when MachineRunner::registerMigration() receives a closure
 * `fn (MachineSnapshot $old): MachineSnapshot` (forward 023, D16).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Runner
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class CallbackMachineMigration implements IMachineMigration
{
    /**
     * @param Closure(MachineSnapshot): MachineSnapshot $callback Mapping of the snapshot
     */
    public function __construct(private readonly Closure $callback)
    {
    }

    /** {@inheritdoc} */
    public function migrate(MachineSnapshot $snapshot): MachineSnapshot
    {
        return ($this->callback)($snapshot);
    }
}
