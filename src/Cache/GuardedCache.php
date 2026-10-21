<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Cache;

use Antevemus\ASpecification\Cache\Exceptions\CacheFailureException;
use Antevemus\ASpecification\FeatureFlags\IFeatureFlagErrorReporter;
use Psr\SimpleCache\CacheInterface;
use stdClass;
use Throwable;

/**
 * GuardedCache - A PSR-16 Cache Whose Failures Are Misses, Reported Once per Key
 *
 * The shared core of the caching decorators (internal). Every call to the cache is guarded: an
 * exception of the cache, or a `false` from `set()`/`deleteMultiple()`, becomes a miss (or an
 * invalidation that did not happen), and a CacheFailureException goes to the error reporter, once per
 * key for the life of this object: an instance lives one request under PHP-FPM, so a cache that is down
 * is reported once per key per request, not once per read. A long-running worker calls
 * forgetReportedFailures() between jobs. An exception of the reporter itself is ignored.
 *
 * @internal
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Cache
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class GuardedCache
{
    /** get(): the key holds a value. */
    public const HIT = 1;

    /** get(): the key holds nothing. */
    public const MISS = 0;

    /** get(): the cache failed (reported); read it as a miss, and do not expect a write to succeed. */
    public const FAILED = -1;

    /** @var array<string, CacheFailureException> The first failure reported for each key */
    private array $reported = [];

    private readonly stdClass $miss;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly ?IFeatureFlagErrorReporter $reporter = null
    ) {
        $this->miss = new stdClass();
    }

    /** The PSR-16 cache. */
    public function cache(): CacheInterface
    {
        return $this->cache;
    }

    /**
     * Reads a key: `[HIT, value]`, `[MISS, null]` or `[FAILED, null]` (reported).
     *
     * @param string|null $code Flag code handed to the reporter (null outside the flags)
     * @return array{0: int, 1: mixed}
     */
    public function get(string $key, ?string $code = null): array
    {
        try {
            $value = $this->cache->get($key, $this->miss);
        } catch (Throwable $e) {
            $this->report($code, new CacheFailureException('get', $key, $e->getMessage(), $e));

            return [self::FAILED, null];
        }

        return $value === $this->miss ? [self::MISS, null] : [self::HIT, $value];
    }

    /**
     * Writes a key; false (reported) when the cache threw or refused it.
     *
     * @param int|null $ttl Seconds (null = the cache's default)
     */
    public function set(string $key, mixed $value, ?int $ttl, ?string $code = null): bool
    {
        try {
            if ($this->cache->set($key, $value, $ttl)) {
                return true;
            }
            $failure = new CacheFailureException('set', $key, 'the cache refused the value');
        } catch (Throwable $e) {
            $failure = new CacheFailureException('set', $key, $e->getMessage(), $e);
        }
        $this->report($code, $failure);

        return false;
    }

    /**
     * Deletes keys in one call; false (reported under the first key) when the cache threw or answered false.
     *
     * @param list<string> $keys
     */
    public function delete(array $keys, ?string $code = null): bool
    {
        if ($keys === []) {
            return true;
        }
        try {
            if ($this->cache->deleteMultiple($keys)) {
                return true;
            }
            $failure = new CacheFailureException('delete', $keys[0], 'the cache did not delete the keys');
        } catch (Throwable $e) {
            $failure = new CacheFailureException('delete', $keys[0], $e->getMessage(), $e);
        }
        $this->report($code, $failure);

        return false;
    }

    /**
     * Reports a failure that did not come from a call to the cache (an entry of an unexpected shape, a
     * catalog version that could not be read), once per key.
     */
    public function fail(string $operation, string $key, string $detail, ?Throwable $previous = null, ?string $code = null): void
    {
        $this->report($code, new CacheFailureException($operation, $key, $detail, $previous));
    }

    /** Forgets which keys were reported, so that the next failure of each is reported again. */
    public function forgetReportedFailures(): void
    {
        $this->reported = [];
    }

    private function report(?string $code, CacheFailureException $failure): void
    {
        if (isset($this->reported[$failure->key])) {
            return;
        }
        $this->reported[$failure->key] = $failure;
        if ($this->reporter !== null) {
            try {
                $this->reporter->report($code, $failure);
            } catch (Throwable) {
                // A broken reporter never changes an answer (IFeatureFlagErrorReporter)
            }
        }
    }
}
