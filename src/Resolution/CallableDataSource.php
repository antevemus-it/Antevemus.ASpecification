<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

use Antevemus\ASpecification\Resolution\Exceptions\DataFetchException;
use Closure;
use Throwable;

/**
 * CallableDataSource - A Data Source Backed by a Plain, Synchronous Callable
 *
 * For data that does not come over HTTP: a repository, a gRPC client, an SDK, a computation. The
 * callable receives the parameters of the request (sorted by name) and returns the value. In the
 * core it runs synchronously: inside a resolution stage the callable sources run one after the
 * other, after the HTTP calls of the stage (concurrency for non-HTTP sources is the job of the
 * Fibers/Revolt satellite package, roadmap milestone 15b).
 *
 * <code>
 * $balance = new CallableDataSource(fn(array $p): float => $accounts->balanceOf($p['account']), cost: 1);
 * </code>
 *
 * An exception of the callable becomes a DataFetchException (the original kept as previous).
 *
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class CallableDataSource implements IDataSource
{
    /**
     * @param Closure(array<string, string|int|float|bool>, DataRequest): mixed $callable Returns the value
     * @param int $cost Relative cost (lower is cheaper)
     * @param int|null $ttl Seconds a value may be cached between resolutions (null or 0: never)
     * @throws \InvalidArgumentException When the TTL is negative
     */
    public function __construct(
        private readonly Closure $callable,
        private readonly int $cost = 0,
        private readonly ?int $ttl = null
    ) {
        if ($ttl !== null && $ttl < 0) {
            throw new \InvalidArgumentException('The TTL of a data source cannot be negative.');
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws DataFetchException When the callable raises
     */
    public function fetch(DataRequest $request): mixed
    {
        try {
            return ($this->callable)($request->parameters, $request);
        } catch (Throwable $e) {
            throw DataFetchException::sourceFailed($request->source, $e);
        }
    }

    /** {@inheritdoc} */
    public function getCost(): int
    {
        return $this->cost;
    }

    /** {@inheritdoc} */
    public function getTtl(): ?int
    {
        return $this->ttl;
    }
}
