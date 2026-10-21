<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Cache\CachedFeatureFlagStore;
use Antevemus\ASpecification\Cache\Exceptions\CacheFailureException;
use Antevemus\ASpecification\FeatureFlags\Exceptions\FeatureFlagStoreException;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagChange;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagDefinition;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagReason;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagRegistry;
use Antevemus\ASpecification\FeatureFlags\FeatureFlags;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagState;
use Antevemus\ASpecification\FeatureFlags\IFeatureFlagStore;
use Antevemus\ASpecification\FeatureFlags\InMemoryFeatureFlagErrorReporter;
use Antevemus\ASpecification\FeatureFlags\InMemoryFeatureFlagStore;
use Antevemus\ASpecification\FeatureFlags\PdoFeatureFlagStore;
use Antevemus\ASpecification\Persistence\PdoTransactionManager;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryTransactionManager;
use Antevemus\ASpecification\Tests\Support\ArrayCache;
use Antevemus\ASpecification\Tests\Support\CountingFeatureFlagStore;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * CachedFeatureFlagStoreTest - the feature flags read through a shared PSR-16 cache: one read of the table
 * per TTL, every write invalidating its keys when the transaction ends, reads inside a transaction never
 * cached, two nodes over one database, and a failing cache read as a miss.
 *
 * @version 2.8.0
 */
final class CachedFeatureFlagStoreTest extends TestCase
{
    private int $now = 2_000_000;

    protected function setUp(): void
    {
        if (!interface_exists(CacheInterface::class)) {
            self::markTestSkipped('psr/simple-cache is not installed');
        }
    }

    public function testLoadAllAndFindAreServedFromTheCache(): void
    {
        [$inner, $counting] = self::stores();
        $cache = $this->cache();
        $store = new CachedFeatureFlagStore($counting, $cache);
        self::assertSame($counting, $store->getInner());
        self::assertSame(30, $store->getTtl());

        $expected = $inner->loadAll();
        self::assertEquals($expected, $store->loadAll());
        $hit = $store->loadAll();
        self::assertEquals($expected, $hit);
        self::assertSame(['beta', 'pilot'], array_keys($hit));
        self::assertSame(1, $counting->count('loadAll'));
        self::assertEquals(self::pilot(), $hit['pilot'], 'every field, the instants in their own time zone');
        self::assertSame('America/Sao_Paulo', $hit['pilot']->enabledAt?->getTimezone()->getName());
        self::assertSame('2026-01-02 03:04:05.678901', $hit['pilot']->enabledAt?->format('Y-m-d H:i:s.u'));
        self::assertSame('+02:00', $hit['pilot']->validUntil?->getTimezone()->getName());

        self::assertEquals(self::pilot(), $store->find('pilot'));
        self::assertEquals(self::pilot(), $store->find('pilot'));
        self::assertNull($store->find('missing'));
        self::assertNull($store->find('missing'), 'the absence is cached too');
        self::assertSame(2, $counting->count('find'));

        self::assertSame(self::sorted([$store->allKey(), $store->flagKey('missing'), $store->flagKey('pilot')]), $cache->keys());
        self::assertMatchesRegularExpression('/^aspec\.flags\.all\.[0-9a-f]{32}\.s1$/', $store->allKey());
        self::assertMatchesRegularExpression('/^aspec\.flags\.one\.[0-9a-f]{32}\.s1$/', $store->flagKey('pilot'));
        self::assertNotSame($store->flagKey('pilot'), $store->flagKey('beta'));
        self::assertSame(30, $cache->ttlOf($store->allKey()));

        self::assertSame([], $store->history());
        self::assertSame(1, $counting->count('history'), 'history() is not cached');
        self::assertTrue($store->supportsSubjects());
        self::assertTrue($store->supportsValidity());
        self::assertTrue($store->supportsRollout());
        $plain = new CachedFeatureFlagStore(new InMemoryFeatureFlagStore(subjects: false, validity: false, rollout: false), $cache);
        self::assertFalse($plain->supportsSubjects());
        self::assertFalse($plain->supportsValidity());
        self::assertFalse($plain->supportsRollout());
    }

    public function testTheServiceReadsOncePerTtlAcrossInstances(): void
    {
        [, $counting] = self::stores();
        $cache = $this->cache();
        $store = new CachedFeatureFlagStore($counting, $cache, 10);
        for ($request = 0; $request < 3; $request++) {
            $flags = self::service($store);
            self::assertTrue($flags->isEnabled('pilot', 'u1'));
            self::assertFalse($flags->isEnabled('pilot', 'u9'));
        }
        self::assertSame(1, $counting->count('loadAll'), 'three requests, one read');
        $this->now += 10;
        self::service($store)->isEnabled('pilot', 'u1');
        self::assertSame(2, $counting->count('loadAll'), 'expired after the TTL');
    }

    public function testEveryWriteInvalidatesTheFlagAndTheList(): void
    {
        [, $counting] = self::stores();
        $cache = $this->cache();
        $store = new CachedFeatureFlagStore($counting, $cache);
        $at = new DateTimeImmutable('2026-10-21 10:00:00');
        $writes = [
            'insertIfAbsent' => static fn() => $store->insertIfAbsent(new FeatureFlagDefinition('newbie', 'Newbie'), $at, 'ana'),
            'compareAndSetEnabled' => static fn() => $store->compareAndSetEnabled('beta', false, true, $at, 'ana'),
            'compareAndSetValidity' => static fn() => $store->compareAndSetValidity('beta', 2, $at, null, $at, 'ana'),
            'compareAndSetRollout' => static fn() => $store->compareAndSetRollout('beta', 3, 50, $at, 'ana'),
            'addSubject' => static fn() => $store->addSubject('beta', 'u5'),
            'removeSubject' => static fn() => $store->removeSubject('beta', 'u5'),
            'appendChange' => static fn() => $store->appendChange(new FeatureFlagChange('beta', true, false, 'a reason for it', 'ui', $at)),
        ];
        foreach ($writes as $method => $write) {
            $code = $method === 'insertIfAbsent' ? 'newbie' : 'beta';
            $store->loadAll();
            $store->find($code);
            $store->find('pilot');
            self::assertContains($store->flagKey($code), $cache->keys(), $method);
            $write();
            self::assertSame(1, $counting->count($method), $method);
            self::assertNotContains($store->allKey(), $cache->keys(), "{$method}: the list is gone");
            self::assertNotContains($store->flagKey($code), $cache->keys(), "{$method}: the flag is gone");
            self::assertContains($store->flagKey('pilot'), $cache->keys(), "{$method}: another flag stays");
        }
        self::assertTrue($store->find('beta')?->enabled);
        self::assertSame(50, $store->find('beta')?->rolloutPercent);
        self::assertSame(4, $store->find('beta')?->version);

        // A compare-and-set that fails invalidates too: the caller's view was stale
        $store->loadAll();
        self::assertFalse($store->compareAndSetEnabled('beta', false, true, $at, 'ana'));
        self::assertNotContains($store->allKey(), $cache->keys());

        // A write that throws invalidates as well, and the exception reaches the caller
        $store->loadAll();
        try {
            $store->addSubject('nobody_here', 'u1');
            self::fail('no failure');
        } catch (FeatureFlagStoreException) {
        }
        self::assertNotContains($store->allKey(), $cache->keys());
    }

    public function testInsideATransactionReadsAreNotCachedAndKeysGoAtTheEnd(): void
    {
        [, $counting] = self::stores();
        $cache = $this->cache();
        $store = new CachedFeatureFlagStore($counting, $cache);
        $manager = $store->getTransactionManager();
        self::assertSame($manager, $store->getTransactionManager(), 'one manager per store');
        $store->loadAll();
        $store->find('beta');
        $at = new DateTimeImmutable('2026-10-21 10:00:00');

        $result = $manager->transactional(function () use ($store, $cache, $at, $manager): string {
            $gets = $cache->calls('get');
            self::assertFalse($store->find('beta')?->enabled, 'read from the store');
            self::assertCount(2, $store->loadAll());
            self::assertSame($gets, $cache->calls('get'), 'no cache read inside the transaction');
            self::assertTrue($store->compareAndSetEnabled('beta', false, true, $at, 'ana'));
            self::assertContains($store->allKey(), $cache->keys(), 'still there until the end');
            $nested = $manager->transactional(fn(): bool => $store->addSubject('beta', 'u7'));
            self::assertTrue($nested);
            self::assertContains($store->flagKey('beta'), $cache->keys(), 'a nested call does not end the transaction');
            self::assertTrue($store->find('beta')?->enabled, 'the uncommitted value, from the store');

            return 'done';
        });
        self::assertSame('done', $result);
        self::assertNotContains($store->allKey(), $cache->keys());
        self::assertNotContains($store->flagKey('beta'), $cache->keys());
        self::assertSame(1, $cache->calls('deleteMultiple'), 'one call for all the keys');
        self::assertTrue($store->find('beta')?->enabled);
        self::assertSame(['u7'], $store->find('beta')?->subjects);

        // A transaction that throws: rolled back by the store's manager, keys deleted anyway
        $store->loadAll();
        try {
            $manager->transactional(function () use ($store, $at): void {
                $store->compareAndSetEnabled('beta', true, false, $at, 'ana');
                throw new RuntimeException('abort');
            });
            self::fail('no exception');
        } catch (RuntimeException $e) {
            self::assertSame('abort', $e->getMessage());
        }
        self::assertNotContains($store->allKey(), $cache->keys());
        self::assertTrue($store->find('beta')?->enabled, 'rolled back');
        self::assertTrue($store->loadAll()['beta']->enabled);
    }

    public function testReadsInsideATransactionOfTheDecoratedStoreAreNotCached(): void
    {
        $manager = new InMemoryTransactionManager();
        $inner = new InMemoryFeatureFlagStore($manager);
        $inner->put(new FeatureFlagState('beta', false));
        $cache = $this->cache();
        $store = new CachedFeatureFlagStore($inner, $cache);
        $manager->transactional(function () use ($store, $cache): void {
            $store->loadAll();
            $store->find('beta');
            self::assertSame(0, $cache->calls('get'));
            self::assertSame([], $cache->keys());
        });
        $store->loadAll();
        self::assertSame(1, $cache->calls('get'));

        $pdo = self::sqlite();
        $pdoStore = new CachedFeatureFlagStore(new PdoFeatureFlagStore($pdo, columns: PdoFeatureFlagStore::COLUMNS_SINCE_2_3), $this->cache());
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO feature_flag (code, name, enabled) VALUES ('dirty', 'Dirty', 'Y')");
        self::assertArrayHasKey('dirty', $pdoStore->loadAll());
        self::assertNotNull($pdoStore->find('dirty'));
        $pdo->rollBack();
        self::assertArrayNotHasKey('dirty', $pdoStore->loadAll(), 'the uncommitted row never reached the cache');
        self::assertNull($pdoStore->find('dirty'));
        self::assertInstanceOf(PdoTransactionManager::class, (new PdoFeatureFlagStore($pdo))->getTransactionManager());
    }

    public function testTwoNodesSharingACacheOverOneDatabase(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'aspec_flags_');
        try {
            self::sqlite($file);
            $cache = $this->cache();
            $nodeA = new CachedFeatureFlagStore(new PdoFeatureFlagStore(new PDO('sqlite:' . $file), columns: PdoFeatureFlagStore::COLUMNS_SINCE_2_3), $cache);
            $databaseB = new PDO('sqlite:' . $file);
            $nodeB = new CachedFeatureFlagStore(new PdoFeatureFlagStore($databaseB, columns: PdoFeatureFlagStore::COLUMNS_SINCE_2_3), $cache);

            self::assertFalse(self::service($nodeB)->isEnabled('beta', 'u1'), 'B: born off');
            self::assertFalse(self::service($nodeA)->isEnabled('beta', 'u1'), 'A reads what B cached');

            // A write through A is seen by the next request of B (A invalidated after its commit)
            self::service($nodeA)->toggle('beta', true, 'Opening the beta to everyone');
            self::assertTrue(self::service($nodeB)->isEnabled('beta', 'u1'));
            self::service($nodeA)->addSubject('beta', 'u1', 'Only the pilot customer');
            self::assertFalse(self::service($nodeB)->isEnabled('beta', 'u2'));
            self::assertTrue(self::service($nodeB)->isEnabled('beta', 'u1'));

            // A write the decorators did not see (a script on the database): B reads the old value within the TTL
            $databaseB->exec("DELETE FROM feature_flag_subject WHERE flag_code = 'beta'");
            self::assertFalse(self::service($nodeB)->isEnabled('beta', 'u2'), 'the stale read within the TTL');
            $this->now += 29;
            self::assertFalse(self::service($nodeB)->isEnabled('beta', 'u2'), 'still within the TTL');
            $this->now += 1;
            self::assertTrue(self::service($nodeB)->isEnabled('beta', 'u2'), 'seen at the end of the TTL');

            // Or at once, after invalidate()
            $databaseB->exec("UPDATE feature_flag SET enabled = 'N' WHERE code = 'beta'");
            self::assertTrue(self::service($nodeA)->isEnabled('beta', 'u2'));
            self::assertTrue($nodeA->invalidate('beta'));
            self::assertFalse(self::service($nodeB)->isEnabled('beta', 'u2'));
            $databaseB->exec("UPDATE feature_flag SET enabled = 'Y' WHERE code = 'beta'");
            self::assertTrue($nodeB->invalidateAll());
            self::assertTrue(self::service($nodeA)->isEnabled('beta', 'u2'));
            self::assertTrue($nodeA->find('beta')?->enabled);
        } finally {
            @unlink($file);
        }
    }

    public function testAFailingCacheIsAStoreWithoutCacheReportedOncePerKey(): void
    {
        [, $counting] = self::stores();
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $cache = $this->cache()->failOn('get', 'set', 'deleteMultiple');
        $store = new CachedFeatureFlagStore($counting, $cache, reporter: $reporter);
        for ($request = 0; $request < 3; $request++) {
            $flags = self::service($store, $reporter);
            self::assertTrue($flags->isEnabled('pilot', 'u1'));
            self::assertFalse($flags->isEnabled('beta', 'u1'));
            self::assertSame(FeatureFlagReason::NOT_IN_TARGET, $flags->evaluate('pilot', 'u9')->reason);
            self::assertNull($flags->lastError());
            self::assertEquals(self::pilot(), $store->find('pilot'));
        }
        self::assertSame(3, $counting->count('loadAll'));
        self::assertSame(0, $cache->calls('set'), 'a cache that fails reads is not written');
        self::assertCount(2, $reporter->entries(), 'the list and the flag, once each');
        self::assertSame([null, 'pilot'], array_column($reporter->entries(), 'code'));
        self::assertContainsOnlyInstancesOf(CacheFailureException::class, array_column($reporter->entries(), 'error'));

        self::service($store, $reporter)->toggle('beta', true, 'Opening it while the cache is down');
        self::assertCount(3, $reporter->entries(), 'the failed invalidation is reported');
        self::assertSame('delete', $reporter->entries()[2]['error']->operation);
        self::assertSame('beta', $reporter->entries()[2]['code']);
        self::assertFalse($store->invalidate('pilot'));
        self::assertFalse($store->invalidateAll());
        self::assertCount(3, $reporter->entries(), 'their keys (the flag pilot, the list) were already reported');

        $store->forgetReportedFailures();
        $store->loadAll();
        self::assertCount(4, $reporter->entries(), 'reported again after forgetReportedFailures()');

        // Writes refused: answers from the store, reported once per key
        $reporter->clear();
        $refusing = new CachedFeatureFlagStore($counting, $this->cache()->refuseWrites(), reporter: $reporter);
        $refusing->loadAll();
        $refusing->loadAll();
        $refusing->find('pilot');
        self::assertCount(2, $reporter->entries());
        self::assertSame('set', $reporter->entries()[0]['error']->operation);
    }

    public function testAnEntryOfAnotherShapeIsAMissAndIsReplaced(): void
    {
        [, $counting] = self::stores();
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $cache = $this->cache();
        $store = new CachedFeatureFlagStore($counting, $cache, reporter: $reporter);
        $good = ['code' => 'x_flag', 'enabled' => true, 'name' => null, 'description' => null, 'category' => null, 'audience' => null, 'notes' => null,
            'validFrom' => null, 'validUntil' => null, 'enabledAt' => null, 'enabledBy' => null, 'disabledAt' => null, 'disabledBy' => null,
            'version' => null, 'subjects' => [], 'rolloutPercent' => null];
        $broken = [
            'not an array' => 'x',
            'no list' => ['flags' => 'x'],
            'not a list' => ['flags' => ['a' => $good]],
            'a row that is not an array' => ['flags' => [3]],
            'a missing field' => ['flags' => [array_diff_key($good, ['notes' => null])]],
            'a string as bool' => ['flags' => [['enabled' => 'Y'] + $good]],
            'an int code' => ['flags' => [['code' => 7] + $good]],
            'a float version' => ['flags' => [['version' => 1.0] + $good]],
            'a subject that is not a string' => ['flags' => [['subjects' => [1]] + $good]],
            'subjects as text' => ['flags' => [['subjects' => 'u1'] + $good]],
            'a rollout out of range' => ['flags' => [['rolloutPercent' => 101] + $good]],
            'an instant as text' => ['flags' => [['enabledAt' => '2026-01-01'] + $good]],
            'an instant without zone' => ['flags' => [['enabledAt' => ['2026-01-01 00:00:00.000000']] + $good]],
            'an instant with a number' => ['flags' => [['enabledAt' => [1, 'UTC']] + $good]],
            'an unreadable instant' => ['flags' => [['enabledAt' => ['yesterday', 'UTC']] + $good]],
            'an unknown time zone' => ['flags' => [['enabledAt' => ['2026-01-01 00:00:00.000000', 'Mars/Olympus']] + $good]],
            'a text name' => ['flags' => [['name' => 5] + $good]],
            'a text rollout' => ['flags' => [['rolloutPercent' => '5'] + $good]],
            'an object' => new \stdClass(),
            'an instant as an object' => ['flags' => [['enabledAt' => new \stdClass()] + $good]],
            'an instant without text' => ['flags' => [['enabledAt' => [1 => 'UTC']] + $good]],
            'a time zone that is not text' => ['flags' => [['enabledAt' => ['2026-01-01 00:00:00.000000', 5]] + $good]],
            'a missing instant' => ['flags' => [array_diff_key($good, ['validFrom' => null])]],
        ];
        $reads = 0;
        foreach ($broken as $case => $value) {
            $cache->plant($store->allKey(), $value);
            $reporter->clear();
            $store->forgetReportedFailures();
            self::assertSame(['beta', 'pilot'], array_keys($store->loadAll()), $case);
            self::assertSame(++$reads, $counting->count('loadAll'), $case);
            self::assertCount(1, $reporter->entries(), $case);
            self::assertSame('read', $reporter->entries()[0]['error']->operation, $case);
            self::assertInstanceOf(\UnexpectedValueException::class, $reporter->entries()[0]['error']->getPrevious(), $case);
            $store->loadAll();
            self::assertSame($reads, $counting->count('loadAll'), "{$case}: replaced");
        }
        $cache->plant($store->allKey(), ['flags' => [['rolloutPercent' => 101] + $good]]);
        $reporter->clear();
        $store->forgetReportedFailures();
        $store->loadAll();
        $failure = $reporter->entries()[0]['error'];
        self::assertSame('Cache read of key "' . $store->allKey() . '" failed, read as a miss: a flag is not valid: The rollout percentage of feature flag "x_flag" must be between 0 and 100, 101 given.', $failure->getMessage());
        self::assertInstanceOf(InvalidArgumentException::class, $failure->getPrevious()?->getPrevious());
        $cache->plant($store->allKey(), ['flags' => [['enabledAt' => ['2026-01-01 00:00:00.000000', 'Mars/Olympus']] + $good]]);
        $reporter->clear();
        $store->forgetReportedFailures();
        $store->loadAll();
        self::assertStringEndsWith('read as a miss: the time zone of enabledAt is not valid', $reporter->entries()[0]['error']->getMessage());
        self::assertInstanceOf(\Exception::class, $reporter->entries()[0]['error']->getPrevious()?->getPrevious());
        $cache->plant($store->allKey(), ['flags' => [$good, ['code' => 'y_flag', 'enabledAt' => ['2026-01-01 00:00:00.000000', 'UTC']] + $good]]);
        $planted = $store->loadAll();
        self::assertSame(['x_flag', 'y_flag'], array_keys($planted), 'a valid planted entry is read, keyed by code');
        self::assertEquals(new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')), $planted['y_flag']->enabledAt);

        foreach (['no flag key' => ['x' => 1], 'not an array' => 'x', 'a broken flag' => ['flag' => ['code' => 'pilot']]] as $case => $value) {
            $cache->plant($store->flagKey('pilot'), $value);
            $reporter->clear();
            $store->forgetReportedFailures();
            self::assertEquals(self::pilot(), $store->find('pilot'), $case);
            self::assertCount(1, $reporter->entries(), $case);
            self::assertSame('pilot', $reporter->entries()[0]['code'], $case);
        }
    }

    public function testInvalidationTargetsTheKeysOfTheFlag(): void
    {
        [, $counting] = self::stores();
        $cache = $this->cache();
        $store = new CachedFeatureFlagStore($counting, $cache);
        $store->loadAll();
        $store->find('pilot');
        $store->find('beta');
        self::assertTrue($store->invalidate('pilot'));
        self::assertSame(self::sorted([$store->flagKey('beta')]), $cache->keys(), 'the flag pilot and the list are gone, beta stays');

        $store->loadAll();
        $store->find('pilot');
        $store->find('missing');
        self::assertTrue($store->invalidateAll());
        self::assertSame([$store->flagKey('missing')], $cache->keys(), 'every flag of the store and the list are gone (a code the store does not have is not known)');
    }

    public function testAWriteThatThrowsInvalidatesAndTheHistoryKeepsItsDefaultLimit(): void
    {
        $pdo = self::sqlite();
        $cache = $this->cache();
        $store = new CachedFeatureFlagStore(new PdoFeatureFlagStore($pdo, columns: PdoFeatureFlagStore::COLUMNS_SINCE_2_3), $cache);
        $at = new DateTimeImmutable('2026-10-21 10:00:00', new DateTimeZone('UTC'));
        for ($i = 0; $i < 52; $i++) {
            $store->appendChange(new FeatureFlagChange('beta', null, true, 'a reason for it', 'ui', $at->modify("+{$i} seconds")));
        }
        self::assertCount(50, $store->history(), 'the default limit of the interface, 50');
        self::assertCount(52, $store->history(limit: 60));
        self::assertCount(2, $store->history('beta', 2));

        $store->loadAll();
        $pdo->exec('DROP TABLE feature_flag_change');
        try {
            $store->appendChange(new FeatureFlagChange('beta', null, true, 'a reason for it', 'ui', $at));
            self::fail('no failure');
        } catch (FeatureFlagStoreException) {
        }
        self::assertNotContains($store->allKey(), $cache->keys(), 'invalidated although the write failed');
    }

    public function testReadsInsideATransactionOfAManagerOfTheApplicationAreNotCached(): void
    {
        $manager = new class implements \Antevemus\ASpecification\Persistence\ITransactionManager {
            public int $calls = 0;

            public function transactional(\Closure $work): mixed
            {
                $this->calls++;

                return $work();
            }
        };
        [, $counting] = self::stores();
        $cache = $this->cache();
        $store = new CachedFeatureFlagStore(new CountingFeatureFlagStore($counting, $manager), $cache);
        $store->getTransactionManager()->transactional(function () use ($store): void {
            $store->loadAll();
            $store->find('pilot');
        });
        self::assertSame(1, $manager->calls);
        self::assertSame(0, $cache->calls('get'), 'inside the transaction: the store, not the cache');
        self::assertSame([], $cache->keys());
        $store->loadAll();
        self::assertSame(1, $cache->calls('get'), 'outside: the cache');
    }

    public function testConfigurationIsValidated(): void
    {
        $inner = new InMemoryFeatureFlagStore();
        foreach ([0, -5] as $ttl) {
            try {
                new CachedFeatureFlagStore($inner, $this->cache(), $ttl);
                self::fail("TTL {$ttl} accepted");
            } catch (InvalidArgumentException $e) {
                self::assertSame("The TTL of a cached feature flag store must be at least 1 second, {$ttl} given.", $e->getMessage());
            }
        }
        self::assertSame(1, (new CachedFeatureFlagStore($inner, $this->cache(), 1))->getTtl());
        $tenant = new CachedFeatureFlagStore($inner, $this->cache(), prefix: 'app_', storeId: 'tenant_b');
        $default = new CachedFeatureFlagStore($inner, $this->cache());
        self::assertStringStartsWith('app_flags.all.', $tenant->allKey());
        self::assertNotSame(substr($default->allKey(), 6), substr($tenant->allKey(), 4), 'the store id is in the hash');
        self::assertNotSame(substr($default->flagKey('beta'), 6), substr($tenant->flagKey('beta'), 4));
        $this->expectException(InvalidArgumentException::class);
        new CachedFeatureFlagStore($inner, $this->cache(), prefix: 'a@b');
    }

    private function cache(): ArrayCache
    {
        return new ArrayCache(fn(): int => $this->now);
    }

    /** @return array{0: InMemoryFeatureFlagStore, 1: CountingFeatureFlagStore} */
    private static function stores(): array
    {
        $inner = new InMemoryFeatureFlagStore();
        $inner->put(self::pilot());
        $inner->put(new FeatureFlagState('beta', false, version: 1));

        return [$inner, new CountingFeatureFlagStore($inner)];
    }

    private static function pilot(): FeatureFlagState
    {
        return new FeatureFlagState(
            code: 'pilot',
            enabled: true,
            name: 'Pilot',
            description: 'The pilot',
            category: 'claims',
            audience: 'agencies',
            notes: 'notes',
            validFrom: new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')),
            validUntil: new DateTimeImmutable('2099-12-31 23:59:59.999999', new DateTimeZone('+02:00')),
            enabledAt: new DateTimeImmutable('2026-01-02 03:04:05.678901', new DateTimeZone('America/Sao_Paulo')),
            enabledBy: 'ana',
            disabledAt: new DateTimeImmutable('2026-01-01 08:00:00', new DateTimeZone('UTC')),
            disabledBy: 'bob',
            version: 7,
            subjects: ['u1', 'u2'],
            rolloutPercent: 100
        );
    }

    private static function service(IFeatureFlagStore $store, ?InMemoryFeatureFlagErrorReporter $reporter = null): FeatureFlags
    {
        return new FeatureFlags(new FeatureFlagRegistry([
            new FeatureFlagDefinition('pilot', 'Pilot', subjectKey: 'id'),
            new FeatureFlagDefinition('beta', 'Beta', subjectKey: 'id'),
        ]), $store, $reporter, static fn(): DateTimeImmutable => new DateTimeImmutable('2026-06-01 12:00:00', new DateTimeZone('UTC')));
    }

    /** @param list<string> $keys @return list<string> */
    private static function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }

    private static function sqlite(?string $file = null): PDO
    {
        $pdo = new PDO($file === null ? 'sqlite::memory:' : 'sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $ddl = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/sql/feature_flags.ansi.sql');
        $lines = array_filter(explode("\n", $ddl), static fn(string $line): bool => !str_starts_with(ltrim($line), '--'));
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }

        return $pdo;
    }
}
