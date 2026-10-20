<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Resolution\Exceptions\DataFetchException;
use Antevemus\ASpecification\Resolution\Exceptions\DataPendingException;
use Antevemus\ASpecification\Resolution\Exceptions\DataResolutionException;
use Antevemus\ASpecification\Resolution\Exceptions\DataSourceNotFoundException;
use Antevemus\ASpecification\Results\SpecificationResult;
use Closure;
use Throwable;

/**
 * ResolvedData - The Data of One Resolution, by Request
 *
 * Returned by DataResolver::resolve(). Holds, per canonical request key, the value fetched, the
 * failure, or the mark "skipped" (the tiered policy stopped because the verdict was decided).
 * Evaluating through it (evaluate(), isSatisfiedBy(), run()) opens a ResolutionScope, so the
 * external data leaves read from it instead of calling their sources.
 *
 * A leaf whose request is not here (the sequential policy, or a leaf the resolver could not see,
 * for instance inside a node that does not expose its operands) fetches it when the evaluation
 * reaches it, through the same registry, cache and time limit, and the value is kept here: a
 * request is fetched at most once per resolution.
 *
 * A request whose asynchronous source has no result yet (DataPendingException, 2.5.0) is a failed request
 * of this resolution, listed by getPendingRequests() too; it is never cached, so the next resolution asks
 * again.
 *
 * A stage fetches its requests in three groups, in this order (2.6.0, forward 045): the HTTP sources together
 * in one `curl_multi` batch; then the deferred sources (IDeferredDataSource), one IStageTransport::fetchAll()
 * call per transport with every request of that transport; then the other sources, one after the other. The
 * groups are serialized: HTTP and transports never interleave. A stage without deferred sources runs exactly
 * as before 2.6.0.
 *
 * @version    2.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class ResolvedData
{
    private const PENDING = 0;
    private const RESOLVED = 1;
    private const FAILED = 2;
    private const SKIPPED = 3;

    /** @var array<string, array{request: DataRequest, state: int, value: mixed, error: ?Throwable}> */
    private array $entries = [];

    /** @var array<int, bool|null> neutral outcome of a skipped leaf, by leaf object id */
    private array $neutral = [];

    private int $stageCount = 0;
    private int $fetchCount = 0;
    private int $cacheHitCount = 0;

    /**
     * @internal Built by DataResolver::resolve().
     *
     * @param DataSourceRegistry $registry Sources by name
     * @param ResolutionPolicy $policy Policy of the resolution
     * @param IDataCache|null $cache Cache between resolutions
     * @param float|null $deadline Monotonic instant (CurlMultiTransport::now()) of the resolution time limit
     */
    public function __construct(
        private readonly DataSourceRegistry $registry,
        private readonly ResolutionPolicy $policy,
        private readonly ?IDataCache $cache = null,
        private readonly ?float $deadline = null
    ) {
    }

    // ==========================================
    // 1. Evaluation inside the scope
    // ==========================================

    /**
     * Evaluates the specification with this data as the resolution scope.
     */
    public function evaluate(ISpecification $specification, mixed $candidate): SpecificationResult
    {
        return $this->run(static fn(): SpecificationResult => $specification->evaluate($candidate));
    }

    /**
     * isSatisfiedBy() with this data as the resolution scope (an evaluation error is thrown, as usual).
     */
    public function isSatisfiedBy(ISpecification $specification, mixed $candidate): bool
    {
        return $this->run(static fn(): bool => $specification->isSatisfiedBy($candidate));
    }

    /**
     * Runs any code with this data as the resolution scope.
     *
     * @template R
     * @param Closure(): R $callback
     * @return R
     */
    public function run(Closure $callback): mixed
    {
        return ResolutionScope::run($this, $callback);
    }

    // ==========================================
    // 2. Inspection
    // ==========================================

    /** Policy of the resolution. */
    public function getPolicy(): ResolutionPolicy
    {
        return $this->policy;
    }

    /**
     * Every distinct request known to this resolution (collected from the tree or fetched on demand).
     *
     * @return list<DataRequest>
     */
    public function getRequests(): array
    {
        return array_values(array_map(static fn(array $e): DataRequest => $e['request'], $this->entries));
    }

    /**
     * Requests skipped by the tiered policy (their sources were not called).
     *
     * @return list<DataRequest>
     */
    public function getSkippedRequests(): array
    {
        return $this->requestsIn(self::SKIPPED);
    }

    /**
     * Requests whose fetch failed.
     *
     * @return list<DataRequest>
     */
    public function getFailedRequests(): array
    {
        return $this->requestsIn(self::FAILED);
    }

    /**
     * Requests whose source accepted them and has no result yet (2.5.0, forward 048, D25): failed with a
     * DataPendingException (or an exception whose previous one is). Fetched again by the next resolution.
     *
     * @return list<DataRequest>
     */
    public function getPendingRequests(): array
    {
        $requests = [];
        foreach ($this->entries as $entry) {
            if ($entry['state'] === self::FAILED && DataPendingException::isPendingThrowable($entry['error'])) {
                $requests[] = $entry['request'];
            }
        }

        return $requests;
    }

    /** True when the request was fetched successfully (or taken from the cache). */
    public function isResolved(DataRequest $request): bool
    {
        return ($this->entries[$request->getKey()]['state'] ?? null) === self::RESOLVED;
    }

    /** True when the tiered policy skipped the request. */
    public function isSkipped(DataRequest $request): bool
    {
        return ($this->entries[$request->getKey()]['state'] ?? null) === self::SKIPPED;
    }

    /**
     * Value of a resolved request.
     *
     * @throws Throwable The fetch failure, or DataResolutionException when the request is not resolved
     */
    public function get(DataRequest $request): mixed
    {
        $entry = $this->entries[$request->getKey()] ?? null;
        if ($entry !== null && $entry['state'] === self::RESOLVED) {
            return $entry['value'];
        }
        if ($entry !== null && $entry['state'] === self::FAILED && $entry['error'] !== null) {
            throw $entry['error'];
        }

        throw new DataResolutionException(sprintf('The request to data source "%s" is not resolved.', $request->source));
    }

    /** Failure of a request, or null. */
    public function getError(DataRequest $request): ?Throwable
    {
        return $this->entries[$request->getKey()]['error'] ?? null;
    }

    /**
     * Number of fetch stages run (a stage: the `curl_multi` batch, one fetchAll() per stage transport and the
     * other sources, one after the other; a stage answered only by the cache is not counted).
     */
    public function getStageCount(): int
    {
        return $this->stageCount;
    }

    /** Number of requests handed to the sources or their transports (cache hits excluded; each key at most once). */
    public function getFetchCount(): int
    {
        return $this->fetchCount;
    }

    /** Number of requests answered by the cache. */
    public function getCacheHitCount(): int
    {
        return $this->cacheHitCount;
    }

    // ==========================================
    // 3. Internal protocol (DataResolver, TreeAnalyzer, ExternalDataSpecification)
    // ==========================================

    /**
     * @internal Adds a request as pending, unless its key is already known.
     */
    public function add(DataRequest $request): void
    {
        $key = $request->getKey();
        if (!isset($this->entries[$key])) {
            $this->entries[$key] = ['request' => $request, 'state' => self::PENDING, 'value' => null, 'error' => null];
        }
    }

    /**
     * @internal True when the key holds a value (a failed, skipped or pending key does not).
     */
    public function isSettled(string $key): bool
    {
        return ($this->entries[$key]['state'] ?? null) === self::RESOLVED;
    }

    /**
     * @internal Pending keys among the given ones, grouped by the cost of their source, cheapest first.
     *
     * @param list<string> $keys
     * @return array<int, list<string>>
     */
    public function pendingByCost(array $keys): array
    {
        $tiers = [];
        foreach ($keys as $key) {
            $entry = $this->entries[$key] ?? null;
            if ($entry === null || $entry['state'] !== self::PENDING) {
                continue;
            }
            $source = $entry['request']->source;
            // An unknown source costs nothing to fail: first stage, no I/O.
            $cost = $this->registry->has($source) ? $this->registry->get($source)->getCost() : PHP_INT_MIN;
            $tiers[$cost][] = $key;
        }
        ksort($tiers, SORT_NUMERIC);

        return $tiers;
    }

    /**
     * @internal Pending keys among the given ones.
     *
     * @param list<string> $keys
     * @return list<string>
     */
    public function pendingAmong(array $keys): array
    {
        return array_values(array_filter(
            $keys,
            fn(string $key): bool => ($this->entries[$key]['state'] ?? null) === self::PENDING
        ));
    }

    /**
     * @internal Answers pending keys from the cache, without calling any source.
     *
     * @param list<string> $keys
     */
    public function loadFromCache(array $keys): void
    {
        foreach ($keys as $key) {
            $entry = $this->entries[$key] ?? null;
            if ($entry === null || $entry['state'] !== self::PENDING || !$this->registry->has($entry['request']->source)) {
                continue;
            }
            $this->tryCache($key, $this->registry->get($entry['request']->source));
        }
    }

    /**
     * @internal Marks pending keys as skipped, with the neutral outcome of each leaf position.
     *
     * @param list<string> $keys
     * @param array<int, bool|null> $neutral Neutral outcome by leaf object id (null: unknown position)
     */
    public function skip(array $keys, array $neutral): void
    {
        foreach ($keys as $key) {
            if (($this->entries[$key]['state'] ?? null) === self::PENDING) {
                $this->entries[$key]['state'] = self::SKIPPED;
            }
        }
        $this->neutral = $neutral + $this->neutral;
    }

    /**
     * @internal Runs one fetch stage: every pending key given, HTTP sources together in one
     * `curl_multi`, then the deferred sources (one fetchAll() per stage transport, transports in the order
     * their first key was met), then the other sources one after the other. Each key ends resolved or failed.
     *
     * @param list<string> $keys
     */
    public function fetch(array $keys): void
    {
        $calls = [];
        $httpSources = [];
        /** @var array<int, array{transport: IStageTransport, pending: array<string, array{IDeferredDataSource, DataRequest}>}> $deferred */
        $deferred = [];
        $others = [];

        foreach ($keys as $key) {
            $entry = $this->entries[$key] ?? null;
            if ($entry === null || $entry['state'] !== self::PENDING) {
                continue;
            }
            $request = $entry['request'];
            if (!$this->registry->has($request->source)) {
                $this->fail($key, new DataSourceNotFoundException($request->source));
                continue;
            }
            $source = $this->registry->get($request->source);
            if ($this->tryCache($key, $source)) {
                continue;
            }
            if ($source instanceof IHttpDataSource) {
                try {
                    $calls[$key] = [$source->createCall($request), $request->source];
                    $httpSources[$key] = $source;
                } catch (Throwable $e) {
                    $this->fail($key, $e);
                }
                continue;
            }
            if ($source instanceof IDeferredDataSource) {
                try {
                    // The group keeps the transport alive, so its object id is not reused during the stage
                    $transport = $source->getTransport();
                    $deferred[spl_object_id($transport)]['transport'] = $transport;
                    $deferred[spl_object_id($transport)]['pending'][$key] = [$source, $request];
                } catch (Throwable $e) {
                    $this->fail($key, $e);
                }
                continue;
            }
            $others[$key] = $source;
        }

        if ($calls === [] && $deferred === [] && $others === []) {
            return;
        }
        $this->stageCount++;

        if ($calls !== []) {
            $this->fetchCount += count($calls);
            try {
                $responses = CurlMultiTransport::execute($calls, $this->deadline);
            } catch (Throwable $e) {
                $responses = array_fill_keys(array_keys($calls), $e);
            }
            foreach ($responses as $key => $response) {
                $key = (string) $key;
                if ($response instanceof Throwable) {
                    $this->fail($key, $response);
                    continue;
                }
                try {
                    $value = $httpSources[$key]->parseResponse(
                        $this->entries[$key]['request'],
                        $response['status'],
                        $response['headers'],
                        $response['body']
                    );
                    $this->succeed($key, $value, $httpSources[$key]);
                } catch (Throwable $e) {
                    $this->fail($key, $e);
                }
            }
        }

        foreach ($deferred as $group) {
            $this->fetchThroughTransport($group['transport'], $group['pending']);
        }

        foreach ($others as $key => $source) {
            $key = (string) $key;
            $request = $this->entries[$key]['request'];
            if ($this->deadline !== null && CurlMultiTransport::now() >= $this->deadline) {
                $this->fail($key, DataFetchException::resolutionTimeout($request->source));
                continue;
            }
            $this->fetchCount++;
            try {
                $this->succeed($key, $source->fetch($request), $source);
            } catch (Throwable $e) {
                $this->fail($key, $e);
            }
        }
    }

    /**
     * @internal The data of one leaf: its value, a SkippedData marker for a request the tiered policy
     * skipped, or the fetch failure thrown. A request not fetched yet is fetched now.
     *
     * @throws Throwable The fetch failure
     */
    public function valueFor(ExternalDataSpecification $leaf, DataRequest $request): mixed
    {
        $key = $request->getKey();
        $this->add($request);

        if ($this->entries[$key]['state'] === self::SKIPPED) {
            $neutral = $this->neutral[spl_object_id($leaf)] ?? null;
            if ($neutral !== null) {
                return new SkippedData($neutral);
            }
            // A skipped request reached from a position without a known neutral outcome: fetch it.
            $this->entries[$key]['state'] = self::PENDING;
        }
        if ($this->entries[$key]['state'] === self::PENDING) {
            $this->fetch([$key]);
        }

        $entry = $this->entries[$key];
        if ($entry['state'] === self::RESOLVED) {
            return $entry['value'];
        }

        throw $entry['error'] ?? new DataResolutionException(sprintf(
            'The request to data source "%s" could not be resolved.',
            $request->source
        ));
    }

    /**
     * The deferred keys of one transport: failed with the resolution time limit when it has passed, otherwise
     * handed to fetchAll() together; a key it did not answer fails, a crash of the call fails all of them.
     *
     * @param array<string, array{IDeferredDataSource, DataRequest}> $pending
     */
    private function fetchThroughTransport(IStageTransport $transport, array $pending): void
    {
        if ($this->deadline !== null && CurlMultiTransport::now() >= $this->deadline) {
            foreach ($pending as $key => [, $request]) {
                $this->fail((string) $key, DataFetchException::resolutionTimeout($request->source));
            }
            return;
        }
        $this->fetchCount += count($pending);
        try {
            $results = $transport->fetchAll($pending, $this->deadline);
        } catch (Throwable $e) {
            $results = array_fill_keys(array_keys($pending), $e);
        }
        foreach ($pending as $key => [$source, $request]) {
            $key = (string) $key;
            if (!array_key_exists($key, $results)) {
                $this->fail($key, DataFetchException::noTransportResult($request->source));
                continue;
            }
            if ($results[$key] instanceof Throwable) {
                $this->fail($key, $results[$key]);
                continue;
            }
            $this->succeed($key, $results[$key], $source);
        }
    }

    /**
     * @return list<DataRequest>
     */
    private function requestsIn(int $state): array
    {
        $requests = [];
        foreach ($this->entries as $entry) {
            if ($entry['state'] === $state) {
                $requests[] = $entry['request'];
            }
        }

        return $requests;
    }

    private function tryCache(string $key, IDataSource $source): bool
    {
        if ($this->cache === null || ($source->getTtl() ?? 0) <= 0 || !$this->cache->has($key)) {
            return false;
        }
        $this->entries[$key]['state'] = self::RESOLVED;
        $this->entries[$key]['value'] = $this->cache->get($key);
        $this->cacheHitCount++;

        return true;
    }

    private function succeed(string $key, mixed $value, IDataSource $source): void
    {
        $this->entries[$key]['state'] = self::RESOLVED;
        $this->entries[$key]['value'] = $value;
        $ttl = $source->getTtl() ?? 0;
        if ($this->cache !== null && $ttl > 0) {
            $this->cache->set($key, $value, $ttl);
        }
    }

    private function fail(string $key, Throwable $error): void
    {
        $this->entries[$key]['state'] = self::FAILED;
        $this->entries[$key]['error'] = $error;
    }
}
