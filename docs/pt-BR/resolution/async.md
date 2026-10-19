# Fontes de Dados Assíncronas

### 22.2. Fontes de Dados Assíncronas (Módulo 36)

Alguns fornecedores respondem em dois passos: um bureau de crédito cria uma análise e devolve um ticket, e o resultado é lido depois. A `AsyncDataSource` envia um pedido **uma vez**, guarda o ticket e o consulta a cada resolução; até o resultado ficar pronto, a folha responde um erro de avaliação tipado (`DataPendingException`) que nunca aprova nem reprova. Nada na biblioteca espera ou faz laço: a próxima requisição do usuário, ou a próxima rodada de um job, pergunta de novo.

```php
use Antevemus\ASpecification\Resolution\AsyncDataSource;
use Antevemus\ASpecification\Resolution\AsyncPoll;
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\InMemoryAsyncTicketStore;
use Antevemus\ASpecification\Spec;

// $bureau: o cliente de um fornecedor que cria uma análise agora e a responde depois
$sources = (new DataSourceRegistry())->register('bureau.score', new AsyncDataSource(
    submit: fn(DataRequest $request, string $key): string => $bureau->createAnalysis($request->get('cpf'), $key),
    poll: fn(string $ticket): AsyncPoll => $bureau->isDone($ticket) ? AsyncPoll::ready($bureau->score($ticket)) : AsyncPoll::pending(retryAfter: 30),
    tickets: new InMemoryAsyncTicketStore(),         // em produção, uma tabela da aplicação (CallableAsyncTicketStore)
    reconcile: fn(DataRequest $request): ?string => $bureau->findAnalysis($request->get('cpf')),
));
$scoreOk = Spec::external('bureau.score', ['cpf' => 'cpf'], Spec::greaterThanOrEqualTo(700))->withCode('SCORE_LOW');
$resolver = new DataResolver($sources);
$tenant = (object) ['cpf' => '111.444.777-35'];

$first = $resolver->evaluate($scoreOk, $tenant);
$first->isPending();                                             // true: enviado, não pronto: nem aprovado nem reprovado
$resolver->evaluate(Spec::not($scoreOk), $tenant)->isSatisfied;  // false: o NOT não inverte um dado pendente
// ... a próxima requisição, ou a próxima rodada do job, quando o bureau terminar
$resolver->evaluate($scoreOk, $tenant)->isSatisfied;            // true (score 720)
$bureau->created;                                                // 1: a análise foi criada uma vez
```

- **Pendente é erro, não um quarto valor de verdade.** A `DataPendingException` (`getSource()`, `getTicket()`, `getRetryAfter()`, informativo) vira o erro de avaliação da folha: o AND propaga, o NOT não inverte, o OR mantém a regra dele (erro à esquerda encerra a disjunção). `SpecificationResult::isPending()`, `RuleEngineVerdict::isPending()` (um veredito pendente continua bloqueando) e `ResolvedData::getPendingRequests()` o distinguem de uma falha real. Quando uma avaliação encontrou um erro real antes do dado pendente, o resultado guarda o primeiro, então não é pendente.
- **No máximo um envio por pedido.** A chave é o `DataRequest::getKey()` (a fonte e os parâmetros), entregue também ao `submit` como chave de idempotência para o fornecedor que aceitar uma. O store (`IAsyncTicketStore`: `get`, `markSubmitting`, uma reivindicação que vale uma vez, `storeTicket`, `clear`) marca a chave antes de enviar; uma chave achada "submitting" sem ticket (o processo parou entre enviar e gravar) nunca é reenviada: o `reconcile` a procura no fornecedor e, sem ele, a busca falha fechada (`DataFetchException`, "submission outcome unknown") até a chave ser limpa. O ticket fica guardado depois de lido o resultado, então perguntar de novo lê o mesmo resultado; `AsyncPoll::failed()` é falha terminal, reenviada só com `resubmitAfterFailure: true`. Um valor pronto vai para o cache pelo TTL da fonte; um pendente nunca vai. O `CallableAsyncTicketStore` mapeia o store numa tabela da aplicação com quatro closures; não há tabela da biblioteca.
