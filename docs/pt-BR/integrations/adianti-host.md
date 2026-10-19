# Adianti Como Host de Primeira Classe

### 22.3. Adianti Como Host de Primeira Classe (Módulo 36)

A biblioteca roda dentro de telas do Adianti Framework sem que nenhuma classe de `src/` dependa dos widgets do Adianti. Três receitas, executadas pela suíte contra os stubs de `tests/Stubs/Adianti`:

- **A transação da tela.** O `TTransaction::open()` sempre abre uma conexão **nova** e a empilha, então `new TransactionMiddleware(fn() => TTransaction::open('db'), ...)` numa tela que já abriu transação grava numa segunda conexão, confirmada à parte: o rollback da tela a deixa gravada. O `AdiantiTransaction` abaixo entra na transação aberta (abre e encerra uma só quando não há nenhuma, e a tela mantém a decisão quando ele entrou); `new PdoTransactionManager(TTransaction::get())` também entra nela.
- **O resultado na tela.** O `UseCaseFeedback` mostra um `UseCaseResult` com `TToast`/`TMessage`: as mensagens das regras numa recusa, uma referência no `FAILED` (nunca a mensagem da exceção, que pode trazer SQL e valores), "em processamento" no `ACCEPTED` (o par de um dado pendente, 22.2).
- **O critério.** Conexão com `prep = 1` (o padrão das aplicações Adianti novas; exigido pelo `withAccentFolding()` e pelas subqueries do 22.1), `inSubquery()`/`existsIn()` no lugar de `NOESC:(SELECT ...)`, e no mínimo `^2.1.1` para o `toCriteria()` (um OR na raiz é aninhado antes de o `TRepository` acrescentar o soft delete e os prefiltros).

```php
use Adianti\Database\TTransaction;
use Adianti\Widget\Dialog\TMessage;
use Adianti\Widget\Dialog\TToast;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\UseCase\IUseCase;
use Antevemus\ASpecification\UseCase\TransactionMiddleware;
use Antevemus\ASpecification\UseCase\UseCaseResult;
use Antevemus\ASpecification\UseCase\UseCaseStatus;

/** Entra na transação que a tela abriu; abre (e encerra) uma só quando não há nenhuma. */
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

/** Um UseCaseResult numa tela Adianti: as mensagens das regras, nunca a mensagem de uma exceção. */
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

TTransaction::open('rental');                                            // a transação da própria tela
$result = $useCases->run(new OpenClaim(), (object) ['claimId' => 7]);    // entra nela: uma conexão, um commit
$result->isSuccess() ? TTransaction::close() : TTransaction::rollback();
UseCaseFeedback::show($result, 'Claim opened.');                         // TToast: 'Claim opened.'
```
