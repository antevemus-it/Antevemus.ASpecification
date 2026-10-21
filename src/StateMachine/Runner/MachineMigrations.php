<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Runner;

/**
 * MachineMigrations - Ready-Made Migrations Between Definition Versions (2.7.0)
 *
 * <code>
 * $runner->registerMigration('quote', 1, 2, MachineMigrations::byStateName());
 * $runner->migrateAll('quote', 1, 2, $now, actor: 'admin');
 * </code>
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Runner
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MachineMigrations
{
    private function __construct()
    {
    }

    /**
     * The identity migration: every region stays in its state (by name), the snapshot moves to the
     * target version; refused by the runner when a state the instance is in does not exist in the
     * target. See ByStateNameMigration.
     */
    public static function byStateName(): ByStateNameMigration
    {
        return new ByStateNameMigration();
    }
}
