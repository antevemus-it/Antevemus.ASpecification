<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Engine;

use Antevemus\ASpecification\Engine\Exceptions\RuleCatalogStoreException;
use Antevemus\ASpecification\Engine\RuleRevision;
use Antevemus\ASpecification\Persistence\ITransactionManager;

/**
 * IRuleCatalogStore - Where the Rows and the History of the Rule Catalog Are Written
 *
 * The persistence port of RuleCatalogEditor (2.13.0, forward 060, D1). A store knows rows, not rules: the reason
 * policy, the change guard, the baseline and the decision of what changed belong to the editor, which calls the
 * write methods inside one transaction of getTransactionManager(), so that a baseline, the change of the rule and
 * its history row commit or roll back together (RN-02).
 *
 * Rows travel in the canonical form of RuleSnapshot (RuleSnapshot::fromStored(): every column of
 * RuleSnapshot::COLUMNS, `active` a boolean, `parameters` an array or null, `decimal_value` canonical text); the
 * store converts them to and from its columns. The history is append-only: there is no update nor delete of it.
 *
 * Implementations: InMemoryRuleCatalogStore and PdoRuleCatalogStore, which answer the same battery with the same
 * results (D15). Every method may throw RuleCatalogStoreException on a storage failure.
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IRuleCatalogStore
{
    /**
     * Checks that the store has what the audited path needs (the `version` column of the rule table and the
     * history table), so that the editor refuses to work with a clear message instead of failing halfway (D13).
     *
     * @throws RuleCatalogStoreException When something is missing (the message points to the migration)
     */
    public function assertReady(): void;

    /** True when the store keeps the serialized body of a rule (`spec_json`). */
    public function supportsSpecJson(): bool;

    /**
     * The row of a rule, active or not, in canonical form; null when there is none.
     *
     * @return array<string, mixed>|null
     * @throws RuleCatalogStoreException
     */
    public function findRow(string $code): ?array;

    /**
     * Every row, active or not, in canonical form, by code (strcmp).
     *
     * @return list<array<string, mixed>>
     * @throws RuleCatalogStoreException
     */
    public function findRows(): array;

    /**
     * Inserts a rule row (canonical form, `version` included). Returns false when a row with the code already
     * exists (also when another writer inserted it concurrently).
     *
     * @param array<string, mixed> $row
     * @throws RuleCatalogStoreException
     */
    public function insertRow(array $row): bool;

    /**
     * Compare-and-set of a rule row: writes $changes (canonical columns, without `version`) and increments the
     * version, only when the stored version is $expectedVersion. Returns true when exactly one row changed, false
     * otherwise (no row, or another version: someone else changed the rule since it was read).
     *
     * @param array<string, mixed> $changes
     * @throws RuleCatalogStoreException
     */
    public function compareAndSetRow(string $code, int $expectedVersion, array $changes): bool;

    /**
     * Appends one history row. Returns false when the history already has a row for that rule and version (also
     * when another writer appended it concurrently: two baselines of the same version); the row is then not
     * written.
     *
     * @throws RuleCatalogStoreException
     */
    public function appendRevision(RuleRevision $revision): bool;

    /**
     * The history row of one version of a rule, or null.
     *
     * @throws RuleCatalogStoreException
     */
    public function findRevision(string $code, int $version): ?RuleRevision;

    /**
     * The history row of the last version of each rule (of one rule when $code is given), by code.
     *
     * @return array<string, RuleRevision>
     * @throws RuleCatalogStoreException
     */
    public function lastRevisions(?string $code = null): array;

    /**
     * History rows, most recent first (instant, then key, descending), of one rule or of every rule.
     *
     * @param string|null $code Rule code (null = every rule)
     * @param int $limit Maximum number of rows (>= 1)
     * @return list<RuleRevision>
     * @throws RuleCatalogStoreException
     */
    public function history(?string $code = null, int $limit = 50): array;

    /** The unit of work the editor runs its writes in (shared with the application when it owns one). */
    public function getTransactionManager(): ITransactionManager;
}
