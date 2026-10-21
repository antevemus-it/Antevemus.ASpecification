<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Cache;

use Antevemus\ASpecification\Contracts\Engine\IRuleCatalog;
use Antevemus\ASpecification\Contracts\Engine\IRuleTypeCatalog;
use Antevemus\ASpecification\FeatureFlags\IFeatureFlagErrorReporter;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

/**
 * CachedRuleTypeCatalog - A Cached Catalog That Also Reads the Rules of a Type in One Query
 *
 * CachedRuleCatalog for a catalog that implements IRuleTypeCatalog (InMemoryRuleCatalog, PdoRuleCatalog):
 * findRulesByType() is cached like the other reads (key `catalog.<hash>.s1` over the scope, the type and
 * the reserved filters), so RulePrecedence::findRule() keeps its one query, now once per TTL.
 * CachedRuleCatalog::decorate() returns this class when the catalog allows it.
 *
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Cache
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class CachedRuleTypeCatalog extends CachedRuleCatalog implements IRuleTypeCatalog
{
    /**
     * Same parameters as CachedRuleCatalog::__construct(), with a catalog that implements IRuleTypeCatalog.
     *
     * @throws InvalidArgumentException When the TTL is below 1 or the prefix is not valid
     */
    public function __construct(
        IRuleCatalog&IRuleTypeCatalog $inner,
        CacheInterface $cache,
        int $ttl = self::DEFAULT_TTL,
        ?ICatalogVersion $version = null,
        string $prefix = CacheKeys::DEFAULT_PREFIX,
        string $catalogId = 'default',
        ?IFeatureFlagErrorReporter $reporter = null
    ) {
        parent::__construct($inner, $cache, $ttl, $version, $prefix, $catalogId, $reporter);
    }

    /** {@inheritdoc} */
    public function findRulesByType(string $scope, string $ruleType, array $filters = []): array
    {
        /** @var IRuleCatalog&IRuleTypeCatalog $inner */
        $inner = $this->inner;
        $load = static fn(): array => $inner->findRulesByType($scope, $ruleType, $filters);
        $applicability = self::applicability($filters);
        if ($applicability === null) {
            return $load();
        }

        return $this->cachedRules('type', array_merge([$scope, $ruleType], $applicability), $load);
    }
}
