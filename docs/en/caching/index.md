# Caching

### 23. Caching the Rule Catalog and the Feature Flags (Module 43)

Since v2.8.0 the rule catalog and the feature flags can be read through a PSR-16 cache shared by every server, instead of once per request: under PHP-FPM each request builds its own `PdoRuleCatalog` and `FeatureFlags`, and each one went to the database. `CachedRuleCatalog` and `CachedFeatureFlagStore` are decorators: they implement the same interfaces as what they wrap, answer exactly what it answers, and only change how often the database is read. They need `psr/simple-cache` (interfaces only, a `suggest` of this package) and a PSR-16 implementation of your choice; without them nothing changes.

```php
use Antevemus\ASpecification\Cache\CachedFeatureFlagStore;
use Antevemus\ASpecification\Cache\CachedRuleCatalog;
use Antevemus\ASpecification\Cache\PdoCatalogVersion;
use Antevemus\ASpecification\Engine\PdoRuleCatalog;
use Antevemus\ASpecification\Engine\RulePrecedence;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagDefinition;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagRegistry;
use Antevemus\ASpecification\FeatureFlags\FeatureFlags;
use Antevemus\ASpecification\FeatureFlags\InMemoryFeatureFlagErrorReporter;
use Antevemus\ASpecification\FeatureFlags\PdoFeatureFlagStore;

// $cache: a PSR-16 cache shared by every server (the Redis, Memcached or APCu adapter of your choice)
// $pdo: the tables of resources/sql/rule_catalog.ansi.sql and resources/sql/feature_flags.ansi.sql, with the
//       update stamp the application writes on business_rule (updated_at)
$errors = new InMemoryFeatureFlagErrorReporter();                  // or your logger, through IFeatureFlagErrorReporter

$pdo->exec("INSERT INTO business_rule (code, name, rule_type, int_value, scope, scenario, updated_at) VALUES
    ('notice', 'Notice window', 'claim_notice', 30, 'claim', NULL, '2026-10-21 09:00:00'),
    ('notice_vacant', 'Notice window, vacant', 'claim_notice', 45, 'claim', 'vacant', '2026-10-21 09:00:00')");

// The catalog: read once per TTL by the whole fleet; with a version, a change is seen at once
$rules = new PdoRuleCatalog($pdo);
$catalog = CachedRuleCatalog::decorate($rules, $cache, ttl: 60,
    version: PdoCatalogVersion::forRuleCatalog($pdo, $rules, 'updated_at'), reporter: $errors);
RulePrecedence::findRule($catalog, 'claim', 'claim_notice', 'vacant')->getIntValue();   // 45, read from the database
RulePrecedence::findRule($catalog, 'claim', 'claim_notice', 'vacant')->getIntValue();   // 45, read from the cache

$pdo->exec("UPDATE business_rule SET int_value = 60, updated_at = '2026-10-21 09:30:00' WHERE code = 'notice_vacant'");
RulePrecedence::findRule($catalog, 'claim', 'claim_notice', 'vacant')->getIntValue();   // 60: the version changed
$catalog->invalidate();             // without a version: call it after the transaction that changed the rules commits

// The flags: one read of the table per TTL for the fleet; a change made through the service invalidates its keys
$store = new CachedFeatureFlagStore(new PdoFeatureFlagStore($pdo), $cache, ttl: 30, reporter: $errors);
$registry = new FeatureFlagRegistry([new FeatureFlagDefinition('claim_portal', 'Claim portal')]);
(new FeatureFlags($registry, $store))->toggle('claim_portal', true, 'Portal released to every agency');
(new FeatureFlags($registry, $store))->isEnabled('claim_portal');   // true: the next request, on any server

$errors->entries();   // []: a failure of the cache would be here, once per key, and the answers would come from the database
```

- **What is cached.** The catalog: `findRules()`, `findDocumentRules()`, `findRuleByCode()` and, through `CachedRuleTypeCatalog` (what `decorate()` returns for a catalog that implements `IRuleTypeCatalog`), `findRulesByType()`, so `RulePrecedence` keeps its single query. The flags: `loadAll()` (the read `FeatureFlags` makes once per instance, subject lists included) and `find()`. Never cached: the history of the flags and every write. Values are stored as plain arrays and read back as `RuleDefinition`, `DocumentRuleDefinition` and `FeatureFlagState` (instants with their microseconds and time zone).
- **Keys.** `aspec.catalog.<hash>.s1`, `aspec.flags.all.<hash>.s1`, `aspec.flags.one.<hash>.s1`: only the characters every PSR-16 implementation accepts, at most 64 after the prefix (`prefix:` changes `aspec.`). Values of the application (a scope, a flag code) are hashed, never written into a key. The hash of a catalog read covers the scope, the scenario and only the reserved filters of the catalog contract (`product_code`, `plan_code`, the day of `reference_date`, and the day of `PdoRuleCatalog::withAsOf()`), so the rest of the validation context does not split the cache. Filters the catalog refuses are not cached: the catalog refuses them as before. Two catalogs or stores over different tables sharing a cache and a prefix take different `catalogId:`/`storeId:`.
- **When a change is seen.** Catalog: `invalidate()` starts a new generation that every server reads on its next call; an `ICatalogVersion` (`PdoCatalogVersion`: `COUNT(*)` and `MAX(<stamp>)` per table, one light query each, on every call) makes a change visible at once; otherwise at the end of the TTL (60 s by default). Flags: every write goes to the store and deletes the keys of its flag and of `loadAll()` when the outermost transaction of the store ends (after the commit when the store opened it; a change that joined a transaction of yours is invalidated again with `invalidate($code)` after your commit); reads inside a transaction go to the store and are never cached; a write the decorator did not see (a script, a migration) is seen at the end of the TTL (30 s by default) or after `invalidate()`/`invalidateAll()`. Between servers the consistency is eventual within the TTL: a read that raced with a commit may keep the previous value until the TTL ends. Nothing locks; the compare-and-set of the store is still the truth of every change.
- **When the cache fails.** A cache that throws, refuses a write or holds an entry of another shape is a miss: the answer comes from the database, as without the cache, and the failure (`CacheFailureException`, with the operation and the key) goes to the error reporter once per key per instance (one request), never once per read. A failure of the database itself reaches the caller as before, and `FeatureFlags` still turns it into "off" (fail closed).
- **Without `psr/simple-cache`.** The library keeps one runtime dependency: the cache classes can be loaded without the interfaces but cannot be built, and every other class works the same (the suite runs green without the package).

### 23.1. Caching the Routing Clusters of a Partitioned Repository

The materialized index of `PartitionRepository` (example 4) has an opt-in part, the routing clusters: the direct partitions of a node that are mutually disjoint, so that `put()` stops at the first one that accepts an entity. Building them costs one `isDisjointWith()` pair per pair of partitions, which is expensive for set specifications and was paid again by every request that builds the graph. `withIndexCache()` keeps them in the shared cache: the first process builds and stores them, every other one reads them.

```php
use Antevemus\ASpecification\Entities\AbstractUUIDEntity;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use Antevemus\ASpecification\Spec;

final class LeaseContract extends AbstractUUIDEntity
{
    public function __construct(public readonly string $state, public readonly int $rent)
    {
        parent::__construct();
    }
}

// Every request builds the graph again; after the first one, the clusters of each node come from the cache
PartitionRepository::setDagRoutingEnabled(true);                   // the routing clusters, off by default
$contracts = PartitionRepository::create(new InMemoryRepository())
    ->withIndexCache($cache, 'lease_contracts');                    // on the root, before the first put()
foreach (['SP', 'RJ', 'MG', 'PR'] as $state) {
    $contracts->addPartition(Spec::property('state', Spec::equalTo($state)));
}
$contracts->put(new LeaseContract('SP', 3200));
$contracts->getIndexCache()->statistics();   // ['hits' => 0, 'misses' => 1, 'uncacheable' => 0]: this request built them
// The next request (any process sharing the cache), the same partitions: ['hits' => 1, 'misses' => 0, 'uncacheable' => 0]
```

- **The key.** `aspec.dag.<hash of the repository id>.<fingerprint>.s1`, per node: the fingerprint is the hash of the node's direct partitions, in attachment order, written by `SpecificationCodec`, and of the installed versions of the library and of the application (Composer), so a changed partition gives another key and a deploy starts from a cold index. The value is one small integer per partition. The data of the partitions never enters the cache.
- **What is not cached.** A node whose partitions the codec cannot write (a callback, a leaf of your own that is not registered on the codec passed to `withIndexCache()`) builds its clusters in memory, as without the cache (`statistics()['uncacheable']`); a node with fewer than two partitions needs no algebra. The subtree order of the index is one walk over the nodes of the process and stays in memory.
- **What it buys.** In the benchmark graph of the partitioned repository (200 partitions of set specifications), routing with the clusters built in the request was more than an order of magnitude slower than routing without them; with the clusters read from the cache, it was about as fast as without them. That is why the clusters are off by default, and why the cache is what makes them worth turning on in a server that builds the graph on every request.
- **Configuration.** On the root only (`LogicException` otherwise), before the first `put()`; `ttl:` (one day by default: the clusters never go stale, the TTL only frees memory), `prefix:`, `reporter:` and `codec:` as in the decorators of example 23.
