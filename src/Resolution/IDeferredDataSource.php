<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

/**
 * IDeferredDataSource - A Data Source Fetched by a Stage Transport
 *
 * The non-HTTP counterpart of IHttpDataSource (2.6.0, forward 045). Inside a resolution scope the
 * DataResolver never calls fetch() on a deferred source: it hands every pending request of the stage
 * whose source shares the same transport (getTransport()) to one IStageTransport::fetchAll() call, after
 * the HTTP batch of the stage and before the other sources. Under the sequential policy (or for a leaf
 * the resolver could not see) the leaf reaches the transport with a single request. fetch() (inherited
 * from IDataSource, mandatory) is the synchronous path used outside a resolution scope.
 *
 * The core ships no implementation: a satellite package (roadmap milestone 15b) provides sources and a
 * transport that fetch concurrently under an event loop. Cost,
 * TTL, deduplication, the tiered policy and the time limit apply as to any other source. A source that
 * is also an IHttpDataSource is fetched as an HTTP source. Registering a deferred source needs no
 * extension.
 *
 * @version    2.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IDeferredDataSource extends IDataSource
{
    /** The transport that fetches this source inside a resolution stage (one transport may serve many sources). */
    public function getTransport(): IStageTransport;
}
