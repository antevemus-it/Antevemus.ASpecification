<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Persistence;

use DateTimeImmutable;

/**
 * InMemoryLeaseLock - Lease Locks in Memory
 *
 * Same contract as PdoLeaseLock (forward 065, D9), checked by the same battery: one lease per name, held
 * by one owner until its end; a renewal by the same owner keeps the instant it was acquired. It is shared
 * by the dispatchers of one process (tests, a single worker running several dispatchers); dispatchers in
 * different processes need PdoLeaseLock. It does not take part in a transaction: a lock is not undone
 * with the write of the application.
 *
 * @version    2.16.0
 * @package    Antevemus\ASpecification
 * @subpackage Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryLeaseLock implements ILeaseLock
{
    /** @var array<string, array{owner: string, locked_until: string, acquired_at: string}> name => lease (UTC text) */
    private array $leases = [];

    /** {@inheritdoc} */
    public function tryAcquire(string $name, string $owner, DateTimeImmutable $now, int $leaseSeconds): bool
    {
        LeaseLockSupport::checkAcquire($name, $owner, $leaseSeconds);
        $at = LeaseLockSupport::toText($now);
        $current = $this->leases[$name] ?? null;
        if ($current !== null && $current['owner'] !== $owner && $current['locked_until'] >= $at) {
            return false;
        }
        $this->leases[$name] = [
            'owner' => $owner,
            'locked_until' => LeaseLockSupport::toText(LeaseLockSupport::leaseEnd($now, $leaseSeconds)),
            'acquired_at' => $current !== null && $current['owner'] === $owner ? $current['acquired_at'] : $at,
        ];

        return true;
    }

    /** {@inheritdoc} */
    public function release(string $name, string $owner): void
    {
        if (($this->leases[$name]['owner'] ?? null) === $owner) {
            unset($this->leases[$name]);
        }
    }

    /**
     * Who holds the lock $name at $now (inspection and tests): null when nobody does or the lease ran out.
     */
    public function holder(string $name, DateTimeImmutable $now): ?string
    {
        $lease = $this->leases[$name] ?? null;

        return $lease !== null && $lease['locked_until'] >= LeaseLockSupport::toText($now) ? $lease['owner'] : null;
    }

    /**
     * The lease of the lock $name (inspection and tests), also when it ran out; null when released.
     *
     * @return array{owner: string, locked_until: string, acquired_at: string}|null Instants as UTC text
     */
    public function lease(string $name): ?array
    {
        return $this->leases[$name] ?? null;
    }
}
