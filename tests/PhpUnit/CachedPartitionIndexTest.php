<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Cache\CacheKeys;
use Antevemus\ASpecification\Cache\CachedPartitionIndex;
use Antevemus\ASpecification\Cache\Exceptions\CacheFailureException;
use Antevemus\ASpecification\Contracts\Repositories\IPartitionRepository;
use Antevemus\ASpecification\Entities\AbstractUUIDEntity;
use Antevemus\ASpecification\FeatureFlags\InMemoryFeatureFlagErrorReporter;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use Antevemus\ASpecification\Serialization\SpecificationCodec;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Tests\Support\ArrayCache;
use Composer\InstalledVersions;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

/**
 * CachePartitionItem - an entity routed by its bucket.
 *
 * @version 2.8.0
 */
final class CachePartitionItem extends AbstractUUIDEntity
{
    public function __construct(public int $bucket)
    {
        parent::__construct();
    }

    public function getBucket(): int
    {
        return $this->bucket;
    }
}

/**
 * CacheBucketLeaf - a serializable leaf of the application (bucket below a limit), registered on a codec.
 *
 * @version 2.8.0
 */
final class CacheBucketLeaf extends \Antevemus\ASpecification\AbstractSpecification implements \Antevemus\ASpecification\Contracts\ISerializableSpecification
{
    public function __construct(public readonly int $limit)
    {
    }

    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $candidate instanceof CachePartitionItem && $candidate->bucket < $this->limit;
    }

    public function getType(): string
    {
        return CachePartitionItem::class;
    }

    public function toArray(): array
    {
        return ['limit' => $this->limit];
    }

    public static function fromArray(array $data, SpecificationCodec $codec): static
    {
        return new static((int) $data['limit']);
    }
}

/**
 * CachedPartitionIndexTest - the routing clusters of a partitioned repository kept in a shared PSR-16 cache:
 * a second process reads them instead of comparing the partitions, and routes every entity to the same
 * partitions as without the cache.
 *
 * @version 2.8.0
 */
final class CachedPartitionIndexTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(CacheInterface::class)) {
            self::markTestSkipped('psr/simple-cache is not installed');
        }
    }

    protected function tearDown(): void
    {
        PartitionRepository::setDagRoutingEnabled(false);
        PartitionRepository::setDagIndexEnabled(true);
    }

    public function testConfigurationIsValidated(): void
    {
        $cache = new ArrayCache();
        $index = new CachedPartitionIndex($cache, 'contracts');
        self::assertSame('contracts', $index->getRepositoryId());
        self::assertSame(86400, $index->getTtl());
        self::assertSame(['hits' => 0, 'misses' => 0, 'uncacheable' => 0], $index->statistics());
        self::assertSame(1, (new CachedPartitionIndex($cache, 'x', 1))->getTtl());
        foreach ([['', 10, 'cannot be empty'], ['  ', 10, 'cannot be empty'], ['x', 0, 'at least 1 second, 0 given'], ['x', -1, 'at least 1 second, -1 given']] as [$id, $ttl, $message]) {
            try {
                new CachedPartitionIndex($cache, $id, $ttl);
                self::fail('accepted');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new CachedPartitionIndex($cache, 'x', prefix: 'a/b');
    }

    public function testTheFingerprintIsTheCodecDocumentsAndTheInstalledVersions(): void
    {
        $index = new CachedPartitionIndex(new ArrayCache(), 'contracts');
        $a = Spec::property('bucket', Spec::in(1, 2));
        $b = Spec::property('bucket', Spec::in(3, 4));
        $codec = new SpecificationCodec();
        $root = InstalledVersions::getRootPackage();
        $installed = $root['name'] . '@' . $root['pretty_version'] . '#' . ($root['reference'] ?? '')
            . ' antevemus/aspecification@' . InstalledVersions::getPrettyVersion('antevemus/aspecification') . '#' . InstalledVersions::getReference('antevemus/aspecification');
        $fingerprint = $index->fingerprint([$a, $b, null]);
        self::assertSame(CacheKeys::hash([$installed, $codec->toJson($a), $codec->toJson($b), null]), $fingerprint);
        self::assertSame($fingerprint, $index->fingerprint([Spec::property('bucket', Spec::in(1, 2)), $b, null]), 'equal specifications, equal fingerprint');
        self::assertNotSame($fingerprint, $index->fingerprint([$b, $a, null]), 'the order counts');
        self::assertNotSame($fingerprint, $index->fingerprint([$a, $b]));
        self::assertSame(['hits' => 0, 'misses' => 0, 'uncacheable' => 0], $index->statistics());

        self::assertNull($index->fingerprint([$a, new PredicateSpecification(static fn(): bool => true)]), 'a callback has no document');
        self::assertSame(1, $index->statistics()['uncacheable']);

        // A leaf of the application: fingerprinted through the codec it was registered on
        self::assertNull($index->fingerprint([new CacheBucketLeaf(5)]), 'not registered on the default codec');
        $custom = (new SpecificationCodec())->register('bucketBelow', CacheBucketLeaf::class);
        $withLeaf = new CachedPartitionIndex(new ArrayCache(), 'contracts', codec: $custom);
        self::assertSame(CacheKeys::hash([$installed, $custom->toJson(new CacheBucketLeaf(5))]), $withLeaf->fingerprint([new CacheBucketLeaf(5)]));
        self::assertNotSame($withLeaf->fingerprint([new CacheBucketLeaf(5)]), $withLeaf->fingerprint([new CacheBucketLeaf(6)]));

        $key = $index->key($fingerprint);
        self::assertSame('aspec.dag.' . hash('xxh64', 'contracts') . '.' . $fingerprint . '.s1', $key);
        self::assertSame(56, strlen($key) - strlen('aspec.'));
        self::assertSame('app_dag.' . hash('xxh64', 'other') . '.' . $fingerprint . '.s1', (new CachedPartitionIndex(new ArrayCache(), 'other', prefix: 'app_'))->key($fingerprint));
    }

    public function testClustersAreStoredNumberedFromZeroAndCheckedWhenRead(): void
    {
        $cache = new ArrayCache();
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $index = new CachedPartitionIndex($cache, 'contracts', 120, reporter: $reporter);
        $fingerprint = str_repeat('a', 32);
        self::assertNull($index->load($fingerprint, 3));
        self::assertTrue($index->store($fingerprint, [7, 7, 9, 7, 4]));
        self::assertSame([0, 0, 1, 0, 2], $cache->get($index->key($fingerprint)));
        self::assertSame(120, $cache->ttlOf($index->key($fingerprint)));
        self::assertSame([0, 0, 1, 0, 2], $index->load($fingerprint, 5));
        self::assertSame(['hits' => 1, 'misses' => 1, 'uncacheable' => 0], $index->statistics());
        self::assertSame([], $reporter->entries());

        $invalid = [
            'another count' => [[0, 1], 3],
            'not a list' => [[1 => 0, 2 => 0], 2],
            'not an array' => ['0,1', 2],
            'starts at 1' => [[1, 0], 2],
            'skips a number' => [[0, 2, 1], 3],
            'negative' => [[0, -1], 2],
            'a string' => [[0, '1'], 2],
            'a float' => [[0, 1.0], 2],
        ];
        foreach ($invalid as $case => [$value, $count]) {
            $cache->plant($index->key($fingerprint), $value);
            $index->forgetReportedFailures();
            $reporter->clear();
            self::assertNull($index->load($fingerprint, $count), $case);
            self::assertCount(1, $reporter->entries(), $case);
            self::assertSame('read', $reporter->entries()[0]['error']->operation, $case);
        }
        foreach ([[[0, 1, 0, 2, 1], 5], [[0], 1], [[], 0], [[0, 0, 0], 3], [[0, 1, 2], 3]] as [$value, $count]) {
            $cache->plant($index->key($fingerprint), $value);
            self::assertSame($value, $index->load($fingerprint, $count), json_encode($value));
        }

        $cache->failOn('get', 'set');
        $index->forgetReportedFailures();
        $reporter->clear();
        self::assertNull($index->load($fingerprint, 5));
        self::assertNull($index->load($fingerprint, 5));
        self::assertFalse($index->store($fingerprint, [0]));
        self::assertCount(1, $reporter->entries(), 'one key, one report');
        self::assertInstanceOf(CacheFailureException::class, $reporter->entries()[0]['error']);
    }

    public function testASecondProcessReadsTheClustersAndRoutesTheSame(): void
    {
        PartitionRepository::setDagRoutingEnabled(true);
        $cache = new ArrayCache();
        $items = self::items();

        $plain = self::graph();
        $first = self::graph()->withIndexCache($cache, 'contracts');
        $second = self::graph()->withIndexCache($cache, 'contracts');
        foreach ([$plain, $first, $second] as $graph) {
            $graph->putAll(array_map(static fn(CachePartitionItem $item): CachePartitionItem => clone $item, $items));
        }

        self::assertSame(['hits' => 0, 'misses' => 4, 'uncacheable' => 0], $first->getIndexCache()?->statistics(), 'root + 3 nodes with 2 or more partitions');
        self::assertSame(['hits' => 4, 'misses' => 0, 'uncacheable' => 0], $second->getIndexCache()?->statistics());
        self::assertCount(4, $cache->keys());
        self::assertSame(4, $cache->calls('set'));
        self::assertSame(self::layout($plain), self::layout($first));
        self::assertSame(self::layout($plain), self::layout($second), 'every entity in the same partitions');
        self::assertSame(self::buckets($plain->findAll(new AlwaysTrueSpecification())), self::buckets($second->findAll(new AlwaysTrueSpecification())));
        self::assertSame(self::buckets($plain->findAll(Spec::property('bucket', Spec::lessThan(30)))), self::buckets($second->findAll(Spec::property('bucket', Spec::lessThan(30)))));

        // A partition added after the clusters were read keeps the clusters current, as without the cache
        $late = Spec::property('bucket', Spec::in(...range(90, 99)));
        $plain->addPartition($late);
        $second->addPartition($late);
        $extra = [new CachePartitionItem(95), new CachePartitionItem(3)];
        $plain->putAll(array_map(static fn(CachePartitionItem $item): CachePartitionItem => clone $item, $extra));
        $second->putAll(array_map(static fn(CachePartitionItem $item): CachePartitionItem => clone $item, $extra));
        self::assertSame(self::layout($plain), self::layout($second));

        // A different graph under the same repository id: other fingerprints, other entries
        $other = PartitionRepository::create(new InMemoryRepository())->withIndexCache($cache, 'contracts');
        $other->addPartition(Spec::property('bucket', Spec::in(1, 2)));
        $other->addPartition(Spec::property('bucket', Spec::in(3, 4)));
        $other->put(new CachePartitionItem(3));
        self::assertSame(['hits' => 0, 'misses' => 1, 'uncacheable' => 0], $other->getIndexCache()?->statistics());
    }

    public function testClustersReadFromTheCacheLeaveRoomForTheNextPartitions(): void
    {
        PartitionRepository::setDagRoutingEnabled(true);
        $cache = new ArrayCache();
        $build = static function (?ArrayCache $cache): PartitionRepository {
            $graph = PartitionRepository::create(new InMemoryRepository());
            self::assertInstanceOf(PartitionRepository::class, $graph);
            if ($cache !== null) {
                $graph->withIndexCache($cache, 'overlapping');
            }
            // Three partitions in two clusters: [0, 20) and [40, 60) are disjoint, [10, 30) overlaps the first
            foreach ([[0, 20], [10, 30], [40, 60]] as [$lo, $hi]) {
                $graph->addPartition(Spec::property('bucket', Spec::in(...range($lo, $hi - 1))));
            }

            return $graph;
        };
        $build($cache)->put(new CachePartitionItem(1));          // stores the clusters
        $plain = $build(null);
        $cached = $build($cache);
        $plain->put(new CachePartitionItem(1));
        $cached->put(new CachePartitionItem(1));
        self::assertSame(['hits' => 1, 'misses' => 0, 'uncacheable' => 0], $cached->getIndexCache()?->statistics());
        self::assertSame([0, 1, 0], $cache->get($cache->keys()[0]));

        // A partition attached afterwards that overlaps every cluster needs a cluster of its own
        foreach ([$plain, $cached] as $graph) {
            $graph->addPartition(Spec::property('bucket', Spec::in(...range(15, 45))));
        }
        $items = [new CachePartitionItem(12), new CachePartitionItem(15), new CachePartitionItem(45), new CachePartitionItem(25)];
        $plain->putAll(array_map(static fn(CachePartitionItem $item): CachePartitionItem => clone $item, $items));
        $cached->putAll(array_map(static fn(CachePartitionItem $item): CachePartitionItem => clone $item, $items));
        self::assertSame(self::layout($plain), self::layout($cached));
        self::assertCount(4, $cached->getDirectPartitions(), 'a sibling of the three');
        self::assertSame([15, 25, 45], self::buckets($cached->getDirectPartitions()[3]->getEntitiesOfThisPartitionOnly()), 'the overlapping partition received every entity it accepts');
    }

    public function testTheCacheBelongsToTheRootAndIsOnlyUsedWithRoutingClusters(): void
    {
        $cache = new ArrayCache();
        $graph = self::graph();
        self::assertNull($graph->getIndexCache());
        $child = $graph->getDirectPartitions()[0];
        try {
            $child->withIndexCache($cache, 'contracts');
            self::fail('a child accepted the cache');
        } catch (LogicException $e) {
            self::assertSame('withIndexCache() configures a whole partition graph: call it on its root.', $e->getMessage());
        }
        self::assertSame($graph, $graph->withIndexCache($cache, 'contracts', 60, 'app_'));
        self::assertSame($graph->getIndexCache(), $child->getIndexCache());
        self::assertSame(60, $graph->getIndexCache()?->getTtl());
        self::assertNotNull($child->getDirectPartitions()[0]->getIndexCache(), 'a grandchild reads the root\'s');

        $graph->putAll(self::items());
        self::assertSame(0, $cache->calls('get'), 'without setDagRoutingEnabled(true) the clusters are not built');

        PartitionRepository::setDagRoutingEnabled(true);
        $graph->putAll(self::items());
        self::assertSame(4, $cache->calls('get'));
        self::assertSame(4, count($cache->keys()));
        foreach ($cache->keys() as $key) {
            self::assertStringStartsWith('app_dag.', $key);
            self::assertSame(60, $cache->ttlOf($key));
        }
    }

    public function testCallingItLaterRebuildsTheRootClustersThroughTheCache(): void
    {
        PartitionRepository::setDagRoutingEnabled(true);
        $cache = new ArrayCache();
        $graph = self::graph();
        $graph->putAll(self::items());
        $graph->withIndexCache($cache, 'contracts');
        $graph->put(new CachePartitionItem(1));
        self::assertSame(['hits' => 0, 'misses' => 1, 'uncacheable' => 0], $graph->getIndexCache()?->statistics(), 'the root rebuilt its clusters');
    }

    public function testNodesThatCannotBeFingerprintedOrHaveOnePartitionAreNotCached(): void
    {
        PartitionRepository::setDagRoutingEnabled(true);
        $cache = new ArrayCache();
        $graph = PartitionRepository::create(new InMemoryRepository())->withIndexCache($cache, 'contracts');
        $graph->addPartition(Spec::property('bucket', Spec::in(1, 2)));
        $graph->addPartition(Spec::property('bucket', Spec::lessThan(0))->must(static fn(CachePartitionItem $item): bool => $item->bucket < -10));
        $graph->put(new CachePartitionItem(1));
        self::assertSame(['hits' => 0, 'misses' => 0, 'uncacheable' => 1], $graph->getIndexCache()?->statistics());
        self::assertSame([], $cache->keys());
        self::assertCount(1, $graph->getDirectPartitions()[0]->getEntitiesOfThisPartitionOnly());

        $single = PartitionRepository::create(new InMemoryRepository())->withIndexCache($cache, 'single');
        $single->addPartition(Spec::property('bucket', Spec::in(1, 2)));
        $single->put(new CachePartitionItem(2));
        self::assertSame(0, $cache->calls('get'), 'one partition: no algebra, no cache');
        self::assertSame(['hits' => 0, 'misses' => 0, 'uncacheable' => 0], $single->getIndexCache()?->statistics());
    }

    public function testAFailingCacheRoutesInMemoryAndIsReportedOnce(): void
    {
        PartitionRepository::setDagRoutingEnabled(true);
        $reporter = new InMemoryFeatureFlagErrorReporter();
        $cache = (new ArrayCache())->failOn('get', 'set');
        $plain = self::graph();
        $graph = self::graph()->withIndexCache($cache, 'contracts', reporter: $reporter);
        $plain->putAll(self::items());
        $graph->putAll(self::items());
        self::assertSame(self::layout($plain), self::layout($graph));
        self::assertCount(4, $reporter->entries(), 'one per node key');
        self::assertSame(['get'], array_values(array_unique(array_map(static fn(array $entry): string => $entry['error']->operation, $reporter->entries()))));
    }

    /** Root with 4 disjoint partitions; the first, second and third with 2, 3 and 2 disjoint sub-partitions. */
    private static function graph(): PartitionRepository
    {
        $graph = PartitionRepository::create(new InMemoryRepository());
        self::assertInstanceOf(PartitionRepository::class, $graph);
        foreach ([[0, 20], [20, 40], [40, 60], [60, 80]] as [$lo, $hi]) {
            $graph->addPartition(Spec::property('bucket', Spec::in(...range($lo, $hi - 1))));
        }
        foreach ([[0, 10], [10, 20], [20, 25], [25, 30], [30, 40], [40, 50], [50, 60]] as [$lo, $hi]) {
            $graph->addPartition(Spec::property('bucket', Spec::in(...range($lo, $hi - 1))));
        }

        return $graph;
    }

    /** @return list<CachePartitionItem> */
    private static function items(): array
    {
        $items = [];
        foreach ([0, 5, 9, 10, 19, 20, 24, 27, 33, 39, 41, 55, 61, 79, 80, 85] as $bucket) {
            $items[] = new CachePartitionItem($bucket);
        }

        return $items;
    }

    /**
     * The buckets of every node, in the order of the walk.
     *
     * @return list<list<int>>
     */
    private static function layout(IPartitionRepository $graph): array
    {
        $layout = [self::buckets($graph->getEntitiesOfThisPartitionOnly())];
        foreach ($graph->getDirectPartitions() as $partition) {
            foreach (self::layout($partition) as $node) {
                $layout[] = $node;
            }
        }

        return $layout;
    }

    /** @param iterable<CachePartitionItem> $items @return list<int> */
    private static function buckets(iterable $items): array
    {
        $buckets = [];
        foreach ($items as $item) {
            $buckets[] = $item->bucket;
        }
        sort($buckets);

        return $buckets;
    }
}
