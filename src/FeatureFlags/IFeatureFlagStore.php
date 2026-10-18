<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags;

use Antevemus\ASpecification\FeatureFlags\Exceptions\FeatureFlagStoreException;
use Antevemus\ASpecification\Persistence\ITransactionManager;
use DateTimeImmutable;

/**
 * IFeatureFlagStore - Where the Switches, Subject Lists and History of the Flags Live
 *
 * The persistence port of FeatureFlags (forward 034, D3). A store knows rows, not rules: whether a
 * flag may be turned on, the reason policy and the evaluation belong to the FeatureFlags service,
 * which calls the write methods inside one transaction of getTransactionManager(), so that a change
 * and its history row commit or roll back together.
 *
 * Implementations: InMemoryFeatureFlagStore and PdoFeatureFlagStore, which answer the same battery
 * with the same results (RN-02). Every method may throw FeatureFlagStoreException on a storage
 * failure; FeatureFlags turns a failure of loadAll() into "off" (D9).
 *
 * 2.3.0 (forward 040, D3 and D4): the validity window and the rollout percentage are written through
 * the store, by compare-and-set of the optimistic version of the row (supportsValidity(),
 * compareAndSetValidity(), supportsRollout(), compareAndSetRollout()). An implementation outside the
 * library must add these four methods (a store that keeps neither answers false to the two
 * `supports*()` and the service refuses the change before calling the others).
 *
 * @version    2.3.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IFeatureFlagStore
{
    /**
     * Every stored flag (not deleted), with its subject list, by code. FeatureFlags calls it once per
     * instance and keeps the result until refresh() (D10).
     *
     * @return array<string, FeatureFlagState>
     * @throws FeatureFlagStoreException
     */
    public function loadAll(): array;

    /**
     * One stored flag with its subject list, or null when there is no row.
     *
     * @throws FeatureFlagStoreException
     */
    public function find(string $code): ?FeatureFlagState;

    /**
     * Creates the row of a declared flag, OFF, when there is none (D5: a declared flag without a row
     * is born off on its first change). Returns true when this call created it, false when it already
     * existed (also when another writer created it concurrently).
     *
     * @param FeatureFlagDefinition $definition Texts of the new row
     * @param DateTimeImmutable $at Instant of creation (for the creation stamp, when the table has one)
     * @param string|int|null $actor Who caused it
     * @throws FeatureFlagStoreException
     */
    public function insertIfAbsent(FeatureFlagDefinition $definition, DateTimeImmutable $at, string|int|null $actor): bool;

    /**
     * Compare-and-set of the switch: changes it to $enabled only when the stored value is $expected,
     * stamping `enabled_at/by` (turned on) or `disabled_at/by` (turned off) and incrementing the
     * optimistic version (when the store keeps one), so that a change of the window or of the rollout
     * read before the switch is stale. Returns true when exactly one row changed, false otherwise (no
     * row, or another state).
     *
     * @throws FeatureFlagStoreException
     */
    public function compareAndSetEnabled(string $code, bool $expected, bool $enabled, DateTimeImmutable $at, string|int|null $actor): bool;

    /**
     * True when the store keeps the validity window and the optimistic version of the flag rows, so that
     * compareAndSetValidity() can be called.
     */
    public function supportsValidity(): bool;

    /**
     * Compare-and-set of the validity window: writes $from and $until (null = open bound; both null =
     * no window) only when the stored version is $expectedVersion, increments the version and stamps
     * the update (when the table has update stamps). Returns true when exactly one row changed, false
     * otherwise (no row, or another version: someone else changed the flag since it was read).
     *
     * @param string $code Flag code
     * @param int $expectedVersion Version the caller read
     * @param DateTimeImmutable|null $from First instant the flag may be on (null = open)
     * @param DateTimeImmutable|null $until Last instant the flag may be on (null = open)
     * @param DateTimeImmutable $at Instant of the change
     * @param string|int|null $actor Who changed it
     * @throws FeatureFlagStoreException When the store keeps no validity window or no version, or on a storage failure
     */
    public function compareAndSetValidity(string $code, int $expectedVersion, ?DateTimeImmutable $from, ?DateTimeImmutable $until, DateTimeImmutable $at, string|int|null $actor): bool;

    /**
     * True when the store keeps the rollout percentage and the optimistic version of the flag rows, so
     * that compareAndSetRollout() can be called.
     */
    public function supportsRollout(): bool;

    /**
     * Compare-and-set of the rollout percentage: writes $percent (0 to 100; null = no rollout) only when
     * the stored version is $expectedVersion, increments the version and stamps the update (when the
     * table has update stamps). Returns true when exactly one row changed, false otherwise.
     *
     * @param string $code Flag code
     * @param int $expectedVersion Version the caller read
     * @param int|null $percent Rollout percentage, 0 to 100 (null = no rollout)
     * @param DateTimeImmutable $at Instant of the change
     * @param string|int|null $actor Who changed it
     * @throws FeatureFlagStoreException When the store keeps no rollout percentage or no version, or on a storage failure
     */
    public function compareAndSetRollout(string $code, int $expectedVersion, ?int $percent, DateTimeImmutable $at, string|int|null $actor): bool;

    /** True when the store keeps subject lists (a subject table is configured). */
    public function supportsSubjects(): bool;

    /**
     * Adds a subject key to the list of a flag whose row exists. Returns false when it is already there.
     *
     * @throws FeatureFlagStoreException When the store keeps no subject lists, or on a storage failure
     */
    public function addSubject(string $code, string $subjectKey): bool;

    /**
     * Removes a subject key from the list of a flag. Returns false when it was not there.
     *
     * @throws FeatureFlagStoreException When the store keeps no subject lists, or on a storage failure
     */
    public function removeSubject(string $code, string $subjectKey): bool;

    /**
     * Appends one history row (append-only: there is no update nor delete of the history).
     *
     * @throws FeatureFlagStoreException
     */
    public function appendChange(FeatureFlagChange $change): void;

    /**
     * History rows, most recent first, of one flag or of every flag.
     *
     * @param string|null $code Flag code (null = every flag)
     * @param int $limit Maximum number of rows (>= 1)
     * @return list<FeatureFlagChange>
     * @throws FeatureFlagStoreException
     */
    public function history(?string $code = null, int $limit = 50): array;

    /** The unit of work the service runs its writes in (shared with the application when it owns one). */
    public function getTransactionManager(): ITransactionManager;
}
