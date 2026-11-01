# Resolução Concorrente de Dados para Especificações Dependentes de I/O

### 16. Resolução Concorrente de Dados para Especificações Dependentes de I/O (Módulo 21)

Uma regra que depende de dado guardado em outro lugar (o score de um bureau de crédito, uma checagem de fraude, o saldo no core bancário) não chama o serviço. `Spec::external()` **declara** o dado de que precisa: o nome de uma fonte, parâmetros lidos do candidato (caminhos com ponto) e uma especificação comum que decide sobre o valor, com seus próprios `because()`/`withCode()`. Um `DataResolver` junta as declarações da árvore inteira, busca os dados em concorrência (`curl_multi`, sem dependência de pacote, funciona no PHP-FPM) e só então avalia a árvore, de forma síncrona, como toda especificação é avaliada. As fontes são registradas por nome: `HttpDataSource` (URL base fixa, um modelo de caminho com `{marcadores}`, cabeçalhos, tempo-limite próprio, custo e TTL de cache) e `CallableDataSource` (qualquer callable síncrono: um repositório, um SDK, um cliente gRPC).

```php
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\HttpDataSource;
use Antevemus\ASpecification\Resolution\ResolutionPolicy;
use Antevemus\ASpecification\Spec;

$serasaUrl = 'https://bureau.example.com';         // da fonte, nunca do candidato
$fraudUrl  = 'https://fraud.example.com';
$bankUrl   = 'https://core-banking.example.com';

$registry = DataSourceRegistry::default()
    ->register('serasa.score', new HttpDataSource(
        baseUrl: $serasaUrl,
        path: '/serasa/score/{document}',
        parse: fn(int $status, array $headers, string $body): int => json_decode($body, true)['score'],
        headers: ['Authorization' => 'Bearer bureau-token'],
        timeout: 2.0,
        cost: 10,                                 // consulta paga ao bureau
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
        cost: 1,                                  // o nosso core bancário: barato
    ));

// A regra declara os dados de que precisa; não chama nada
$credit = Spec::external('bank.balance', ['account' => 'account.number'],
        Spec::greaterThanOrEqualTo(1000)->because('Balance below 1,000.')->withCode('LOW_BALANCE'))
    ->and(Spec::external('fraud.flag', ['document' => 'cpf'], Spec::isFalse())->withCode('FRAUD_SUSPECTED'))
    ->and(Spec::external('serasa.score', ['document' => 'cpf'], Spec::greaterThanOrEqualTo(700))
        ->because('Credit score below 700.')->withCode('SCORE_LOW'));

$customer = (object) ['cpf' => '111.444.777-35', 'account' => (object) ['number' => 'A-1']];
$resolver = new DataResolver($registry);

// concurrent() (o padrão): as três chamadas de uma vez, mais ou menos o tempo da mais lenta
$resolver->evaluate($credit, $customer, ResolutionPolicy::concurrent())->isSatisfied;      // true

// tiered() (escolha explícita): da mais barata para a mais cara, parando assim que o veredito está decidido
$poor = (object) ['cpf' => '222.333.444-05', 'account' => (object) ['number' => 'A-2']];
$data = $resolver->resolve($credit, $poor, ResolutionPolicy::tiered()); // saldo 10: o AND já é falso
count($data->getSkippedRequests());                   // 2: a checagem de fraude e o bureau não foram chamados
$data->evaluate($credit, $poor)->getCodes();          // ['LOW_BALANCE']

// Mesmo veredito em todas as políticas; concurrent() pagou todas as fontes e relata todas as falhas
$resolver->evaluate($credit, $poor, ResolutionPolicy::concurrent())->getCodes();          // ['LOW_BALANCE', 'SCORE_LOW']
$resolver->evaluate($credit, $poor, ResolutionPolicy::sequential())->isSatisfied;         // false
```

As políticas: `concurrent()` (o padrão) busca todos os pedidos da árvore numa etapa e devolve todas as falhas, como a avaliação fora do resolvedor; `tiered()` (escolha explícita, quando a chamada custa dinheiro) busca por custo, do mais barato ao mais caro, e depois de cada etapa avalia a árvore em lógica de três valores (verdadeiro, falso, desconhecido): quando o veredito não pode mais mudar (um `and` com um operando falso, um `or` cujo operando da esquerda é verdadeiro), as fontes mais caras não são chamadas; `sequential()` não busca nada antes e cada folha busca quando a avaliação chega nela. O veredito é o mesmo em todas as políticas e fora do resolvedor; no `tiered()`, um ramo que não foi buscado porque não podia mudar o veredito não relata falha, então a lista de falhas pode ser menor que a do `concurrent()`. Um `or` só para cedo pelo operando da esquerda: no núcleo, um erro no operando da esquerda aborta a disjunção, então um operando da esquerda desconhecido não pode ser pulado. Duas folhas que pedem o mesmo dado (mesma fonte, mesmos parâmetros) geram uma chamada só. Uma busca que falhou é desconhecida, nunca decide sozinha.

```php
use Antevemus\ASpecification\Resolution\HttpDataSource;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;

// Fora do resolvedor a mesma regra continua funcionando: cada folha busca o próprio dado pelo
// registro padrão, de forma síncrona (uma chamada depois da outra, sem deduplicação)
$credit->evaluate($customer)->isSatisfied;                          // true

// Uma fonte que falha é erro de avaliação da folha, nunca um "false" silencioso
$limitsUrl = 'https://limits.example.com';
$registry->register('credit.limit', new HttpDataSource(baseUrl: $limitsUrl, path: '/limits/{document}', cost: 2));
$limit = Spec::external('credit.limit', ['document' => 'cpf'], Spec::greaterThan(0));

$result = $resolver->evaluate($limit, $customer);                   // o serviço responde 503
$result->isError;                                                   // true
$result->failures[0]->message;                                      // 'The rule could not be evaluated.'
$result->exception->getStatusCode();                                // 503, para o log (o corpo nunca é guardado)

// Os visitors recusam a folha: o dado só existe quando a árvore é avaliada em PHP
try {
    $credit->toSql('pgsql');
} catch (NonTranslatableSpecificationException $e) {
    // filtre no SQL com as outras regras e avalie a parte externa em PHP
}
```

Os erros seguem a regra da 1.8.0: uma busca que falha (status HTTP fora de 2xx, erro de transporte, o tempo-limite da fonte ou da resolução inteira com `ResolutionPolicy::tiered(timeout: 3.0)`, um parser que lança, uma fonte desconhecida) é erro de avaliação da folha: falha com a mensagem genérica, `evaluation_error = true` e uma `DataFetchException` em `$result->exception` com o status, nunca o corpo da resposta (desde a v2.12.0 também o tempo de espera pedido pelo provedor e, com um classificador de erro, um motivo: exemplo 16.2 abaixo). Não há retry. O valor de um parâmetro entra codificado no modelo de URL, então `/`, `?`, `#`, `..` ou uma URL inteira num número de documento não mudam o host nem o caminho da chamada; redirecionamentos não são seguidos. O cache entre resoluções é opcional (`new DataResolver($registry, new InMemoryDataCache())`, com o TTL de cada fonte; falhas nunca vão para o cache). O `ext-curl` é um `suggest`: sem ele, um `HttpDataSource` é recusado ao ser registrado e um `CallableDataSource` continua funcionando. Os visitors de SQL, TCriteria e ALinq recusam a folha com a exceção de "não traduzível". Os guards da máquina de estados continuam puros: o caso de uso resolve o dado antes de mandar o evento. Desde a v2.6.0 fontes que não são HTTP também podem ser buscadas de forma concorrente, pelo gancho do [exemplo 16.1](deferred.md) (`IDeferredDataSource` e `IStageTransport`); o pacote satélite `antevemus/aspecification-revolt` (Fibers/Revolt) é a implementação planejada.

Desde a v2.5.0 uma fonte que envia um pedido e lê o resultado depois (`AsyncDataSource`, com um store de tickets) responde um dado ainda não pronto como erro de avaliação tipado (`DataPendingException`, `isPending()`): nunca aprovado nem reprovado, enviado no máximo uma vez por pedido, nada espera; veja o exemplo 22.2.

### 16.2. Motivo e tempo de espera de uma chamada HTTP que falhou (Módulo 46)

Desde a v2.12.0 uma chamada HTTP que falha diz por que e por quanto tempo o provedor pediu para esperar, sem expor o corpo da resposta. `DataFetchException::getRetryAfter()` é a espera em segundos, lida de `retry-after-ms` (OpenAI, Azure) ou de `Retry-After` (segundos, ou uma data HTTP medida a partir do `date` da resposta), com ou sem nada configurado. `getReason()` é um código curto dado pelo `classifyError(int $status, array $headers, string $body)` da fonte: chamado só para status fora de 2xx, ele responde um código (`/^[a-z0-9_.-]{1,64}$/`), um `HttpErrorClassification` (código e/ou espera lidos do corpo, que vence o cabeçalho, para o provedor que manda a espera ali) ou null. É o que um gateway de LLM precisa para decidir entre esperar, tentar de novo e trocar de provedor: o mesmo 429 é "vá mais devagar" ou "a cota acabou", conforme o corpo.

```php
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\Exceptions\DataFetchException;
use Antevemus\ASpecification\Resolution\HttpDataSource;

$llmUrl = 'https://llm.example.com';               // da fonte, nunca do candidato

$completion = new HttpDataSource(
    baseUrl: $llmUrl,
    path: '/v1/chat/completions',
    method: 'POST',
    headers: ['Authorization' => 'Bearer llm-key', 'Content-Type' => 'application/json'],
    body: fn(array $p): string => $p['payload'],
    timeout: 60.0,
    // Responda de uma lista fechada: o código chega aos logs, o corpo nunca
    classifyError: function (int $status, array $headers, string $body): ?string {
        $code = (string) (json_decode($body, true)['error']['code'] ?? '');

        return match (true) {
            $code === 'insufficient_quota' => 'insufficient_quota',   // um 429 que esperar não resolve
            $status === 429 => 'rate_limit',
            $status >= 500 => 'server',
            default => null,
        };
    },
);

try {
    $completion->fetch(new DataRequest('llm.chat', ['payload' => '{"model":"m","messages":[]}']));
} catch (DataFetchException $e) {                  // o provedor responde 429 com Retry-After: 2
    $e->getStatusCode();                           // 429
    $e->getReason();                               // 'rate_limit'
    $e->getRetryAfter();                           // 2.0
    $e->getMessage();                              // 'Data source "llm.chat" answered with HTTP status 429 (reason: rate_limit, retry after 2.000 s).'
}
```

Só valores validados chegam à exceção e à mensagem dela: o código e o número. Sem motivo e sem espera a mensagem é a da 2.11. Um classificador que lança ou responde outra coisa não muda a falha: ela continua o status HTTP, sem motivo, e a causa vai como exceção anterior (o valor respondido nunca é repetido). O núcleo continua sem nova tentativa: o que fazer com a espera (esperar, tentar de novo com recuo, ir para um provedor de reserva) é decisão de quem chama. Uma fonte própria (`IHttpDataSource`) reaproveita as peças: `RetryAfter::fromHeaders($headers)` e `DataFetchException::httpStatus($source, $status, $reason, $retryAfter)`.
