<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

/**
 * IStageTransport - Fetches the Deferred Sources of One Resolution Stage Together
 *
 * The hook through which a package outside the core fetches non-HTTP data concurrently (2.6.0, forward
 * 045): a satellite package (roadmap milestone 15b) implements it with an event loop; the core only
 * calls it and has no dependency of its own. One transport may serve many sources
 * (IDeferredDataSource::getTransport()); the resolver groups the pending requests of a stage by transport
 * instance and calls fetchAll() once per transport, with every request of that transport.
 *
 * Inside a stage the resolver runs, in this order: the HTTP batch (`curl_multi`), then each transport (in
 * the order its first request was met), then the other sources one after the other. The groups are
 * serialized, never interleaved: a transport starts after the HTTP batch has ended and ends before the
 * next transport starts.
 *
 * Contract of fetchAll():
 * - it answers by the keys it received: the value of the request, or the Throwable that failed it (a
 *   failure of one request is returned, not thrown, so it fails that request only). Any value that is not
 *   a Throwable is a success. A key it does not answer fails with DataFetchException::noTransportResult();
 *   a key it answers that was not asked is ignored;
 * - a Throwable thrown by fetchAll() itself fails every request it received (and only those: the other
 *   transports and sources of the stage are fetched as usual);
 * - $deadline is the monotonic instant (comparable to CurlMultiTransport::now()) of the time limit of the
 *   whole resolution, or null. A transport should stop waiting at it and answer the unfinished requests
 *   with DataFetchException::resolutionTimeout(). The resolver does not call a transport whose deadline
 *   has already passed (its requests fail with resolutionTimeout());
 * - a value returned is cached by the resolver with the TTL of its source, like any value; a failure is
 *   never cached.
 *
 * @version    2.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IStageTransport
{
    /**
     * Fetches every pending request of one stage that belongs to this transport, together.
     *
     * @param array<string, array{IDeferredDataSource, DataRequest}> $pending By request key (DataRequest::getKey())
     * @param float|null $deadline Monotonic instant (comparable to CurlMultiTransport::now()) of the resolution time limit, or null
     * @return array<string, mixed|\Throwable> By the same keys: the value, or the Throwable that failed the request
     */
    public function fetchAll(array $pending, ?float $deadline): array;
}
