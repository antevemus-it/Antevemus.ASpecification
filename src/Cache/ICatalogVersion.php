<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Cache;

/**
 * ICatalogVersion - A Cheap Token That Changes When the Rule Catalog Changes
 *
 * CachedRuleCatalog reads it on every read of the catalog (one light query instead of the reads it
 * saves) and puts it in the cache key: when the catalog changes, the token changes and every node reads
 * the catalog again, without waiting for the TTL nor for an explicit invalidate(). PdoCatalogVersion
 * builds it from row counts and the largest value of a version or update-stamp column; an application
 * that keeps a version of its own (a counter bumped by the screen that edits the rules) implements this
 * interface over it. A token that cannot be read (an exception) makes the read go to the catalog, as
 * without a cache, and the failure is reported.
 *
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Cache
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ICatalogVersion
{
    /**
     * The current version of the catalog: any text, equal while the catalog is unchanged.
     *
     * @throws \Throwable When it cannot be read
     */
    public function catalogVersion(): string;
}
