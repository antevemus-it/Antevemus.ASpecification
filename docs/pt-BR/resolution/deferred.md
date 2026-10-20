# Fontes Adiadas e Transportes de Estágio

### 16.1. Fontes Adiadas e Transportes de Estágio (Módulo 21)

O `DataResolver` busca juntas as fontes HTTP de um estágio (`curl_multi`, veja o [exemplo 16](concurrent.md)); um `CallableDataSource` roda de forma síncrona, uma depois da outra. Desde a v2.6.0 o core tem um gancho pelo qual um pacote de fora dele busca também as fontes **que não são HTTP** de forma concorrente: a fonte implementa `IDeferredDataSource` e nomeia o seu `IStageTransport`, e o resolvedor entrega todos os pedidos pendentes daquele transporte no estágio a uma única chamada de `fetchAll()`. O pacote satélite `antevemus/aspecification-revolt` (planejado, veja o [roadmap](../project/roadmap.md)) implementa os dois sob o event loop do Revolt (Fibers); o core não tem dependência nenhuma e não roda event loop.

As duas interfaces, em `Antevemus\ASpecification\Resolution`:

- `IDeferredDataSource extends IDataSource`: `getTransport(): IStageTransport`, o transporte que a busca dentro de um estágio (um transporte pode servir várias fontes). O `fetch()` dela continua obrigatório: é o caminho síncrono fora de um escopo de resolução (`$spec->evaluate($candidate)` sem o resolvedor); dentro de um escopo o resolvedor nunca o chama, como acontece com um `IHttpDataSource`. Uma fonte que também é `IHttpDataSource` é buscada como HTTP. Registrá-la não exige extensão nenhuma.
- `IStageTransport`: `fetchAll(array $pending, ?float $deadline): array`. `$pending` liga cada chave de pedido (`DataRequest::getKey()`) a `[$source, $request]`; a resposta liga as mesmas chaves ao valor do pedido ou ao `Throwable` que o fez falhar.

Dentro de um estágio o resolvedor busca em três grupos, sempre nesta ordem:

1. as fontes HTTP, juntas, num único lote de `curl_multi` (como antes);
2. as fontes adiadas, agrupadas pela instância do transporte: um `fetchAll()` por transporte, os transportes na ordem em que o primeiro pedido de cada um apareceu, as chaves na ordem em que estavam pendentes;
3. as outras fontes, uma depois da outra (como antes).

Os grupos são serializados: um transporte começa depois que o lote HTTP terminou e termina antes de o próximo transporte começar; a concorrência fica dentro de cada grupo. Sob `tiered()` cada custo é um estágio próprio, então fontes adiadas de custos diferentes são buscadas em estágios diferentes, a mais barata primeiro, e um veredito já decidido pula as caras; sob `sequential()` cada folha chega ao seu transporte com um único pedido, quando a avaliação chega nela. Duas folhas que pedem o mesmo dado continuam causando uma única busca.

**Tempo-limite.** `$deadline` é o instante monotônico do tempo-limite da resolução (`ResolutionPolicy::concurrent(timeout: 3.0)`), comparável a `CurlMultiTransport::now()`, ou `null` sem limite. Um transporte deve parar de esperar nele e responder os pedidos não terminados com `DataFetchException::resolutionTimeout()`. Quando o limite já passou antes da vez de um transporte, o resolvedor não o chama: os pedidos dele falham com essa mesma exceção.

**Falhas.** Um `Throwable` na resposta faz falhar só aquele pedido: é o erro de avaliação da folha dele (mensagem genérica, `evaluation_error = true`, o `Throwable` em `$result->exception`), listado por `getFailedRequests()`. Uma chave que o transporte não responde falha com uma `DataFetchException` ("the stage transport returned no result for data source ..."), nunca com os parâmetros; uma chave respondida que não foi pedida é ignorada. Um `Throwable` lançado pelo próprio `fetchAll()` faz falhar todos os pedidos daquele transporte, e só eles: os outros transportes e fontes do estágio são buscados normalmente.

**Cache.** Um valor vai para o cache com o TTL da sua fonte, como qualquer valor (`new DataResolver($registry, new InMemoryDataCache())`); uma falha nunca vai para o cache, então a próxima resolução pergunta de novo ao transporte. Um pedido respondido pelo cache nunca chega ao transporte. `getFetchCount()` conta os pedidos entregues aos transportes; um estágio sem fontes adiadas roda exatamente como antes da 2.6.0.

O bloco abaixo é um transporte completo e mínimo: busca os pedidos das suas fontes um depois do outro, pelo `fetch()` de cada uma. Um transporte de verdade (o pacote satélite) dispara todos de uma vez e espera por eles juntos.

```php
use Antevemus\ASpecification\Resolution\DataRequest;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\IDeferredDataSource;
use Antevemus\ASpecification\Resolution\IStageTransport;
use Antevemus\ASpecification\Spec;

// O transporte: todos os pedidos pendentes das suas fontes no estágio, numa única chamada
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
                $results[$key] = $e;                     // faz falhar só este pedido
            }
        }

        return $results;
    }
};

// Uma fonte adiada: a closure é o fetch() síncrono; dentro de uma resolução quem a busca é o transporte
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

$transport->calls;                                      // 1: os dois pedidos num único fetchAll()
$data->getStageCount();                                 // 1
$data->getFetchCount();                                 // 2
$data->evaluate($rule, $customer)->isSatisfied;         // true

// Um pedido que o transporte responde com um Throwable é o erro de avaliação da sua folha
$screened = $rule->and(Spec::external('sanctions.hit', ['document' => 'cpf'], Spec::isFalse()));
$result = $resolver->evaluate($screened, $customer);
$result->isError;                                       // true
$result->exception->getMessage();                       // 'sanctions list offline'
$transport->calls;                                      // 2
```
