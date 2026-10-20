<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

/**
 * IDataSource - A Place External Data Comes From
 *
 * A source turns a DataRequest into a value: a bureau score, a fraud flag, a balance. It is
 * registered by name in a DataSourceRegistry and named by the external data leaves
 * (`Spec::external('credit.score', ...)`). The value it returns is what the inner specification of
 * the leaf evaluates.
 *
 * Two forms ship with the core: HttpDataSource (IHttpDataSource, fetched concurrently through
 * `curl_multi` by the DataResolver) and CallableDataSource (a synchronous callable, run one after
 * the other inside a resolution stage). Since 2.6.0 a third kind is a hook for packages outside the
 * core: an IDeferredDataSource is fetched inside a stage by its IStageTransport, together with the
 * other requests of that transport (a satellite package fetches them concurrently under an event
 * loop). Any other implementation is fetched synchronously through fetch(), like CallableDataSource.
 *
 * Contract:
 * - fetch() is synchronous and returns the value, or throws (any Throwable) when it cannot;
 * - getCost() orders the stages of the tiered policy: lower is cheaper, fetched first;
 * - getTtl() is the time, in seconds, a fetched value may be kept by an IDataCache between
 *   resolutions; null or 0 disables caching for the source. Failures are never cached.
 *
 * @version    2.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IDataSource
{
    /**
     * Fetches the value of one request, synchronously.
     *
     * @param DataRequest $request Request built by an external data leaf
     * @return mixed The value the inner specification of the leaf evaluates
     * @throws \Throwable When the value cannot be fetched
     */
    public function fetch(DataRequest $request): mixed;

    /**
     * Relative cost of a fetch (lower is cheaper). The tiered policy fetches the cheapest first.
     */
    public function getCost(): int;

    /**
     * Seconds a fetched value may be cached between resolutions; null or 0 disables caching.
     */
    public function getTtl(): ?int;
}
