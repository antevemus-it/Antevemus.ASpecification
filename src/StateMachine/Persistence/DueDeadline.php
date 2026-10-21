<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Spec;
use DateTimeImmutable;

/**
 * DueDeadline - A Region Whose Deadline Is Due
 *
 * Returned by IMachineInstanceStore::findDue() (forward 023, D18): the instance, the region, the
 * state it is in and the due instant (UTC). The runner turns it into the state's deadline event,
 * sent with internal origin; the pure core checks `dueAt <= occurredAt` again, so a late or
 * repeated delivery is harmless.
 *
 * The search itself is a specification (specification()): the PDO store compiles it to SQL with
 * the SQL visitor, the InMemory store evaluates it over the same projection, so both answer the
 * same by construction.
 *
 * Since 2.7.0 a due deadline carries the tenant of its instance (null when it has none, or when the
 * store does not keep tenants), and specification() filters by tenant when one is given.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DueDeadline
{
    /** Property of the projection holding the due instant (text form, UTC). */
    public const PROPERTY_DUE_AT = 'dueAt';

    /** Property of the projection holding the machine key. */
    public const PROPERTY_MACHINE_KEY = 'machineKey';

    /** Property of the projection holding the tenant (2.7.0). */
    public const PROPERTY_TENANT = 'tenant';

    public readonly DateTimeImmutable $dueAt;

    /**
     * @param string $instanceId Instance id
     * @param string $machineKey Machine key
     * @param string $region Region whose deadline is due
     * @param string $state State the region is in
     * @param DateTimeImmutable $dueAt Due instant (stored in UTC)
     * @param string|null $tenant Tenant of the instance (2.7.0), null when none
     */
    public function __construct(
        public readonly string $instanceId,
        public readonly string $machineKey,
        public readonly string $region,
        public readonly string $state,
        DateTimeImmutable $dueAt,
        public readonly ?string $tenant = null
    ) {
        $this->dueAt = PersistenceSupport::utc($dueAt);
    }

    /**
     * The search of due deadlines as a specification over the deadline projection: `dueAt` (text
     * form, UTC) is set and `<= now`, and, when given, `machineKey` equals the key and (2.7.0)
     * `tenant` equals the tenant.
     *
     * @param DateTimeImmutable $now Reference instant
     * @param string|null $machineKey Optional machine key filter
     * @param string|null $tenant Optional tenant filter (2.7.0)
     * @return ISpecification
     */
    public static function specification(DateTimeImmutable $now, ?string $machineKey = null, ?string $tenant = null): ISpecification
    {
        $due = Spec::property(
            self::PROPERTY_DUE_AT,
            Spec::isNotNull()->and(Spec::lessThanOrEqualTo(PersistenceSupport::toText($now)))
        );
        if ($machineKey !== null) {
            $due = $due->and(Spec::property(self::PROPERTY_MACHINE_KEY, Spec::equalTo($machineKey)));
        }

        return $tenant === null
            ? $due
            : $due->and(Spec::property(self::PROPERTY_TENANT, Spec::equalTo($tenant)));
    }

    /**
     * Returns the JSON-friendly array form.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $array = [
            'instance_id' => $this->instanceId,
            'machine_key' => $this->machineKey,
            'region' => $this->region,
            'state' => $this->state,
            'due_at' => PersistenceSupport::toText($this->dueAt),
        ];
        if ($this->tenant !== null) {
            $array['tenant'] = $this->tenant;
        }

        return $array;
    }
}
