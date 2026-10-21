# Deferred Sources and Stage Transports

### 16.1. Deferred Sources and Stage Transports (Module 21)

The `DataResolver` fetches the HTTP sources of a stage together (`curl_multi`, see [example 16](concurrent.md)); a `CallableDataSource` runs synchronously, one after the other. Since v2.6.0 the core has a hook through which a package outside it fetches **non-HTTP** sources concurrently too: a source implements `IDeferredDataSource` and names its `IStageTransport`, and the resolver hands every pending request of that transport in the stage to one `fetchAll()` call. The satellite package [`antevemus/aspecification-revolt`](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt) (v1.0.0, 2026-10-20) implements both under the Revolt event loop (Fibers); the core itself has no dependency and runs no event loop.

The two interfaces, in `Antevemus\ASpecification\Resolution`:

- `IDeferredDataSource extends IDataSource`: `getTransport(): IStageTransport`, the transport that fetches it inside a stage (one transport may serve many sources). Its `fetch()` stays mandatory: it is the synchronous path outside a resolution scope (`$spec->evaluate($candidate)` without the resolver); inside a scope the resolver never calls it, as with an `IHttpDataSource`. A source that is also an `IHttpDataSource` is fetched as HTTP. Registering one needs no extension.
- `IStageTransport`: `fetchAll(array $pending, ?float $deadline): array`. `$pending` maps each request key (`DataRequest::getKey()`) to `[$source, $request]`; the answer maps the same keys to the value of the request or to the `Throwable` that failed it.

Inside one stage the resolver fetches in three groups, always in this order:

1. the HTTP sources, together, in one `curl_multi` batch (as before);
2. the deferred sources, grouped by transport instance: one `fetchAll()` per transport, transports in the order their first request was met, keys in the order they were pending;
3. the other sources, one after the other (as before).

The groups are serialized: a transport starts after the HTTP batch has ended and ends before the next transport starts; the concurrency is inside each group. Under `tiered()` each cost is a stage of its own, so deferred sources of different costs are fetched in different stages, cheapest first, and a decided verdict skips the expensive ones; under `sequential()` each leaf reaches its transport with a single request, when the evaluation reaches it. Two leaves asking the same data still cause one fetch.

**Time limit.** `$deadline` is the monotonic instant of the time limit of the resolution (`ResolutionPolicy::concurrent(timeout: 3.0)`), comparable to `CurlMultiTransport::now()`, or `null` without a limit. A transport should stop waiting at it and answer the unfinished requests with `DataFetchException::resolutionTimeout()`. When the limit has already passed before a transport's turn, the resolver does not call it: its requests fail with that same exception.

**Failures.** A `Throwable` in the answer fails that request only: the evaluation error of its leaf (generic message, `evaluation_error = true`, the `Throwable` in `$result->exception`), listed by `getFailedRequests()`. A key the transport does not answer fails with a `DataFetchException` ("the stage transport returned no result for data source ..."), never with the parameters; a key it answers that was not asked is ignored. A `Throwable` thrown by `fetchAll()` itself fails every request of that transport, and only those: the other transports and sources of the stage are fetched as usual.

**Cache.** A value is cached with the TTL of its source, like any value (`new DataResolver($registry, new InMemoryDataCache())`); a failure is never cached, so the next resolution asks the transport again. A request answered by the cache never reaches the transport. `getFetchCount()` counts the requests handed to the transports; a stage without deferred sources runs exactly as before 2.6.0.

The block below is a complete, tiny transport: it fetches the requests of its sources one after the other through their own `fetch()`. A real one (the satellite package) starts them all at once and waits for them together.

```php
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\IDeferredDataSource;
use Antevemus\ASpecification\Resolution\IStageTransport;
use Antevemus\ASpecification\Spec;

// The transport: every pending request of its sources in the stage, in one call
$transport = new class implements IStageTransport {
    public int $calls = 0;

    public function fetchAll(array $pending, ?float $deadline): array
    {
        $this->calls++;
        $results = [];
        foreach ($pending as $key => [$source, $request]) {
            try {
                $results[$key] = $source->fetch($request);
            } catch (\Throwable $e) {
                $results[$key] = $e;                     // fails this request only
            }
        }

        return $results;
    }
};

// A deferred source: its closure is the synchronous fetch(); inside a resolution the transport fetches it
$deferred = fn(\Closure $value): IDeferredDataSource => new class($value, $transport) implements IDeferredDataSource {
    public function __construct(private \Closure $value, private IStageTransport $transport)
    {
    }

    public function fetch(DataRequest $request): mixed
    {
        return ($this->value)($request->parameters);
    }

    public function getCost(): int
    {
        return 0;
    }

    public function getTtl(): ?int
    {
        return null;
    }

    public function getTransport(): IStageTransport
    {
        return $this->transport;
    }
};

$registry = (new DataSourceRegistry())
    ->register('ledger.balance', $deferred(fn(array $p): float => ['A-1' => 2500.0, 'A-2' => 10.0][$p['account']]))
    ->register('kyc.status', $deferred(fn(array $p): string => $p['document'] === '111.444.777-35' ? 'verified' : 'pending'))
    ->register('sanctions.hit', $deferred(fn(array $p): bool => throw new \RuntimeException('sanctions list offline')));

$rule = Spec::external('ledger.balance', ['account' => 'account'], Spec::greaterThanOrEqualTo(1000))
    ->and(Spec::external('kyc.status', ['document' => 'cpf'], Spec::equalTo('verified')));

$customer = (object) ['cpf' => '111.444.777-35', 'account' => 'A-1'];
$resolver = new DataResolver($registry);
$data = $resolver->resolve($rule, $customer);

$transport->calls;                                      // 1: both requests in one fetchAll()
$data->getStageCount();                                 // 1
$data->getFetchCount();                                 // 2
$data->evaluate($rule, $customer)->isSatisfied;         // true

// A request the transport answers with a Throwable is the evaluation error of its leaf
$screened = $rule->and(Spec::external('sanctions.hit', ['document' => 'cpf'], Spec::isFalse()));
$result = $resolver->evaluate($screened, $customer);
$result->isError;                                       // true
$result->exception->getMessage();                       // 'sanctions list offline'
$transport->calls;                                      // 2
```

## Other event loops

The hook knows no event loop: the core calls `fetchAll()` and waits for it to return, and never references an event loop library, its futures or Fibers. `antevemus/aspecification-revolt` is the implementation for Revolt and Amp: a `RevoltDataSource` and a `RevoltStageTransport` that run the requests of a stage in Fibers under the Revolt event loop. Its [Architecture](https://github.com/antevemus-it/Antevemus.ASpecificationRevolt/blob/main/docs/en/internals/architecture.md) page explains why it is built on Revolt and Amp, and what a satellite for another event loop (ReactPHP, ...) implements: a source that implements `IDeferredDataSource`, a transport that implements `IStageTransport`, and the failures of the core's own sources. Such a package would touch neither the core nor the Revolt one.
