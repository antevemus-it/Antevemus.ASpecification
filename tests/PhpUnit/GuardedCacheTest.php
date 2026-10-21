<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Cache\Exceptions\CacheFailureException;
use Antevemus\ASpecification\Cache\GuardedCache;
use Antevemus\ASpecification\FeatureFlags\IFeatureFlagErrorReporter;
use Antevemus\ASpecification\FeatureFlags\InMemoryFeatureFlagErrorReporter;
use Antevemus\ASpecification\Tests\Support\ArrayCache;
use Antevemus\ASpecification\Tests\Support\ArrayCacheFailure;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Throwable;

/**
 * GuardedCacheTest - a cache failure is a miss, reported once per key (the shared core of the decorators).
 *
 * @version 2.8.0
 */
final class GuardedCacheTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(CacheInterface::class)) {
            self::markTestSkipped('psr/simple-cache is not installed');
        }
    }

    public function testGetTellsAHitAMissAndAFailureApart(): void
    {
        $cache = new ArrayCache();
        $guard = new GuardedCache($cache);
        self::assertSame($cache, $guard->cache());
        self::assertSame([GuardedCache::MISS, null], $guard->get('k'));
        $cache->set('k', null);
        self::assertSame([GuardedCache::HIT, null], $guard->get('k'), 'a stored null is a hit');
        $cache->set('k', ['v' => 1]);
        self::assertSame([GuardedCache::HIT, ['v' => 1]], $guard->get('k'));
        $cache->failOn('get');
        self::assertSame([GuardedCache::FAILED, null], $guard->get('k'));
        self::assertSame([1, 0, -1], [GuardedCache::HIT, GuardedCache::MISS, GuardedCache::FAILED]);
    }

    public function testFailuresAreReportedOncePerKeyWithTheFlagCode(): void
    {
        $cache = (new ArrayCache())->failOn('get', 'set', 'deleteMultiple');
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $guard = new GuardedCache($cache, $reporter);

        self::assertSame([GuardedCache::FAILED, null], $guard->get('a', 'flag_a'));
        $guard->get('a', 'flag_a');
        self::assertFalse($guard->set('a', 1, 10));
        self::assertFalse($guard->set('b', 1, 10));
        self::assertFalse($guard->delete(['c', 'a']));
        $guard->fail('read', 'a', 'again');
        $guard->fail('version', 'd', 'the version', new RuntimeException('down'));

        $entries = $reporter->entries();
        self::assertCount(4, $entries, 'a, b, c, d: one report per key');
        self::assertSame(['flag_a', null, null, null], array_column($entries, 'code'));
        $failures = array_column($entries, 'error');
        self::assertSame(['get', 'set', 'delete', 'version'], array_map(static fn(CacheFailureException $e): string => $e->operation, $failures));
        self::assertSame(['a', 'b', 'c', 'd'], array_map(static fn(CacheFailureException $e): string => $e->key, $failures));
        self::assertSame('Cache get of key "a" failed, read as a miss: ArrayCache: get failed on purpose', $failures[0]->getMessage());
        self::assertInstanceOf(ArrayCacheFailure::class, $failures[0]->getPrevious());
        self::assertSame('Cache version of key "d" failed, read as a miss: the version', $failures[3]->getMessage());
        self::assertSame('down', $failures[3]->getPrevious()?->getMessage());

        $guard->forgetReportedFailures();
        $guard->get('a');
        self::assertCount(5, $reporter->entries(), 'reported again after forgetReportedFailures()');
    }

    public function testARefusedWriteOrDeleteIsAFailure(): void
    {
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $cache = (new ArrayCache())->refuseWrites();
        $guard = new GuardedCache($cache, $reporter);
        self::assertFalse($guard->set('k', 1, null));
        self::assertSame('Cache set of key "k" failed, read as a miss: the cache refused the value', $reporter->entries()[0]['error']->getMessage());
        self::assertNull($reporter->entries()[0]['error']->getPrevious());

        $cache->heal();
        self::assertTrue($guard->set('k', 1, 5));
        self::assertSame(5, $cache->ttlOf('k'));
        self::assertTrue($guard->set('n', 1, null));
        self::assertNull($cache->ttlOf('n'), 'null TTL = the cache default (no expiry here)');
        self::assertTrue($guard->delete(['k', 'n']));
        self::assertSame([], $cache->keys());

        $calls = $cache->calls('deleteMultiple');
        self::assertTrue($guard->delete([]), 'nothing to delete');
        self::assertSame($calls, $cache->calls('deleteMultiple'), 'no call for an empty list');

        $guard = new GuardedCache((new ArrayCache())->refuseDeletes(), $reporter);
        self::assertFalse($guard->delete(['x', 'y']));
        $last = $reporter->entries()[count($reporter->entries()) - 1]['error'];
        self::assertSame('Cache delete of key "x" failed, read as a miss: the cache did not delete the keys', $last->getMessage());
    }

    public function testWithoutAReporterOrWithABrokenOneNothingIsThrown(): void
    {
        $cache = (new ArrayCache())->failOn('get', 'set');
        $silent = new GuardedCache($cache);
        self::assertSame([GuardedCache::FAILED, null], $silent->get('k'));
        self::assertFalse($silent->set('k', 1, 1));
        $silent->fail('read', 'k', 'x');

        $broken = new class implements IFeatureFlagErrorReporter {
            public int $calls = 0;

            public function report(?string $code, Throwable $error): void
            {
                $this->calls++;
                throw new RuntimeException('the logger is down');
            }
        };
        $guard = new GuardedCache($cache, $broken);
        self::assertSame([GuardedCache::FAILED, null], $guard->get('k'));
        self::assertFalse($guard->set('j', 1, 1));
        self::assertSame(2, $broken->calls);
    }
}
