# Concurrent Data Resolution for I/O-Bound Specifications

### 16. Concurrent Data Resolution for I/O-Bound Specifications (Module 21)

A rule that depends on data held elsewhere (a credit bureau score, a fraud check, the balance in the core banking) does not call the service. `Spec::external()` **declares** the data it needs: a source name, parameters read from the candidate (dot paths), and an ordinary specification that decides on the value, with its own `because()`/`withCode()`. A `DataResolver` collects the declarations of the whole tree, fetches the data concurrently (`curl_multi`, no package dependency, works under PHP-FPM) and only then evaluates the tree, synchronously, as every specification is evaluated. Sources are registered by name: `HttpDataSource` (fixed base URL, a path template with `{placeholders}`, headers, its own time limit, a cost and a cache TTL) and `CallableDataSource` (any synchronous callable: a repository, an SDK, a gRPC client).

```php
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\HttpDataSource;
use Antevemus\ASpecification\Resolution\ResolutionPolicy;
use Antevemus\ASpecification\Spec;

$serasaUrl = 'https://bureau.example.com';         // owned by the source, never by the candidate
$fraudUrl  = 'https://fraud.example.com';
$bankUrl   = 'https://core-banking.example.com';

$registry = DataSourceRegistry::default()
    ->register('serasa.score', new HttpDataSource(
        baseUrl: $serasaUrl,
        path: '/serasa/score/{document}',
        parse: fn(int $status, array $headers, string $body): int => json_decode($body, true)['score'],
        headers: ['Authorization' => 'Bearer bureau-token'],
        timeout: 2.0,
        cost: 10,                                 // a paid bureau query
    ))
    ->register('fraud.flag', new HttpDataSource(
        baseUrl: $fraudUrl,
        path: '/fraud/check/{document}',
        parse: fn(int $status, array $headers, string $body): bool => json_decode($body, true)['flagged'],
        cost: 5,
    ))
    ->register('bank.balance', new HttpDataSource(
        baseUrl: $bankUrl,
        path: '/bank/balance/{account}',
        parse: fn(int $status, array $headers, string $body): float => json_decode($body, true)['balance'],
        cost: 1,                                  // our own core banking: cheap
    ));

// The rule declares the data it needs; it calls nothing
$credit = Spec::external('bank.balance', ['account' => 'account.number'],
        Spec::greaterThanOrEqualTo(1000)->because('Balance below 1,000.')->withCode('LOW_BALANCE'))
    ->and(Spec::external('fraud.flag', ['document' => 'cpf'], Spec::isFalse())->withCode('FRAUD_SUSPECTED'))
    ->and(Spec::external('serasa.score', ['document' => 'cpf'], Spec::greaterThanOrEqualTo(700))
        ->because('Credit score below 700.')->withCode('SCORE_LOW'));

$customer = (object) ['cpf' => '111.444.777-35', 'account' => (object) ['number' => 'A-1']];
$resolver = new DataResolver($registry);

// concurrent() (the default): the three calls at once, about the time of the slowest one
$resolver->evaluate($credit, $customer, ResolutionPolicy::concurrent())->isSatisfied;      // true

// tiered() (an explicit choice): cheapest first, stopping as soon as the verdict is decided
$poor = (object) ['cpf' => '222.333.444-05', 'account' => (object) ['number' => 'A-2']];
$data = $resolver->resolve($credit, $poor, ResolutionPolicy::tiered()); // balance 10: the AND is already false
count($data->getSkippedRequests());                   // 2: the fraud check and the bureau were not called
$data->evaluate($credit, $poor)->getCodes();          // ['LOW_BALANCE']

// Same verdict under every policy; concurrent() paid every source and reports every failure
$resolver->evaluate($credit, $poor, ResolutionPolicy::concurrent())->getCodes();          // ['LOW_BALANCE', 'SCORE_LOW']
$resolver->evaluate($credit, $poor, ResolutionPolicy::sequential())->isSatisfied;         // false
```

The policies: `concurrent()` (the default) fetches every request of the tree in one stage and reports every failure, as an evaluation outside the resolver does; `tiered()` (an explicit choice, when calls cost money) fetches by cost, cheapest first, and after each stage evaluates the tree in three-valued logic (true, false, unknown): once the verdict can no longer change (an `and` with a false operand, an `or` whose left operand is true) the more expensive sources are not called; `sequential()` fetches nothing beforehand and each leaf fetches when the evaluation reaches it. The verdict is the same under every policy and outside the resolver; under `tiered()`, a branch that was not fetched because it could not change the verdict reports no failure, so the list of failures can be shorter than with `concurrent()`. An `or` stops early only on its left operand: in the core an error on the left operand aborts the disjunction, so an unknown left operand cannot be skipped. Two leaves asking the same data (same source, same parameters) cause one call. A failed fetch is unknown, never a decision on its own.

```php
use Antevemus\ASpecification\Resolution\HttpDataSource;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;

// Outside the resolver the same rule still works: each leaf fetches its own data through the
// default registry, synchronously (one call after the other, no deduplication)
$credit->evaluate($customer)->isSatisfied;                          // true

// A source that fails is an evaluation error of its leaf, never a silent "false"
$limitsUrl = 'https://limits.example.com';
$registry->register('credit.limit', new HttpDataSource(baseUrl: $limitsUrl, path: '/limits/{document}', cost: 2));
$limit = Spec::external('credit.limit', ['document' => 'cpf'], Spec::greaterThan(0));

$result = $resolver->evaluate($limit, $customer);                   // the service answers 503
$result->isError;                                                   // true
$result->failures[0]->message;                                      // 'The rule could not be evaluated.'
$result->exception->getStatusCode();                                // 503, for the log (the body is never kept)

// The visitors refuse the leaf: the data only exists when the tree is evaluated in PHP
try {
    $credit->toSql('pgsql');
} catch (NonTranslatableSpecificationException $e) {
    // filter in SQL with the other rules, evaluate the external part in PHP
}
```

Errors follow the 1.8.0 rule: a fetch that fails (an HTTP status outside 2xx, a transport error, the time limit of the source or of the whole resolution with `ResolutionPolicy::tiered(timeout: 3.0)`, a parser that throws, an unknown source) is an evaluation error of the leaf: a failure with the generic message, `evaluation_error = true`, and a `DataFetchException` in `$result->exception` with the status code, never the response body. There is no retry. A parameter value is percent-encoded into the URL template, so `/`, `?`, `#`, `..` or a whole URL in a document number cannot change the host or the path of the call; redirects are not followed. A cache between resolutions is optional (`new DataResolver($registry, new InMemoryDataCache())`, with the TTL of each source; failures are never cached). `ext-curl` is a `suggest`: without it an `HttpDataSource` is refused when registered and a `CallableDataSource` keeps working. The SQL, TCriteria and ALinq visitors refuse the leaf with their "non translatable" exception. State machine guards stay pure: a use case resolves the data before sending the event. Concurrency for non-HTTP sources (Fibers/Revolt) is a planned satellite package.

Since v2.5.0 a source that submits a request and reads its result later (`AsyncDataSource`, with a store of tickets) answers a datum not ready yet as a typed evaluation error (`DataPendingException`, `isPending()`): never approved nor refused, submitted at most once per request, nothing waits; see example 22.2.
