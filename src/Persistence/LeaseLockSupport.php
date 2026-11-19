<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Persistence;

use Antevemus\ASpecification\Util\UlidGenerator;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * LeaseLockSupport - Shared Rules of the Lease Locks and of the Dispatchers That Take Them
 *
 * Forward 065, D9, D10: the arguments (a name and an owner of 1 to 120 characters, a lease of at least one
 * second), checked alike by both locks and by the three dispatchers; the end of a lease; the instants as
 * the fixed-width UTC text of the outboxes (`YYYY-MM-DD HH:MM:SS.ffffff`), compared lexicographically in
 * memory as in SQL; and the lock of one run of a dispatcher (take, renew, release), so that OutboxRelay,
 * DomainEventDispatcher and EffectDispatcher follow one algorithm.
 *
 * @internal
 * @version    2.16.0
 * @package    Antevemus\ASpecification
 * @subpackage Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class LeaseLockSupport
{
    /** Default lease of the dispatchers, in seconds. */
    public const DEFAULT_LEASE_SECONDS = 60;

    /** Text form of the instants of a lease (UTC, fixed width, lexicographically ordered). */
    public const TEXT_FORMAT = 'Y-m-d H:i:s.u';

    private function __construct()
    {
    }

    /**
     * @throws InvalidArgumentException When the name of a lock is empty or longer than 120 characters
     */
    public static function checkName(string $name): void
    {
        if ($name === '' || mb_strlen($name) > ILeaseLock::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf('The name of a lease lock must have 1 to %d characters, %d given.', ILeaseLock::MAX_NAME_LENGTH, mb_strlen($name)));
        }
    }

    /**
     * @throws InvalidArgumentException When the owner of a lock is empty or longer than 120 characters
     */
    public static function checkOwner(string $owner): void
    {
        if ($owner === '' || mb_strlen($owner) > ILeaseLock::MAX_OWNER_LENGTH) {
            throw new InvalidArgumentException(sprintf('The owner of a lease lock must have 1 to %d characters, %d given.', ILeaseLock::MAX_OWNER_LENGTH, mb_strlen($owner)));
        }
    }

    /**
     * @throws InvalidArgumentException When a lease is below 1 second
     */
    public static function checkLease(int $leaseSeconds): void
    {
        if ($leaseSeconds < 1) {
            throw new InvalidArgumentException(sprintf('The lease of a lock must be at least 1 second, %d given.', $leaseSeconds));
        }
    }

    /**
     * Checks the arguments of ILeaseLock::tryAcquire().
     *
     * @throws InvalidArgumentException
     */
    public static function checkAcquire(string $name, string $owner, int $leaseSeconds): void
    {
        self::checkName($name);
        self::checkOwner($owner);
        self::checkLease($leaseSeconds);
    }

    /** The same instant in UTC. */
    public static function utc(DateTimeImmutable $instant): DateTimeImmutable
    {
        return $instant->setTimezone(new DateTimeZone('UTC'));
    }

    /** Text form of an instant (UTC, fixed width). */
    public static function toText(DateTimeImmutable $instant): string
    {
        return self::utc($instant)->format(self::TEXT_FORMAT);
    }

    /** The end of a lease taken at $now (UTC). */
    public static function leaseEnd(DateTimeImmutable $now, int $leaseSeconds): DateTimeImmutable
    {
        return self::utc($now)->add(new DateInterval('PT' . $leaseSeconds . 'S'));
    }

    /**
     * Checks the lock arguments of a dispatcher and returns its owner: the one given, or a new ULID when it
     * has a lock (null without a lock and an owner).
     *
     * @throws InvalidArgumentException When the lease is below 1, or a given name or owner is invalid
     */
    public static function ownerOf(?ILeaseLock $lock, ?string $owner, ?string $name, int $leaseSeconds): ?string
    {
        self::checkLease($leaseSeconds);
        if ($name !== null) {
            self::checkName($name);
        }
        if ($owner !== null) {
            self::checkOwner($owner);
        }

        return $owner ?? ($lock === null ? null : UlidGenerator::ulid());
    }

    /**
     * Takes or renews the lock of a run of a dispatcher; true without a lock.
     *
     * @param \Closure(): DateTimeImmutable $clock The clock of the dispatcher
     */
    public static function hold(?ILeaseLock $lock, string $name, ?string $owner, \Closure $clock, int $leaseSeconds): bool
    {
        return $lock === null || $lock->tryAcquire($name, (string) $owner, $clock(), $leaseSeconds);
    }
}
