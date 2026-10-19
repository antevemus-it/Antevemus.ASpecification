# Use Cases With a Result Shared With the Machine

### 15. Use Cases With a Result Shared With the Machine (Module 19)

A use case is one class with one method, `execute(object $request): UseCaseResult`. `UseCaseRunner` runs it through the middlewares, validates the request first (its `#[ValidateRule]`/`#[AssertSpec]` attributes plus any specification registered for its class) and only an `ERROR` failure blocks: `WARNING` and `INFO` come back with the success. The status is a closed domain enum (`SUCCESS`, `CREATED`, `ACCEPTED`, `NO_CHANGE`, `INVALID`, `REJECTED`, `NOT_FOUND`, `CONFLICT`, `FORBIDDEN`, `FAILED`); success is derived from it alone, and HTTP exists only in one edge method, `toHttpStatus()`. An unhandled exception becomes `FAILED` with a generic message and goes to an optional `IUseCaseErrorReporter`: no message, class, file, line or stack trace ever reaches the result. No mediator, container or handler discovery is embedded.

#### 15.1 `IUseCase`, `UseCaseRunner` and `TransactionMiddleware`

```php
use Antevemus\ASpecification\Attributes\ValidateRule;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\UseCase\IUseCase;
use Antevemus\ASpecification\UseCase\TransactionMiddleware;
use Antevemus\ASpecification\UseCase\UseCaseResult;
use Antevemus\ASpecification\UseCase\UseCaseStatus;

final class RegisterTenantRequest
{
    public function __construct(
        #[ValidateRule('not_blank', code: 'NAME_REQUIRED', message: 'The tenant name is required.')]
        public readonly string $name,
        #[ValidateRule('<=', value: 5000, code: 'RENT_REVIEW', message: 'Rent above 5 000 goes to manual review.', severity: 'WARNING')]
        public readonly int $rent,
    ) {}
}

/** @implements IUseCase<RegisterTenantRequest, int> */
final class RegisterTenant implements IUseCase
{
    public function __construct(private PDO $pdo) {}

    public function execute(object $request): UseCaseResult
    {
        $this->pdo->prepare('INSERT INTO tenant (name, rent) VALUES (?, ?)')->execute([$request->name, $request->rent]);

        return UseCaseResult::created((int) $this->pdo->lastInsertId());
    }
}

$useCases = Spec::useCaseRunner(middlewares: [
    new TransactionMiddleware($pdo->beginTransaction(...), $pdo->commit(...), $pdo->rollBack(...)),
]);

$created = $useCases->run(new RegisterTenant($pdo), new RegisterTenantRequest('Alice', 6200));
$created->isSuccess();                       // true: a WARNING never blocks (committed)
$created->getStatus();                       // UseCaseStatus::CREATED
$created->getWarnings()[0]->code;            // 'RENT_REVIEW'

$invalid = $useCases->run(new RegisterTenant($pdo), new RegisterTenantRequest('', 1200));
$invalid->getStatus();                       // UseCaseStatus::INVALID: execute() was not called (rolled back)
$invalid->getCodes();                        // ['NAME_REQUIRED']

// HTTP only at the edge of the application
$invalid->getStatus()->toHttpStatus();       // 400 (CREATED → 201, REJECTED → 422, CONFLICT → 409 ...)
```

`TransactionMiddleware` commits only a success and rolls back a failure status or an exception (which it rethrows for the runner to turn into `FAILED`). `UseCaseResult` and the machine's `TransitionResult` implement the same `Results\IOutcome` (`isSuccess()`, `getReason()`), and `UseCaseResult::fromTransition()` converts one into the other.

#### 15.2 A Machine Event as a Use Case

`StateMachineEventUseCase` (or `Spec::machineEventUseCase()`) exposes one event of a machine as a use case: the request is a `MachineEventRequest` (instance id, `occurredAt`, payload, optional idempotency key, actor and expected version), and the outcome is the runner's result converted: accepted → `SUCCESS` (payload = the new snapshot), rejected → `REJECTED` with the guards' codes, unhandled → `CONFLICT`, a stale version → `CONFLICT` with `SM_CONCURRENCY_CONFLICT`, an unknown instance → `NOT_FOUND`. With the quote machine of 14.2:

```php
use Antevemus\ASpecification\UseCase\MachineEventRequest;

$runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$quote]), MachinePersistence::inMemory());
$runner->start('quote-7', 'quote', 1, null, $now);

$useCases = Spec::useCaseRunner();
$send = fn(string $event, array $payload = []): UseCaseResult => $useCases->run(
    Spec::machineEventUseCase($runner, $event),
    new MachineEventRequest('quote-7', $now, $payload, actor: 'alice')
);

$send('SEND_PROPOSAL')->getStatus();                          // UseCaseStatus::SUCCESS
$send('REGISTER_PENDING', ['motive' => 'Missing ID'])->isSuccess(); // true
$send('APPROVE')->getCodes();                                 // ['QUOTE_PENDING_OPEN'] (status REJECTED, HTTP 422)
$send('SEND_PROPOSAL')->getStatus();                          // UseCaseStatus::CONFLICT: the event does not fit the current state
$send('RESOLVE_PENDING')->getPayload()->stateOf('progress');  // 'proposal_sent'

$useCases->run(Spec::machineEventUseCase($runner, 'CANCEL'), new MachineEventRequest('quote-99', $now))
    ->getStatus();                                            // UseCaseStatus::NOT_FOUND
```

#### 15.3 Adianti as the Host

The transaction of an Adianti screen (`TTransaction` opens a new connection at every `open()`: join the open one instead) and a `UseCaseResult` shown with `TMessage`/`TToast` without the message of an exception: example 22.3.
