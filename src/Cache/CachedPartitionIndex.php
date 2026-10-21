<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Cache;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\FeatureFlags\IFeatureFlagErrorReporter;
use Antevemus\ASpecification\Serialization\SpecificationCodec;
use Composer\InstalledVersions;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * CachedPartitionIndex - The Routing Clusters of a Partitioned Repository, Shared Between Processes
 *
 * The materialized index of PartitionRepository (1.6.0) has two parts. The subtree order (part (ii)) is
 * built by one walk over the nodes of this process, which no cache can skip, so it stays in memory. The
 * routing clusters (part (i), setDagRoutingEnabled()) group the direct partitions of a node that are
 * mutually disjoint, and building them costs one isDisjointWith() pair per pair of partitions: for
 * set specifications, milliseconds per pair (tests/Benchmark/REPORT-dag-index.md), paid again by every
 * request that builds the graph. This class keeps them in a PSR-16 cache, opted in per graph with
 * PartitionRepository::withIndexCache(): the first process builds them, every other one reads them.
 *
 * - Key: `<prefix>dag.<hash of the repository id>.<fingerprint>.s1`. The fingerprint is the hash of the
 *   direct partitions' specifications, in attachment order, written by the SpecificationCodec, and of the
 *   installed versions of the library and of the application (Composer): the clusters are a function of
 *   those specifications and of the algebra that compared them, so the same fingerprint gives the same
 *   clusters, a changed partition gives another key, and a deploy starts from a cold index. Specification
 *   classes of your own (registered on the codec) that change without a Composer version change need a new
 *   repository id.
 * - Value: the cluster of each partition, by position (a list of small integers). The data of the
 *   partitions never enters the cache: it stays in their repositories.
 * - A node whose partitions cannot all be written by the codec (a callback, a specification not
 *   registered) is not cached: its clusters are built in memory, as without the cache. Nodes with fewer
 *   than two partitions need no algebra and are not cached either.
 * - Failures: a cache failure, or an entry of an unexpected shape, is a miss (the clusters are built in
 *   memory), reported once per key per instance.
 *
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Cache
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class CachedPartitionIndex
{
    /** Seconds an entry lives when no TTL is given: the clusters never go stale, the TTL only frees memory. */
    public const DEFAULT_TTL = 86400;

    /** Version of the stored format (the last segment of every key). */
    public const FORMAT = 's1';

    private readonly GuardedCache $guard;

    private readonly string $prefix;

    private readonly string $repositoryHash;

    private readonly SpecificationCodec $codec;

    /** The installed versions of the library and of the application, part of every fingerprint. */
    private readonly string $installed;

    private int $hits = 0;

    private int $misses = 0;

    private int $uncacheable = 0;

    /**
     * @param CacheInterface $cache The shared PSR-16 cache
     * @param string $repositoryId Name of the partitioned repository (not empty); graphs with different shapes may share it, the fingerprint tells them apart
     * @param int $ttl Seconds an entry lives (>= 1)
     * @param string $prefix Prefix of every key (A-Z, a-z, 0-9, "_" and ".")
     * @param IFeatureFlagErrorReporter|null $reporter Where cache failures are reported (null = not reported; still misses)
     * @param SpecificationCodec|null $codec Writes the specifications for the fingerprint (null = a default codec; register your own leaves on it)
     * @throws InvalidArgumentException When the repository id is empty, the TTL is below 1 or the prefix is not valid
     */
    public function __construct(
        CacheInterface $cache,
        private readonly string $repositoryId,
        private readonly int $ttl = self::DEFAULT_TTL,
        string $prefix = CacheKeys::DEFAULT_PREFIX,
        ?IFeatureFlagErrorReporter $reporter = null,
        ?SpecificationCodec $codec = null
    ) {
        if (trim($repositoryId) === '') {
            throw new InvalidArgumentException('The repository id of a partition index cache cannot be empty.');
        }
        if ($ttl < 1) {
            throw new InvalidArgumentException(sprintf('The TTL of a partition index cache must be at least 1 second, %d given.', $ttl));
        }
        $this->prefix = CacheKeys::prefix($prefix);
        $this->guard = new GuardedCache($cache, $reporter);
        $this->repositoryHash = CacheKeys::shortHash($repositoryId);
        $this->codec = $codec ?? new SpecificationCodec();
        $this->installed = self::installedVersions();
    }

    /** The name of the partitioned repository. */
    public function getRepositoryId(): string
    {
        return $this->repositoryId;
    }

    /** Seconds an entry lives. */
    public function getTtl(): int
    {
        return $this->ttl;
    }

    /**
     * The fingerprint of the direct partitions of a node, in attachment order; null when one of their
     * specifications cannot be written by the codec (the node is not cached).
     *
     * @param list<ISpecification|null> $specifications
     */
    public function fingerprint(array $specifications): ?string
    {
        $documents = [$this->installed];
        foreach ($specifications as $specification) {
            try {
                $documents[] = $specification === null ? null : $this->codec->toJson($specification);
            } catch (Throwable) {
                $this->uncacheable++;

                return null;
            }
        }

        return CacheKeys::hash($documents);
    }

    /** The key of a fingerprint. */
    public function key(string $fingerprint): string
    {
        return CacheKeys::key($this->prefix, 'dag', $this->repositoryHash, $fingerprint, self::FORMAT);
    }

    /**
     * The clusters stored for a fingerprint: one integer per partition, numbered from 0 in order of first
     * appearance; null on a miss, a failure or an entry of another shape (reported).
     *
     * @return list<int>|null
     */
    public function load(string $fingerprint, int $partitions): ?array
    {
        $key = $this->key($fingerprint);
        [$state, $value] = $this->guard->get($key);
        if ($state === GuardedCache::HIT) {
            if (self::isClustering($value, $partitions)) {
                $this->hits++;

                return $value;
            }
            $this->guard->fail('read', $key, sprintf('the entry is not a clustering of %d partitions', $partitions));
        }
        $this->misses++;

        return null;
    }

    /**
     * Stores the clusters of a fingerprint (renumbered from 0 in order of first appearance).
     *
     * @param list<int> $clusters One cluster id per partition, in attachment order
     * @return bool False when the cache failed (reported)
     */
    public function store(string $fingerprint, array $clusters): bool
    {
        $numbers = [];
        $normalized = [];
        foreach ($clusters as $cluster) {
            $numbers[$cluster] ??= count($numbers);
            $normalized[] = $numbers[$cluster];
        }

        return $this->guard->set($this->key($fingerprint), $normalized, $this->ttl);
    }

    /**
     * What this instance did: clusters read from the cache, built after a miss, and nodes not cached because
     * a specification could not be written.
     *
     * @return array{hits: int, misses: int, uncacheable: int}
     */
    public function statistics(): array
    {
        return ['hits' => $this->hits, 'misses' => $this->misses, 'uncacheable' => $this->uncacheable];
    }

    /** Reports again, once per key, the cache failures already reported by this instance (for long-running workers). */
    public function forgetReportedFailures(): void
    {
        $this->guard->forgetReportedFailures();
    }

    /** A list of one cluster number per partition, numbered from 0 in order of first appearance. */
    private static function isClustering(mixed $value, int $partitions): bool
    {
        if (!is_array($value) || !array_is_list($value) || count($value) !== $partitions) {
            return false;
        }
        $next = 0;
        foreach ($value as $cluster) {
            if (!is_int($cluster) || $cluster < 0 || $cluster > $next) {
                return false;
            }
            if ($cluster === $next) {
                $next++;
            }
        }

        return true;
    }

    /** The installed versions of this library and of the application, as Composer recorded them ('' without Composer). */
    private static function installedVersions(): string
    {
        $versions = [];
        if (class_exists(InstalledVersions::class)) {
            $root = InstalledVersions::getRootPackage();
            $versions[] = $root['name'] . '@' . $root['pretty_version'] . '#' . ($root['reference'] ?? '');
            if (InstalledVersions::isInstalled('antevemus/aspecification')) {
                $versions[] = 'antevemus/aspecification@' . InstalledVersions::getPrettyVersion('antevemus/aspecification')
                    . '#' . InstalledVersions::getReference('antevemus/aspecification');
            }
        }

        return implode(' ', $versions);
    }
}
