# Adianti as a First-Class Host

### 22.3. Adianti as a First-Class Host (Module 36)

The library runs inside Adianti Framework screens without any class of `src/` depending on Adianti widgets. Three recipes, run by the suite against the stubs of `tests/Stubs/Adianti`:

- **The transaction of the screen.** `TTransaction::open()` always opens a **new** connection and stacks it, so `new TransactionMiddleware(fn() => TTransaction::open('db'), ...)` inside a screen that already opened a transaction writes on a second connection, committed apart: a rollback of the screen leaves it written. `AdiantiTransaction` below joins the open transaction (it opens and ends one only when there is none, and the screen keeps the decision when it joined); `new PdoTransactionManager(TTransaction::get())` joins it as well.
- **The result on the screen.** `UseCaseFeedback` shows a `UseCaseResult` with `TToast`/`TMessage`: the messages of the rules for a refusal, a reference for `FAILED` (never the message of an exception, which may carry SQL and values), "being processed" for `ACCEPTED` (the pair of a pending datum, 22.2).
- **The criteria.** A connection with `prep = 1` (the default of new Adianti applications; required by `withAccentFolding()` and by the subqueries of 22.1), `inSubquery()`/`existsIn()` instead of `NOESC:(SELECT ...)`, and at least `^2.1.1` for `toCriteria()` (an OR at the root is nested before `TRepository` adds the soft delete and the prefilters).

```php
use Adianti\Database\TTransaction;
use Adianti\Widget\Dialog\TMessage;
use Adianti\Widget\Dialog\TToast;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\UseCase\IUseCase;
use Antevemus\ASpecification\UseCase\TransactionMiddleware;
use Antevemus\ASpecification\UseCase\UseCaseResult;
use Antevemus\ASpecification\UseCase\UseCaseStatus;

/** Joins the transaction the screen opened; opens (and ends) one only when there is none. */
final class AdiantiTransaction
{
    private bool $opened = false;

    public function __construct(private readonly string $database) {}

    public function middleware(): TransactionMiddleware
    {
        return new TransactionMiddleware($this->begin(...), $this->commit(...), $this->rollback(...));
    }

    public function begin(): void
    {
        if (TTransaction::get() === null) {
            TTransaction::open($this->database);
            $this->opened = true;
        }
    }

    public function commit(): void
    {
        if ($this->opened) {
            $this->opened = false;
            TTransaction::close();
        }
    }

    public function rollback(): void
    {
        if ($this->opened) {
            $this->opened = false;
            TTransaction::rollback();
        }
    }
}

/** A UseCaseResult on an Adianti screen: messages of the rules, never the message of an exception. */
final class UseCaseFeedback
{
    public static function show(UseCaseResult $result, string $done): void
    {
        match (true) {
            $result->getStatus() === UseCaseStatus::ACCEPTED => TToast::show('info', 'Received: being processed.'),
            $result->isSuccess() => TToast::show('success', $done),
            $result->getStatus() === UseCaseStatus::FAILED => new TMessage('error', 'Unexpected error. Reference: ' . ($result->getCorrelationId() ?? '-')),
            default => new TMessage('error', implode('<br>', array_map(fn($failure) => $failure->message, $result->getErrors()))),
        };
    }
}

final class OpenClaim implements IUseCase
{
    public function execute(object $request): UseCaseResult
    {
        TTransaction::get()->prepare('INSERT INTO claim_history (claim_id, event) VALUES (?, ?)')->execute([$request->claimId, 'opened']);

        return UseCaseResult::created($request->claimId);
    }
}

$useCases = Spec::useCaseRunner(middlewares: [(new AdiantiTransaction('rental'))->middleware()]);

TTransaction::open('rental');                                            // the screen's own transaction
$result = $useCases->run(new OpenClaim(), (object) ['claimId' => 7]);    // joins it: one connection, one commit
$result->isSuccess() ? TTransaction::close() : TTransaction::rollback();
UseCaseFeedback::show($result, 'Claim opened.');                         // TToast: 'Claim opened.'
```
