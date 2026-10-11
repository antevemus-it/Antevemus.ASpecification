<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

/**
 * ResolutionMode - How a DataResolver Fetches the Data of a Tree
 *
 * - CONCURRENT: every request of the tree in one stage (one `curl_multi` for the HTTP sources).
 * - TIERED (default): stages by cost, cheapest first; after each stage the tree is evaluated in
 *   three-valued logic and the resolution stops as soon as the verdict cannot change.
 * - SEQUENTIAL: nothing is fetched beforehand; each leaf fetches its data when the evaluation
 *   reaches it (the behaviour outside a resolution scope, with deduplication and the cache).
 *
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum ResolutionMode: string
{
    case CONCURRENT = 'concurrent';
    case TIERED = 'tiered';
    case SEQUENTIAL = 'sequential';
}
