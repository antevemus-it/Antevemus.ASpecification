# Asynchronous Data Sources

### 22.2. Asynchronous Data Sources (Module 36)

Some providers answer in two steps: a credit bureau creates an analysis and returns a ticket, and the result is read later. `AsyncDataSource` submits a request **once**, keeps its ticket and polls it at each resolution; until the result is ready the leaf answers a typed evaluation error (`DataPendingException`) that never approves nor refuses. Nothing in the library waits or loops: the next request of the user, or the next run of a job, asks again.

```php
use Antevemus\ASpecification\Resolution\AsyncDataSource;
use Antevemus\ASpecification\Resolution\AsyncPoll;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\InMemoryAsyncTicketStore;
use Antevemus\ASpecification\Spec;

// $bureau: the client of a provider that creates an analysis now and answers it later
$sources = (new DataSourceRegistry())->register('bureau.score', new AsyncDataSource(
    submit: fn(DataRequest $request, string $key): string => $bureau->createAnalysis($request->get('cpf'), $key),
    poll: fn(string $ticket): AsyncPoll => $bureau->isDone($ticket) ? AsyncPoll::ready($bureau->score($ticket)) : AsyncPoll::pending(retryAfter: 30),
    tickets: new InMemoryAsyncTicketStore(),         // in production, a table of the application (CallableAsyncTicketStore)
    reconcile: fn(DataRequest $request): ?string => $bureau->findAnalysis($request->get('cpf')),
));
$scoreOk = Spec::external('bureau.score', ['cpf' => 'cpf'], Spec::greaterThanOrEqualTo(700))->withCode('SCORE_LOW');
$resolver = new DataResolver($sources);
$tenant = (object) ['cpf' => '111.444.777-35'];

$first = $resolver->evaluate($scoreOk, $tenant);
$first->isPending();                                             // true: submitted, not ready: neither approved nor refused
$resolver->evaluate(Spec::not($scoreOk), $tenant)->isSatisfied;  // false: NOT does not invert a pending datum
// ... the next request, or the next run of the job, once the bureau is done
$resolver->evaluate($scoreOk, $tenant)->isSatisfied;            // true (score 720)
$bureau->created;                                                // 1: the analysis was created once
```

- **Pending is an error, not a fourth truth value.** `DataPendingException` (`getSource()`, `getTicket()`, `getRetryAfter()`, informative) becomes the evaluation error of the leaf: AND propagates it, NOT does not invert it, OR keeps its rule (an error on the left ends the disjunction). `SpecificationResult::isPending()`, `RuleEngineVerdict::isPending()` (a pending verdict still blocks) and `ResolvedData::getPendingRequests()` tell it apart from a real failure. When an evaluation met a real error before the pending datum, the result keeps the first one, so it is not pending.
- **At most one submission per request.** The key is `DataRequest::getKey()` (the source and its parameters), also handed to `submit` as an idempotency key for a provider that accepts one. The store (`IAsyncTicketStore`: `get`, `markSubmitting`, a claim that succeeds once, `storeTicket`, `clear`) marks the key before sending; a key found "submitting" without a ticket (the process stopped between sending and storing) is never sent again: `reconcile` looks it up at the provider, and without it the fetch fails closed (`DataFetchException`, "submission outcome unknown") until the key is cleared. The ticket is kept after the result is read, so asking again reads the same result; `AsyncPoll::failed()` is a terminal failure, sent again only with `resubmitAfterFailure: true`. A ready value goes to the cache for the TTL of the source; a pending one never does. `CallableAsyncTicketStore` maps the store onto a table of the application with four closures; there is no table of the library.
