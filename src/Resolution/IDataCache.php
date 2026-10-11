<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

/**
 * IDataCache - Optional Cache of Fetched Values Between Resolutions
 *
 * A port only (forward 026 D9): the core ships InMemoryDataCache; an adapter over PSR-16 belongs to
 * roadmap milestone 16. Keys are canonical request keys (DataRequest::getKey()), values are what the
 * source returned, and the time to live is the one of the source (IDataSource::getTtl()). The
 * resolver only stores successful values of sources with a positive TTL; failures are never cached.
 *
 * Within one resolution, deduplication does not depend on the cache: a key is fetched once.
 *
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IDataCache
{
    /**
     * True when a value that has not expired is stored under the key.
     */
    public function has(string $key): bool;

    /**
     * The value stored under the key, or null when there is none (check has() first: null may be a value).
     */
    public function get(string $key): mixed;

    /**
     * Stores a value for at most `$ttlSeconds` seconds.
     */
    public function set(string $key, mixed $value, int $ttlSeconds): void;
}
