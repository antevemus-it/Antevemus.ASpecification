<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

use Closure;

/**
 * InMemoryDataCache - Process-Local IDataCache With Expiration
 *
 * Keeps fetched values in an array for the time to live given by the source. Useful for a worker
 * that evaluates many candidates against slow sources, and in tests. The clock is injectable (a
 * closure returning the current time in seconds, `microtime(true)` by default).
 *
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryDataCache implements IDataCache, \Countable
{
    /** @var array<string, array{0: mixed, 1: float}> value and expiration instant, by key */
    private array $entries = [];

    /** @var Closure(): float */
    private readonly Closure $clock;

    /**
     * @param (Closure(): float)|null $clock Current time in seconds (default: microtime(true))
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /** {@inheritdoc} */
    public function has(string $key): bool
    {
        if (!array_key_exists($key, $this->entries)) {
            return false;
        }
        if ($this->entries[$key][1] <= ($this->clock)()) {
            unset($this->entries[$key]);
            return false;
        }

        return true;
    }

    /** {@inheritdoc} */
    public function get(string $key): mixed
    {
        return $this->has($key) ? $this->entries[$key][0] : null;
    }

    /** {@inheritdoc} */
    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            unset($this->entries[$key]);
            return;
        }
        $this->entries[$key] = [$value, ($this->clock)() + $ttlSeconds];
    }

    /**
     * Removes every entry.
     */
    public function clear(): void
    {
        $this->entries = [];
    }

    /**
     * Number of stored entries, expired ones included until they are looked up.
     */
    public function count(): int
    {
        return count($this->entries);
    }
}
