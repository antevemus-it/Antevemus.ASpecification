<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Contracts\Engine\IRuleCatalogStore;
use Antevemus\ASpecification\Engine\RuleChangeKind;
use Antevemus\ASpecification\Engine\RuleRevision;
use Antevemus\ASpecification\Persistence\ITransactionManager;
use RuntimeException;

/**
 * A rule catalog store that delegates everything and fails on the append of a history row that is not a baseline,
 * after the compare-and-set of the row and the baseline went through (forward 060, RN-02): the transaction of the
 * store must roll every write back, in memory and over PDO.
 *
 * @version 2.13.0
 */
final class FailingRuleCatalogStore implements IRuleCatalogStore
{
    public bool $armed = true;

    private bool $vanish = false;

    private bool $changed = false;

    public function __construct(private readonly IRuleCatalogStore $inner)
    {
    }

    /** From the next compare-and-set on, the row is gone (a store that loses what it wrote). */
    public function vanishAfterNextChange(): void
    {
        $this->vanish = true;
        $this->changed = false;
    }

    public function assertReady(): void
    {
        $this->inner->assertReady();
    }

    public function supportsSpecJson(): bool
    {
        return $this->inner->supportsSpecJson();
    }

    public function findRow(string $code): ?array
    {
        return $this->vanish && $this->changed ? null : $this->inner->findRow($code);
    }

    public function findRows(): array
    {
        return $this->inner->findRows();
    }

    public function insertRow(array $row): bool
    {
        return $this->inner->insertRow($row);
    }

    public function compareAndSetRow(string $code, int $expectedVersion, array $changes): bool
    {
        $this->changed = $this->inner->compareAndSetRow($code, $expectedVersion, $changes);

        return $this->changed;
    }

    public function appendRevision(RuleRevision $revision): bool
    {
        if ($this->armed && $revision->kind !== RuleChangeKind::Baseline) {
            throw new RuntimeException('injected failure after the row and the baseline were written');
        }

        return $this->inner->appendRevision($revision);
    }

    public function findRevision(string $code, int $version): ?RuleRevision
    {
        return $this->inner->findRevision($code, $version);
    }

    public function lastRevisions(?string $code = null): array
    {
        return $this->inner->lastRevisions($code);
    }

    public function history(?string $code = null, int $limit = 50): array
    {
        return $this->inner->history($code, $limit);
    }

    public function getTransactionManager(): ITransactionManager
    {
        return $this->inner->getTransactionManager();
    }
}
