<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IRuleCatalogStore;
use Antevemus\ASpecification\Engine\Exceptions\RuleCatalogStoreException;
use Antevemus\ASpecification\Persistence\ITransactionManager;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\IInMemoryTransactional;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryTransactionManager;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * InMemoryRuleCatalogStore - The Rule Catalog and Its History in Plain PHP Arrays
 *
 * The in-memory IRuleCatalogStore (2.13.0, forward 060, D1): tests, prototypes and applications that keep their
 * catalog in configuration. Same contract as PdoRuleCatalogStore: the same battery runs against both with the same
 * results (D15), down to the text and the hash of every photo (RN-06).
 *
 * - Transactions: an InMemoryTransactionManager whose rollback restores the rows and the history together (pass
 *   your own manager to share it with other in-memory stores)
 * - put() seeds a row as a migration script would, in the values a database holds (`active` as 'Y'/'N' or a
 *   boolean, `parameters` as JSON text or an array); it records no history, so a seeded rule has none until it is
 *   adopted or changed through the editor. A column left out takes the default of the reference DDL (`version` 1,
 *   `active` 'Y', `priority` 0, `violation_action` block, NULL otherwise). remove() deletes a row as a DELETE by hand would (the history stays). Both exist to reproduce what
 *   happens outside the editor; the editor never calls them
 * - Without `spec_json` (`$specJson: false`) it mirrors a PDO store that does not map the column
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryRuleCatalogStore implements IRuleCatalogStore, IInMemoryTransactional
{
    /** @var array<string, array<string, mixed>> Canonical rows by code */
    private array $rows = [];

    /** @var list<RuleRevision> History in append order */
    private array $revisions = [];

    private readonly InMemoryTransactionManager $transactions;

    /**
     * @param InMemoryTransactionManager|null $transactions Manager to take part in (null = a manager of its own)
     * @param bool $specJson Whether the store keeps the serialized body of a rule (`spec_json`)
     */
    public function __construct(?InMemoryTransactionManager $transactions = null, private readonly bool $specJson = true)
    {
        $this->transactions = ($transactions ?? new InMemoryTransactionManager())->addParticipant($this);
    }

    /**
     * Seeds (or replaces) the row of a rule, as a script would: no history row is written.
     *
     * @param array<string, mixed> $row Values by canonical column, as a database holds them
     * @return $this
     * @throws InvalidArgumentException When a column is unknown, or `spec_json` is given to a store without it
     * @throws RuleCatalogStoreException When a value cannot be read
     */
    public function put(array $row): self
    {
        foreach (array_keys($row) as $column) {
            if (!in_array($column, RuleSnapshot::COLUMNS, true)) {
                throw new InvalidArgumentException(sprintf('Unknown rule column "%s". Expected one of: %s.', (string) $column, implode(', ', RuleSnapshot::COLUMNS)));
            }
        }
        if (!$this->specJson && isset($row['spec_json'])) {
            throw new InvalidArgumentException('This in-memory store keeps no spec_json.');
        }
        $canonical = RuleSnapshot::fromStored($row + ['version' => 1, 'active' => 'Y', 'priority' => 0]);
        $this->rows[$canonical['code']] = $canonical;

        return $this;
    }

    /**
     * Deletes the row of a rule, as a DELETE by hand would: its history stays.
     *
     * @return $this
     */
    public function remove(string $code): self
    {
        unset($this->rows[$code]);

        return $this;
    }

    /** {@inheritdoc} */
    public function assertReady(): void
    {
    }

    /** {@inheritdoc} */
    public function supportsSpecJson(): bool
    {
        return $this->specJson;
    }

    /** {@inheritdoc} */
    public function findRow(string $code): ?array
    {
        return $this->rows[$code] ?? null;
    }

    /** {@inheritdoc} */
    public function findRows(): array
    {
        $rows = $this->rows;
        ksort($rows, SORT_STRING);

        return array_values($rows);
    }

    /** {@inheritdoc} */
    public function insertRow(array $row): bool
    {
        $canonical = RuleSnapshot::fromStored($row);
        if (isset($this->rows[$canonical['code']])) {
            return false;
        }
        $this->rows[$canonical['code']] = $canonical;

        return true;
    }

    /** {@inheritdoc} */
    public function compareAndSetRow(string $code, int $expectedVersion, array $changes): bool
    {
        $current = $this->rows[$code] ?? null;
        if ($current === null || $current['version'] !== $expectedVersion) {
            return false;
        }
        unset($changes['version'], $changes['code']);
        $this->rows[$code] = RuleSnapshot::fromStored(['version' => $expectedVersion + 1] + $changes + $current);

        return true;
    }

    /** {@inheritdoc} */
    public function appendRevision(RuleRevision $revision): bool
    {
        if ($this->findRevision($revision->code, $revision->version) !== null) {
            return false;
        }
        $this->revisions[] = $revision;

        return true;
    }

    /** {@inheritdoc} */
    public function findRevision(string $code, int $version): ?RuleRevision
    {
        foreach ($this->revisions as $revision) {
            if ($revision->code === $code && $revision->version === $version) {
                return $revision;
            }
        }

        return null;
    }

    /** {@inheritdoc} */
    public function lastRevisions(?string $code = null): array
    {
        $last = [];
        foreach ($this->revisions as $revision) {
            if ($code !== null && $revision->code !== $code) {
                continue;
            }
            if (!isset($last[$revision->code]) || $revision->version > $last[$revision->code]->version) {
                $last[$revision->code] = $revision;
            }
        }
        ksort($last, SORT_STRING);

        return $last;
    }

    /** {@inheritdoc} */
    public function history(?string $code = null, int $limit = 50): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('The history limit must be at least 1, %d given.', $limit));
        }
        $rows = array_filter($this->revisions, static fn(RuleRevision $r): bool => $code === null || $r->code === $code);
        usort($rows, static fn(RuleRevision $a, RuleRevision $b): int => strcmp(self::instant($b->changedAt), self::instant($a->changedAt))
            ?: strcmp((string) $b->changeId, (string) $a->changeId));

        return array_slice($rows, 0, $limit);
    }

    /** {@inheritdoc} */
    public function getTransactionManager(): ITransactionManager
    {
        return $this->transactions;
    }

    /** {@inheritdoc} */
    public function exportState(): array
    {
        return [$this->rows, $this->revisions];
    }

    /** {@inheritdoc} */
    public function importState(array $state): void
    {
        [$this->rows, $this->revisions] = $state;
    }

    /** The instant as PdoRuleCatalogStore writes it, so that both order the history alike. */
    private static function instant(DateTimeInterface $at): string
    {
        return PdoRuleCatalogStore::instantText($at);
    }
}
