<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * ITenantScopedInstanceStore - Instance Reads Confined to One Tenant (2.7.0)
 *
 * Opt-in capability of an instance store whose instances belong to tenants (MachineInstance::$tenant):
 * a load that never crosses tenants and a search of due deadlines of one tenant. One machine
 * definition serves every tenant; the tenant is data of the instance, never part of the machine key
 * nor of the instance id.
 *
 * A load for another tenant answers null, exactly as an unknown id (it never tells that the id
 * exists). findDueForTenant() is findDue() filtered by the tenant, in the same order: the search is
 * DueDeadline::specification() with the tenant, evaluated in memory or compiled to SQL, so both
 * stores answer the same by construction.
 *
 * InMemoryMachineInstanceStore implements it; PdoMachineInstanceStore implements it and needs the
 * `tenant` columns mapped (PdoMachineSchema::COLUMNS_SINCE_2_7), refusing otherwise with a message
 * that names them.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ITenantScopedInstanceStore
{
    /**
     * Loads an instance of the tenant; null when the id is unknown or belongs to another tenant (or
     * to none).
     *
     * @param string $instanceId Instance id
     * @param string $tenant Tenant
     * @return MachineInstance|null
     */
    public function loadForTenant(string $instanceId, string $tenant): ?MachineInstance;

    /**
     * Regions of the tenant's instances whose deadline is due at $now, ordered as findDue() (due
     * instant, instance id, region); at most $limit of them.
     *
     * @param DateTimeImmutable $now Reference instant
     * @param string $tenant Tenant
     * @param string|null $machineKey Only instances of this machine, when given
     * @param int $limit Maximum number of rows (>= 1)
     * @return list<DueDeadline>
     * @throws InvalidArgumentException When the limit is below 1
     */
    public function findDueForTenant(DateTimeImmutable $now, string $tenant, ?string $machineKey = null, int $limit = 100): array;
}
