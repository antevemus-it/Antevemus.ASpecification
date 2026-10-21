<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Runner;

use Antevemus\ASpecification\StateMachine\Persistence\MachineInstance;

/**
 * InstanceMigration - Outcome of Migrating One Instance in a Batch (2.7.0)
 *
 * MachineRunner::migrateAll() reports one entry per instance listed, as deliverDue() does per
 * deadline: the migrated instance, or why it was skipped (a concurrency conflict, a snapshot the
 * target refuses, an instance gone or already moved). A skipped instance stays on its version; the
 * next run retries it.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Runner
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InstanceMigration
{
    /**
     * @param string $instanceId Instance listed
     * @param MachineInstance|null $instance The migrated instance, when it was migrated
     * @param string|null $skipped Why it was not migrated, when it was not
     */
    public function __construct(
        public readonly string $instanceId,
        public readonly ?MachineInstance $instance,
        public readonly ?string $skipped = null
    ) {
    }

    /** True when the instance was migrated. */
    public function isMigrated(): bool
    {
        return $this->instance !== null;
    }

    /** True when the instance was skipped. */
    public function isSkipped(): bool
    {
        return $this->skipped !== null;
    }
}
