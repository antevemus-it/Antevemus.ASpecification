<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

/**
 * IHttpDataSource - A Data Source Fetched Over HTTP
 *
 * The DataResolver does not call fetch() on an HTTP source: it asks the source for the HttpCall of
 * each request, runs every call of a stage together in one `curl_multi`, and hands each response
 * back to parseResponse(). fetch() (inherited from IDataSource) is the synchronous path used outside
 * a resolution scope.
 *
 * Security contract: the call is built by the source, not by the candidate. The base URL, the path
 * and the headers (authentication included) belong to the source; parameter values only fill the
 * placeholders of the template, percent-encoded, so they can never change the scheme, the host, the
 * port or the path segments of the call.
 *
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IHttpDataSource extends IDataSource
{
    /**
     * Builds the HTTP call of one request.
     *
     * @throws \Throwable When the call cannot be built (missing parameter, for instance)
     */
    public function createCall(DataRequest $request): HttpCall;

    /**
     * Turns a response into the value of the request.
     *
     * @param DataRequest $request Request the response answers
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers, names in lower case
     * @param string $body Response body
     * @return mixed The value
     * @throws \Throwable When the response is not a usable value (a status outside 2xx, for instance)
     */
    public function parseResponse(DataRequest $request, int $status, array $headers, string $body): mixed;
}
