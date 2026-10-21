<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Cache\CacheKeys;
use Antevemus\ASpecification\Cache\CachedFeatureFlagStore;
use Antevemus\ASpecification\Cache\CachedRuleCatalog;
use Antevemus\ASpecification\Cache\PdoCatalogVersion;
use Antevemus\ASpecification\Engine\InMemoryRuleCatalog;
use Antevemus\ASpecification\Engine\PdoRuleCatalog;
use Antevemus\ASpecification\Engine\RuleDefinition;
use Antevemus\ASpecification\Engine\RulePrecedence;
use Antevemus\ASpecification\Entities\AbstractUUIDEntity;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagDefinition;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagReason;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagRegistry;
use Antevemus\ASpecification\FeatureFlags\FeatureFlags;
use Antevemus\ASpecification\FeatureFlags\InMemoryFeatureFlagErrorReporter;
use Antevemus\ASpecification\FeatureFlags\InMemoryFeatureFlagStore;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagState;
use Antevemus\ASpecification\FeatureFlags\PdoFeatureFlagStore;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Tests\Support\ArrayCache;
use Antevemus\ASpecification\Tests\Support\CountingFeatureFlagStore;
use Antevemus\ASpecification\Tests\Support\CountingRuleCatalog;
use Antevemus\ASpecification\Tests\Support\PdoRecordingPdo;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use Antevemus\ASpecification\Tests\Support\RedisCache;
use Antevemus\ASpecification\Tests\TestCase;
use Closure;
use PDO;
use Psr\SimpleCache\CacheInterface;
use stdClass;
use TypeError;

/**
 * LeaseContract - the entity of README 23.1 (a lease contract routed by its state).
 *
 * @version 2.8.0
 */
final class LeaseContract extends AbstractUUIDEntity
{
    public function __construct(public readonly string $state, public readonly int $rent)
    {
        parent::__construct();
    }
}

/**
 * Module43_DistributedCacheTest - Module 43: the rule catalog, the feature flags and the routing clusters of a
 * partitioned repository read through a PSR-16 cache shared between servers (2.8.0).
 *
 * - README 23 and 23.1 (docs/<language>/caching/index.md) transcribed and run, the transcription checked token
 *   by token against the page, both languages with the same code
 * - two servers sharing one cache over one database: a change made through one is seen by the other after
 *   the invalidation; a change neither saw is read as before until the TTL ends (the documented stale read)
 * - a cache that fails is a miss, reported once per key per request
 * - without psr/simple-cache (the leg of the suite without it): the library works as before, the cache
 *   classes load but cannot be built; the transcriptions are still checked
 * - with ASPEC_REDIS_DSN (the Redis round of tests/Fixtures/pdo_repository/run_portability.sh): the same
 *   scenarios over a real Redis, between two connections and between two PHP processes
 *
 * @version 2.8.0
 */
final class Module43_DistributedCacheTest extends TestCase
{
    /** The page of each example. */
    private const README_PAGES = ['23' => 'caching/index', '23.1' => 'caching/index'];

    private int $now = 5_000_000;

    public function run(): void
    {
        $this->testReadmeBlocksAreTheSameInBothLanguages();
        $this->assertTranscribed('23', 0, 'README 23');
        $this->assertTranscribed('23.1', 0, 'README 23.1');
        $this->testCatalogVersionNeedsNoCache();
        if (!interface_exists(CacheInterface::class)) {
            $this->testTheLibraryWithoutPsrSimpleCache();

            return;
        }
        $this->testReadme23();
        $this->testReadme231();
        $this->testTwoServersSharingOneCache(fn(): CacheInterface => $this->arrayCache ??= new ArrayCache(fn(): int => $this->now), fn(int $seconds) => $this->now += $seconds);
        $this->testAFailingCacheIsAMissReportedOncePerKey();
        $dsn = getenv('ASPEC_REDIS_DSN');
        if (is_string($dsn) && $dsn !== '') {
            $this->testRedis($dsn);
        }
    }

    private ?ArrayCache $arrayCache = null;

    ///////////////////////////////////////////////////////////////////////////
    // README 23 and 23.1 (transcribed; the transcription is checked against the page)
    ///////////////////////////////////////////////////////////////////////////

    private function testReadme23(): void
    {
        $pdo = self::database(recording: true);
        $cache = new ArrayCache(fn(): int => $this->now);

        // README 23 >>>
        $errors = new InMemoryFeatureFlagErrorReporter();                  // or your logger, through IFeatureFlagErrorReporter

        $pdo->exec("INSERT INTO business_rule (code, name, rule_type, int_value, scope, scenario, updated_at) VALUES
    ('notice', 'Notice window', 'claim_notice', 30, 'claim', NULL, '2026-10-21 09:00:00'),
    ('notice_vacant', 'Notice window, vacant', 'claim_notice', 45, 'claim', 'vacant', '2026-10-21 09:00:00')");

        // The catalog: read once per TTL by the whole fleet; with a version, a change is seen at once
        $rules = new PdoRuleCatalog($pdo);
        $catalog = CachedRuleCatalog::decorate($rules, $cache, ttl: 60,
            version: PdoCatalogVersion::forRuleCatalog($pdo, $rules, 'updated_at'), reporter: $errors);
        $r1 = RulePrecedence::findRule($catalog, 'claim', 'claim_notice', 'vacant')->getIntValue();   // 45, read from the database
        $r2 = RulePrecedence::findRule($catalog, 'claim', 'claim_notice', 'vacant')->getIntValue();   // 45, read from the cache

        $pdo->exec("UPDATE business_rule SET int_value = 60, updated_at = '2026-10-21 09:30:00' WHERE code = 'notice_vacant'");
        $r3 = RulePrecedence::findRule($catalog, 'claim', 'claim_notice', 'vacant')->getIntValue();   // 60: the version changed
        $r4 = $catalog->invalidate();             // without a version: call it after the transaction that changed the rules commits

        // The flags: one read of the table per TTL for the fleet; a change made through the service invalidates its keys
        $store = new CachedFeatureFlagStore(new PdoFeatureFlagStore($pdo), $cache, ttl: 30, reporter: $errors);
        $registry = new FeatureFlagRegistry([new FeatureFlagDefinition('claim_portal', 'Claim portal')]);
        (new FeatureFlags($registry, $store))->toggle('claim_portal', true, 'Portal released to every agency');
        $r5 = (new FeatureFlags($registry, $store))->isEnabled('claim_portal');   // true: the next request, on any server

        $r6 = $errors->entries();   // []: a failure of the cache would be here, once per key, and the answers would come from the database
        // <<< README 23

        $this->assertEquals(45, $r1, 'README 23: from the database');
        $this->assertEquals(45, $r2, 'README 23: from the cache');
        $this->assertTrue($pdo instanceof PdoRecordingPdo);
        $catalogReads = array_filter($pdo->statements, static fn(string $sql): bool => str_contains($sql, 'FROM business_rule WHERE'));
        $this->assertCount(2, $catalogReads, 'README 23: the catalog was read for the first call and after the change, not for the second (a hit)');
        $this->assertEquals(60, $r3, 'README 23: the version changed');
        $this->assertTrue($r4, 'README 23: invalidate()');
        $this->assertTrue($r5, 'README 23: the flag on the next request');
        $this->assertEquals([], $r6, 'README 23: no failure');
        $this->assertTrue((new FeatureFlags($registry, $store))->isEnabled('claim_portal'));
        $this->assertTrue(in_array($store->allKey(), $cache->keys(), true), 'README 23: the list of flags is cached');
    }

    private function testReadme231(): void
    {
        $cache = new ArrayCache(fn(): int => $this->now);
        $routing = PartitionRepository::isDagRoutingEnabled();
        try {
            // README 23.1 >>>
            // Every request builds the graph again; after the first one, the clusters of each node come from the cache
            PartitionRepository::setDagRoutingEnabled(true);                   // the routing clusters, off by default
            $contracts = PartitionRepository::create(new InMemoryRepository())
                ->withIndexCache($cache, 'lease_contracts');                    // on the root, before the first put()
            foreach (['SP', 'RJ', 'MG', 'PR'] as $state) {
                $contracts->addPartition(Spec::property('state', Spec::equalTo($state)));
            }
            $contracts->put(new LeaseContract('SP', 3200));
            $r1 = $contracts->getIndexCache()->statistics();   // ['hits' => 0, 'misses' => 1, 'uncacheable' => 0]: this request built them
            // <<< README 23.1

            // The next request: a new graph, the same partitions
            $next = PartitionRepository::create(new InMemoryRepository())->withIndexCache($cache, 'lease_contracts');
            foreach (['SP', 'RJ', 'MG', 'PR'] as $state) {
                $next->addPartition(Spec::property('state', Spec::equalTo($state)));
            }
            $next->put(new LeaseContract('RJ', 2100));
            $next->put(new LeaseContract('SP', 1800));
            $r2 = $next->getIndexCache()?->statistics();
        } finally {
            PartitionRepository::setDagRoutingEnabled($routing);
        }

        $this->assertEquals(['hits' => 0, 'misses' => 1, 'uncacheable' => 0], $r1, 'README 23.1: built');
        $this->assertEquals(['hits' => 1, 'misses' => 0, 'uncacheable' => 0], $r2, 'README 23.1: read by the next request');
        $this->assertEquals([0, 0, 0, 0], $cache->get($cache->keys()[0]), 'README 23.1: the four states are mutually disjoint, one cluster');
        $this->assertCount(1, $next->getDirectPartitions()[1]->getEntitiesOfThisPartitionOnly(), 'README 23.1: RJ routed to its partition');
        $this->assertCount(1, $next->getDirectPartitions()[0]->getEntitiesOfThisPartitionOnly(), 'README 23.1: SP routed to its partition');
    }

    private function testReadmeBlocksAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (self::README_PAGES as $example => $page) {
            $english = ReadmeBlocks::of($root . '/docs/en/' . $page . '.md', $example);
            $portuguese = ReadmeBlocks::of($root . '/docs/pt-BR/' . $page . '.md', $example);
            $this->assertCount(1, $english, "um bloco PHP no exemplo {$example} (EN)");
            $this->assertCount(1, $portuguese, "um bloco PHP no exemplo {$example} (pt-BR)");
            $this->assertEquals($english[0], $portuguese[0], "README {$example}: o mesmo código, byte a byte");
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // Two servers, one cache, one database, and a failing cache
    ///////////////////////////////////////////////////////////////////////////

    /**
     * @param Closure(): CacheInterface $connect A connection to the shared cache (one per server)
     * @param Closure(int): void $wait Lets the given seconds pass for the cache
     * @param int $catalogTtl TTL of the catalog entries
     * @param int $flagTtl TTL of the flag entries
     */
    private function testTwoServersSharingOneCache(Closure $connect, Closure $wait, int $catalogTtl = CachedRuleCatalog::DEFAULT_TTL, int $flagTtl = CachedFeatureFlagStore::DEFAULT_TTL): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'aspec43_');
        try {
            self::database($file);
            $serverA = new PDO('sqlite:' . $file);
            $serverB = new PDO('sqlite:' . $file);
            $id = 'm43_' . bin2hex(random_bytes(4));

            // The catalog
            $innerA = new CountingRuleCatalog(new InMemoryRuleCatalog([]));
            $catalogA = CachedRuleCatalog::decorate(new PdoRuleCatalog($serverA), $connect(), $catalogTtl, catalogId: $id);
            $catalogB = CachedRuleCatalog::decorate(new PdoRuleCatalog($serverB), $connect(), $catalogTtl, catalogId: $id);
            $serverA->exec("INSERT INTO business_rule (code, name, rule_type, int_value, scope) VALUES ('max_claims', 'Max', 'claim_max', 2, 'claim')");
            $this->assertEquals(2, $catalogA->findRules('claim')[0]->getIntValue(), 'A reads the database');
            $serverA->exec("UPDATE business_rule SET int_value = 3 WHERE code = 'max_claims'");
            $this->assertEquals(2, $catalogB->findRules('claim')[0]->getIntValue(), 'B reads what A cached: the stale read within the TTL, documented');
            $catalogA->invalidate();
            $this->assertEquals(3, $catalogB->findRules('claim')[0]->getIntValue(), 'after the invalidation by A, B reads the change');
            $serverA->exec("UPDATE business_rule SET int_value = 4 WHERE code = 'max_claims'");
            $this->assertEquals(3, $catalogA->findRules('claim')[0]->getIntValue(), 'A reads what B cached');
            $wait($catalogTtl + 1);
            $this->assertEquals(4, $catalogB->findRules('claim')[0]->getIntValue(), 'at the end of the TTL, B reads the change');
            $this->assertEquals(0, $innerA->total());

            // The flags
            $registry = new FeatureFlagRegistry([new FeatureFlagDefinition('claim_portal', 'Claim portal', subjectKey: 'id')]);
            $countingB = new CountingFeatureFlagStore(new PdoFeatureFlagStore($serverB));
            $storeA = new CachedFeatureFlagStore(new PdoFeatureFlagStore($serverA), $connect(), $flagTtl, storeId: $id);
            $storeB = new CachedFeatureFlagStore($countingB, $connect(), $flagTtl, storeId: $id);
            $this->assertFalse((new FeatureFlags($registry, $storeB))->isEnabled('claim_portal', 'agency_1'), 'born off');
            (new FeatureFlags($registry, $storeA))->toggle('claim_portal', true, 'Portal released to every agency');
            $this->assertTrue((new FeatureFlags($registry, $storeB))->isEnabled('claim_portal', 'agency_1'), 'a write through A is seen by the next request of B');
            (new FeatureFlags($registry, $storeA))->addSubject('claim_portal', 'agency_1', 'Pilot agency first');
            $this->assertFalse((new FeatureFlags($registry, $storeB))->isEnabled('claim_portal', 'agency_2'));
            $reads = $countingB->count('loadAll');
            $this->assertTrue((new FeatureFlags($registry, $storeB))->isEnabled('claim_portal', 'agency_1'));
            $this->assertEquals($reads, $countingB->count('loadAll'), 'B served from the cache');

            $serverB->exec("DELETE FROM feature_flag_subject WHERE flag_code = 'claim_portal'");
            $this->assertFalse((new FeatureFlags($registry, $storeB))->isEnabled('claim_portal', 'agency_2'), 'a script on the database: the stale read within the TTL, documented');
            $this->assertFalse((new FeatureFlags($registry, $storeA))->isEnabled('claim_portal', 'agency_2'));
            $wait($flagTtl);
            $this->assertTrue((new FeatureFlags($registry, $storeB))->isEnabled('claim_portal', 'agency_2'), 'at the end of the TTL');
            $serverB->exec("UPDATE feature_flag SET enabled = 'N' WHERE code = 'claim_portal'");
            $storeB->invalidate('claim_portal');
            $this->assertFalse((new FeatureFlags($registry, $storeA))->isEnabled('claim_portal', 'agency_2'), 'or at once after invalidate()');
        } finally {
            @unlink($file);
        }
    }

    private function testAFailingCacheIsAMissReportedOncePerKey(): void
    {
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $cache = (new ArrayCache(fn(): int => $this->now))->failOn('get', 'set', 'deleteMultiple');
        $inner = new InMemoryFeatureFlagStore();
        $inner->put(new FeatureFlagState('claim_portal', true, subjects: ['agency_1']));
        $store = new CachedFeatureFlagStore($inner, $cache, reporter: $reporter);
        $registry = new FeatureFlagRegistry([new FeatureFlagDefinition('claim_portal', 'Claim portal', subjectKey: 'id')]);
        $catalog = CachedRuleCatalog::decorate(new InMemoryRuleCatalog([new RuleDefinition('notice', 'Notice', 'claim_notice', intValue: 30, scope: 'claim')]), $cache, reporter: $reporter);
        for ($request = 0; $request < 3; $request++) {
            $flags = new FeatureFlags($registry, $store, $reporter);
            $this->assertTrue($flags->isEnabled('claim_portal', 'agency_1'), 'answered by the store');
            $this->assertTrue($flags->evaluate('claim_portal', 'agency_2')->reason === FeatureFlagReason::NOT_IN_TARGET);
            $this->assertEquals(30, RulePrecedence::findRule($catalog, 'claim', 'claim_notice')?->getIntValue(), 'answered by the catalog');
        }
        $this->assertCount(2, $reporter->entries(), 'the list of flags and the generation of the catalog, once each');
        $this->assertEquals(0, $cache->calls('set'), 'nothing written to a cache that fails reads');
    }

    ///////////////////////////////////////////////////////////////////////////
    // Without psr/simple-cache, and the catalog version (needs no cache)
    ///////////////////////////////////////////////////////////////////////////

    private function testCatalogVersionNeedsNoCache(): void
    {
        $pdo = self::database();
        $version = PdoCatalogVersion::forRuleCatalog($pdo, new PdoRuleCatalog($pdo), 'updated_at');
        $empty = $version->catalogVersion();
        $pdo->exec("INSERT INTO business_rule (code, name, rule_type, updated_at) VALUES ('a', 'A', 't', '2026-10-21 09:00:00')");
        $this->assertTrue($empty !== $version->catalogVersion(), 'a new rule changes the version');
        $this->assertEquals('aspec.catalog.s1', CacheKeys::key(CacheKeys::DEFAULT_PREFIX, 'catalog', 's1'));
    }

    private function testTheLibraryWithoutPsrSimpleCache(): void
    {
        $this->assertFalse(interface_exists(CacheInterface::class), 'psr/simple-cache is not installed');
        $this->assertTrue(class_exists(CachedRuleCatalog::class), 'the decorator loads');
        $this->assertThrows(TypeError::class, static fn() => new CachedRuleCatalog(new InMemoryRuleCatalog([]), new stdClass()), 'but cannot be built without a PSR-16 cache');
        $routing = PartitionRepository::isDagRoutingEnabled();
        try {
            PartitionRepository::setDagRoutingEnabled(true);
            $contracts = PartitionRepository::create(new InMemoryRepository());
            $contracts->addPartition(Spec::property('state', Spec::equalTo('SP')));
            $contracts->addPartition(Spec::property('state', Spec::equalTo('RJ')));
            $contracts->put(new LeaseContract('RJ', 2100));
            $this->assertCount(1, $contracts->getDirectPartitions()[1]->getEntitiesOfThisPartitionOnly(), 'routing clusters without the cache');
            $this->assertTrue($contracts->getIndexCache() === null);
        } finally {
            PartitionRepository::setDagRoutingEnabled($routing);
        }
        $flags = new FeatureFlags(new FeatureFlagRegistry([new FeatureFlagDefinition('claim_portal', 'Claim portal')]), new InMemoryFeatureFlagStore());
        $flags->toggle('claim_portal', true, 'Portal released to every agency');
        $this->assertTrue($flags->isEnabled('claim_portal'), 'the flags without the cache');
    }

    ///////////////////////////////////////////////////////////////////////////
    // Redis (ASPEC_REDIS_DSN): two connections and two processes
    ///////////////////////////////////////////////////////////////////////////

    private function testRedis(string $dsn): void
    {
        $control = new RedisCache($dsn);
        $control->clear();
        // Real time: TTLs of 2 s, waited for
        $this->testTwoServersSharingOneCache(static fn(): CacheInterface => new RedisCache($dsn), static function (int $seconds): void {
            usleep($seconds * 1_000_000 + 100_000);
        }, 2, 2);

        // The TTLs reach Redis as EX
        $store = new CachedFeatureFlagStore(new InMemoryFeatureFlagStore(), new RedisCache($dsn), ttl: 7, storeId: 'ttl_probe');
        $store->loadAll();
        $ttl = $control->ttlOf($store->allKey());
        $this->assertTrue($ttl > 0 && $ttl <= 7, "Redis: TTL {$ttl}");

        // Another PHP process reads what this one wrote: the catalog and the routing clusters
        $catalog = CachedRuleCatalog::decorate(new InMemoryRuleCatalog([
            new RuleDefinition('notice', 'Notice', 'claim_notice', intValue: 30, scope: 'claim'),
            new RuleDefinition('notice_vacant', 'Notice, vacant', 'claim_notice', intValue: 45, scope: 'claim', scenario: 'vacant'),
        ]), new RedisCache($dsn), catalogId: 'cross_process');
        $this->assertCount(2, $catalog->findRules('claim', 'vacant'));
        $routing = PartitionRepository::isDagRoutingEnabled();
        try {
            PartitionRepository::setDagRoutingEnabled(true);
            $contracts = PartitionRepository::create(new InMemoryRepository())->withIndexCache(new RedisCache($dsn), 'cross_process');
            foreach (['SP', 'RJ', 'MG'] as $state) {
                $contracts->addPartition(Spec::property('state', Spec::equalTo($state)));
            }
            $contracts->put(new LeaseContract('MG', 900));
        } finally {
            PartitionRepository::setDagRoutingEnabled($routing);
        }
        $child = <<<'PHP'
            require 'tests/bootstrap.php';
            require_once 'tests/Unit/Module43_DistributedCacheTest.php';
            use Antevemus\ASpecification\Cache\CachedRuleCatalog;
            use Antevemus\ASpecification\Engine\InMemoryRuleCatalog;
            use Antevemus\ASpecification\Repositories\InMemoryRepository;
            use Antevemus\ASpecification\Repositories\PartitionRepository;
            use Antevemus\ASpecification\Spec;
            use Antevemus\ASpecification\Tests\Support\RedisCache;
            use Antevemus\ASpecification\Tests\Unit\LeaseContract;
            $dsn = getenv('ASPEC_REDIS_DSN');
            $catalog = CachedRuleCatalog::decorate(new InMemoryRuleCatalog([]), new RedisCache($dsn), catalogId: 'cross_process');
            PartitionRepository::setDagRoutingEnabled(true);
            $contracts = PartitionRepository::create(new InMemoryRepository())->withIndexCache(new RedisCache($dsn), 'cross_process');
            foreach (['SP', 'RJ', 'MG'] as $state) {
                $contracts->addPartition(Spec::property('state', Spec::equalTo($state)));
            }
            $contracts->put(new LeaseContract('SP', 1000));
            echo json_encode(['rules' => count($catalog->findRules('claim', 'vacant')), 'pid' => getmypid(), 'dag' => $contracts->getIndexCache()->statistics()]);
            PHP;
        $output = shell_exec(sprintf('cd %s && ASPEC_REDIS_DSN=%s %s -d xdebug.mode=off -r %s 2>&1', escapeshellarg(dirname(__DIR__, 2)), escapeshellarg($dsn), escapeshellarg(PHP_BINARY), escapeshellarg($child)));
        // The last line is the answer (a PHP startup warning of the host may come before it)
        $lines = preg_split('/\R/', trim((string) $output)) ?: [];
        $answer = json_decode((string) end($lines), true);
        $this->assertTrue(is_array($answer), 'Redis: the child process answered: ' . $output);
        $this->assertEquals(2, $answer['rules'], 'Redis: another process read the rules this one cached (its own catalog is empty)');
        $this->assertTrue($answer['pid'] !== getmypid(), 'Redis: another process');
        $this->assertEquals(['hits' => 1, 'misses' => 0, 'uncacheable' => 0], $answer['dag'], 'Redis: another process read the routing clusters');
        $control->clear();
    }

    ///////////////////////////////////////////////////////////////////////////
    // Support
    ///////////////////////////////////////////////////////////////////////////

    /** The tables of the two reference DDLs, with the update stamp the application keeps on the catalog. */
    private static function database(?string $file = null, bool $recording = false): PDO
    {
        $dsn = $file === null ? 'sqlite::memory:' : 'sqlite:' . $file;
        $pdo = $recording ? new PdoRecordingPdo($dsn) : new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['rule_catalog', 'feature_flags'] as $name) {
            $ddl = (string) file_get_contents(dirname(__DIR__, 2) . "/resources/sql/{$name}.ansi.sql");
            $lines = array_filter(explode("\n", $ddl), static fn(string $line): bool => !str_starts_with(ltrim($line), '--'));
            foreach (explode(';', implode("\n", $lines)) as $statement) {
                if (trim($statement) !== '') {
                    $pdo->exec($statement);
                }
            }
        }
        foreach (['business_rule', 'document_requirement_group', 'document_requirement'] as $table) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN updated_at VARCHAR(20)");
        }

        return $pdo;
    }

    /**
     * The code between the markers `README <name> >>>` and `<<< README <name>` of this file is, token by token
     * (comments and blanks aside, and an expression the page shows bare kept as `$rN = <expression>`), a
     * contiguous part of block $index of the README example $example.
     */
    private function assertTranscribed(string $example, int $index, string $name): void
    {
        $source = (string) file_get_contents(__FILE__);
        $start = strpos($source, "// {$name} >>>\n");
        $end = strpos($source, "// <<< {$name}\n");
        $this->assertTrue($start !== false && $end !== false && $end > $start, "transcrição {$name} marcada");
        $tokens = ReadmeBlocks::codeTokens(substr($source, (int) $start, (int) $end - (int) $start));
        $transcribed = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if (preg_match('/^\$r\d+$/', $tokens[$i]) === 1 && ($tokens[$i + 1] ?? null) === '=') {
                $i++;
                continue;
            }
            $transcribed[] = $tokens[$i];
        }
        foreach (['en', 'pt-BR'] as $language) {
            $blocks = ReadmeBlocks::of(dirname(__DIR__, 2) . "/docs/{$language}/" . self::README_PAGES[$example] . '.md', $example);
            $this->assertTrue(isset($blocks[$index]), "README {$example} ({$language}): bloco {$index}");
            $block = ReadmeBlocks::codeTokens($blocks[$index] ?? '');
            $found = false;
            $length = count($transcribed);
            for ($i = 0; $i + $length <= count($block) && !$found; $i++) {
                $found = array_slice($block, $i, $length) === $transcribed;
            }
            $this->assertTrue($length > 10 && $found, "{$name}: a transcrição é o código do README {$example} ({$language})");
        }
    }
}
