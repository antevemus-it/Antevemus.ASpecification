# Casos de Uso Com Resultado Comum à Máquina

### 15. Casos de Uso Com Resultado Comum à Máquina (Módulo 19)

Um caso de uso é uma classe com um método, `execute(object $request): UseCaseResult`. O `UseCaseRunner` o executa pelos middlewares, valida antes o request (atributos `#[ValidateRule]`/`#[AssertSpec]` mais as especificações registradas para a classe dele) e só falha `ERROR` bloqueia: `WARNING` e `INFO` voltam junto do sucesso. O status é um enum de domínio fechado (`SUCCESS`, `CREATED`, `ACCEPTED`, `NO_CHANGE`, `INVALID`, `REJECTED`, `NOT_FOUND`, `CONFLICT`, `FORBIDDEN`, `FAILED`); o sucesso deriva só dele, e HTTP existe só num método de borda, `toHttpStatus()`. Exceção não tratada vira `FAILED` com mensagem genérica e vai para um `IUseCaseErrorReporter` opcional: nenhuma mensagem, classe, arquivo, linha ou stack trace chega ao resultado. Nenhum mediator, contêiner ou descoberta de handlers vem embutido.

#### 15.1 `IUseCase`, `UseCaseRunner` e `TransactionMiddleware`

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
$created->isSuccess();                       // true: WARNING nunca bloqueia (commit feito)
$created->getStatus();                       // UseCaseStatus::CREATED
$created->getWarnings()[0]->code;            // 'RENT_REVIEW'

$invalid = $useCases->run(new RegisterTenant($pdo), new RegisterTenantRequest('', 1200));
$invalid->getStatus();                       // UseCaseStatus::INVALID: execute() não foi chamado (rollback)
$invalid->getCodes();                        // ['NAME_REQUIRED']

// HTTP só na borda da aplicação
$invalid->getStatus()->toHttpStatus();       // 400 (CREATED → 201, REJECTED → 422, CONFLICT → 409 ...)
```

O `TransactionMiddleware` só faz commit de sucesso e faz rollback de status de falha ou de exceção (que ele relança para o runner transformar em `FAILED`). `UseCaseResult` e o `TransitionResult` da máquina implementam o mesmo `Results\IOutcome` (`isSuccess()`, `getReason()`), e `UseCaseResult::fromTransition()` converte um no outro.

#### 15.2 Evento da Máquina Como Caso de Uso

`StateMachineEventUseCase` (ou `Spec::machineEventUseCase()`) expõe um evento da máquina como caso de uso: o request é um `MachineEventRequest` (id da instância, `occurredAt`, payload, chave de idempotência, ator e versão esperada opcionais) e o resultado é o do runner convertido: aceito → `SUCCESS` (payload = o snapshot novo), rejeitado → `REJECTED` com os códigos dos guards, unhandled → `CONFLICT`, versão velha → `CONFLICT` com `SM_CONCURRENCY_CONFLICT`, instância inexistente → `NOT_FOUND`. Com a máquina da cotação do 14.2:

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
$send('SEND_PROPOSAL')->getStatus();                          // UseCaseStatus::CONFLICT: o evento não cabe no estado atual
$send('RESOLVE_PENDING')->getPayload()->stateOf('progress');  // 'proposal_sent'

$useCases->run(Spec::machineEventUseCase($runner, 'CANCEL'), new MachineEventRequest('quote-99', $now))
    ->getStatus();                                            // UseCaseStatus::NOT_FOUND
```

#### 15.3 Adianti Como Host

A transação de uma tela Adianti (o `TTransaction` abre uma conexão nova a cada `open()`: entre na que está aberta) e um `UseCaseResult` mostrado com `TMessage`/`TToast` sem a mensagem de uma exceção: exemplo 22.3.
