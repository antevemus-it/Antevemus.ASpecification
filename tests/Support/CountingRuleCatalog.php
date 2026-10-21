<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\Contracts\Engine\IRuleCatalog;
use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleTypeCatalog;

/**
 * CountingRuleCatalog - A rule catalog that counts the reads it serves (the database a cache spares).
 *
 * @version 2.8.0
 */
final class CountingRuleCatalog implements IRuleCatalog, IRuleTypeCatalog
{
    /** @var array<string, int> */
    public array $reads = [];

    public function __construct(private readonly IRuleCatalog&IRuleTypeCatalog $inner)
    {
    }

    public function total(): int
    {
        return array_sum($this->reads);
    }

    public function findRules(string $scope, ?string $scenario = null, array $filters = []): array
    {
        $this->count('findRules');

        return $this->inner->findRules($scope, $scenario, $filters);
    }

    public function findDocumentRules(string $scope, ?string $scenario = null): array
    {
        $this->count('findDocumentRules');

        return $this->inner->findDocumentRules($scope, $scenario);
    }

    public function findRuleByCode(string $code): ?IRuleDefinition
    {
        $this->count('findRuleByCode');

        return $this->inner->findRuleByCode($code);
    }

    public function findRulesByType(string $scope, string $ruleType, array $filters = []): array
    {
        $this->count('findRulesByType');

        return $this->inner->findRulesByType($scope, $ruleType, $filters);
    }

    private function count(string $method): void
    {
        $this->reads[$method] = ($this->reads[$method] ?? 0) + 1;
    }
}
