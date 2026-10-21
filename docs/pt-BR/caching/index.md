# Cache

### 23. Cache do Catálogo de Regras e das Feature Flags (Módulo 43)

Desde a v2.8.0 o catálogo de regras e as feature flags podem ser lidos por um cache PSR-16 compartilhado por todos os servidores, em vez de uma vez por requisição: no PHP-FPM cada requisição monta o seu `PdoRuleCatalog` e o seu `FeatureFlags`, e cada uma ia ao banco. `CachedRuleCatalog` e `CachedFeatureFlagStore` são decoradores: implementam as mesmas interfaces do que envolvem, respondem exatamente o que ele responde e só mudam quantas vezes o banco é lido. Precisam de `psr/simple-cache` (só interfaces, um `suggest` deste pacote) e de uma implementação PSR-16 à sua escolha; sem elas nada muda.

```php
use Antevemus\ASpecification\Cache\CachedFeatureFlagStore;
use Antevemus\ASpecification\Cache\CachedRuleCatalog;
use Antevemus\ASpecification\Cache\PdoCatalogVersion;
use Antevemus\ASpecification\Engine\PdoRuleCatalog;
use Antevemus\ASpecification\Engine\RulePrecedence;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagDefinition;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagRegistry;
use Antevemus\ASpecification\FeatureFlags\FeatureFlags;
use Antevemus\ASpecification\FeatureFlags\InMemoryFeatureFlagErrorReporter;
use Antevemus\ASpecification\FeatureFlags\PdoFeatureFlagStore;

// $cache: a PSR-16 cache shared by every server (the Redis, Memcached or APCu adapter of your choice)
// $pdo: the tables of resources/sql/rule_catalog.ansi.sql and resources/sql/feature_flags.ansi.sql, with the
//       update stamp the application writes on business_rule (updated_at)
$errors = new InMemoryFeatureFlagErrorReporter();                  // or your logger, through IFeatureFlagErrorReporter

$pdo->exec("INSERT INTO business_rule (code, name, rule_type, int_value, scope, scenario, updated_at) VALUES
    ('notice', 'Notice window', 'claim_notice', 30, 'claim', NULL, '2026-10-21 09:00:00'),
    ('notice_vacant', 'Notice window, vacant', 'claim_notice', 45, 'claim', 'vacant', '2026-10-21 09:00:00')");

// The catalog: read once per TTL by the whole fleet; with a version, a change is seen at once
$rules = new PdoRuleCatalog($pdo);
$catalog = CachedRuleCatalog::decorate($rules, $cache, ttl: 60,
    version: PdoCatalogVersion::forRuleCatalog($pdo, $rules, 'updated_at'), reporter: $errors);
RulePrecedence::findRule($catalog, 'claim', 'claim_notice', 'vacant')->getIntValue();   // 45, read from the database
RulePrecedence::findRule($catalog, 'claim', 'claim_notice', 'vacant')->getIntValue();   // 45, read from the cache

$pdo->exec("UPDATE business_rule SET int_value = 60, updated_at = '2026-10-21 09:30:00' WHERE code = 'notice_vacant'");
RulePrecedence::findRule($catalog, 'claim', 'claim_notice', 'vacant')->getIntValue();   // 60: the version changed
$catalog->invalidate();             // without a version: call it after the transaction that changed the rules commits

// The flags: one read of the table per TTL for the fleet; a change made through the service invalidates its keys
$store = new CachedFeatureFlagStore(new PdoFeatureFlagStore($pdo), $cache, ttl: 30, reporter: $errors);
$registry = new FeatureFlagRegistry([new FeatureFlagDefinition('claim_portal', 'Claim portal')]);
(new FeatureFlags($registry, $store))->toggle('claim_portal', true, 'Portal released to every agency');
(new FeatureFlags($registry, $store))->isEnabled('claim_portal');   // true: the next request, on any server

$errors->entries();   // []: a failure of the cache would be here, once per key, and the answers would come from the database
```

- **O que vai para o cache.** O catálogo: `findRules()`, `findDocumentRules()`, `findRuleByCode()` e, pelo `CachedRuleTypeCatalog` (o que `decorate()` devolve para um catálogo que implementa `IRuleTypeCatalog`), `findRulesByType()`, então o `RulePrecedence` mantém a sua consulta única. As flags: `loadAll()` (a leitura que o `FeatureFlags` faz uma vez por instância, com as listas de sujeitos) e `find()`. Nunca vão para o cache: o histórico das flags e toda escrita. Os valores são guardados como arrays simples e lidos de volta como `RuleDefinition`, `DocumentRuleDefinition` e `FeatureFlagState` (instantes com os microssegundos e o fuso).
- **Chaves.** `aspec.catalog.<hash>.s1`, `aspec.flags.all.<hash>.s1`, `aspec.flags.one.<hash>.s1`: só os caracteres que toda implementação PSR-16 aceita, no máximo 64 depois do prefixo (`prefix:` troca o `aspec.`). Valores da aplicação (um escopo, o código de uma flag) entram no hash, nunca escritos na chave. O hash de uma leitura do catálogo cobre o escopo, o cenário e só os filtros reservados do contrato do catálogo (`product_code`, `plan_code`, o dia de `reference_date` e o dia do `PdoRuleCatalog::withAsOf()`), então o resto do contexto da validação não divide o cache. Filtros que o catálogo recusa não vão para o cache: o catálogo os recusa como antes. Dois catálogos ou stores sobre tabelas diferentes, no mesmo cache e com o mesmo prefixo, recebem `catalogId:`/`storeId:` diferentes.
- **Quando uma mudança aparece.** Catálogo: `invalidate()` começa uma geração nova, que todo servidor lê na chamada seguinte; um `ICatalogVersion` (`PdoCatalogVersion`: `COUNT(*)` e `MAX(<carimbo>)` por tabela, uma consulta leve cada, em toda chamada) faz a mudança aparecer na hora; sem ele, no fim do TTL (60 s por padrão). Flags: toda escrita vai ao store e apaga as chaves da sua flag e do `loadAll()` quando a transação mais externa do store termina (depois do commit quando foi o store que a abriu; uma mudança que entrou numa transação sua é invalidada de novo com `invalidate($code)` depois do seu commit); leituras dentro de uma transação vão ao store e nunca vão para o cache; uma escrita que o decorador não viu (um script, uma migração) aparece no fim do TTL (30 s por padrão) ou depois de `invalidate()`/`invalidateAll()`. Entre servidores a consistência é eventual dentro do TTL: uma leitura que correu junto com um commit pode manter o valor anterior até o TTL acabar. Nada trava; o compare-and-set do store continua sendo a verdade de toda mudança.
- **Quando o cache falha.** Um cache que lança exceção, recusa uma escrita ou guarda uma entrada de outro formato é um miss: a resposta vem do banco, como sem o cache, e a falha (`CacheFailureException`, com a operação e a chave) vai ao reporter de erros uma vez por chave por instância (uma requisição), nunca uma vez por leitura. Uma falha do próprio banco chega a quem chamou como antes, e o `FeatureFlags` continua transformando-a em "desligada" (falha fechada).
- **Sem `psr/simple-cache`.** A biblioteca continua com uma única dependência de runtime: as classes de cache podem ser carregadas sem as interfaces, mas não podem ser construídas, e todas as outras classes funcionam igual (a suíte roda verde sem o pacote).

### 23.1. Cache dos Clusters de Roteamento de um Repositório Particionado

O índice materializado do `PartitionRepository` (exemplo 4) tem uma parte opcional, os clusters de roteamento: as partições diretas de um nó que são mutuamente disjuntas, para que o `put()` pare na primeira que aceita a entidade. Montá-los custa um par de `isDisjointWith()` por par de partições, o que é caro para especificações de conjunto e era pago de novo por toda requisição que monta o grafo. `withIndexCache()` os guarda no cache compartilhado: o primeiro processo os monta e grava, todos os outros os leem.

```php
use Antevemus\ASpecification\Entities\AbstractUUIDEntity;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use Antevemus\ASpecification\Spec;

final class LeaseContract extends AbstractUUIDEntity
{
    public function __construct(public readonly string $state, public readonly int $rent)
    {
        parent::__construct();
    }
}

// Every request builds the graph again; after the first one, the clusters of each node come from the cache
PartitionRepository::setDagRoutingEnabled(true);                   // the routing clusters, off by default
$contracts = PartitionRepository::create(new InMemoryRepository())
    ->withIndexCache($cache, 'lease_contracts');                    // on the root, before the first put()
foreach (['SP', 'RJ', 'MG', 'PR'] as $state) {
    $contracts->addPartition(Spec::property('state', Spec::equalTo($state)));
}
$contracts->put(new LeaseContract('SP', 3200));
$contracts->getIndexCache()->statistics();   // ['hits' => 0, 'misses' => 1, 'uncacheable' => 0]: this request built them
// The next request (any process sharing the cache), the same partitions: ['hits' => 1, 'misses' => 0, 'uncacheable' => 0]
```

- **A chave.** `aspec.dag.<hash do id do repositório>.<impressão digital>.s1`, por nó: a impressão digital é o hash das partições diretas do nó, na ordem em que foram anexadas, escritas pelo `SpecificationCodec`, e das versões instaladas da biblioteca e da aplicação (Composer), então uma partição alterada dá outra chave e um deploy começa com o índice frio. O valor é um inteiro pequeno por partição. Os dados das partições nunca vão para o cache.
- **O que não vai para o cache.** Um nó cujas partições o codec não sabe escrever (um callback, uma folha sua não registrada no codec passado ao `withIndexCache()`) monta os clusters em memória, como sem o cache (`statistics()['uncacheable']`); um nó com menos de duas partições não precisa da álgebra. A ordem da subárvore do índice é uma passada pelos nós do processo e continua em memória.
- **O que se ganha.** No grafo do benchmark do repositório particionado (200 partições de especificações de conjunto), rotear com os clusters montados na requisição foi mais de uma ordem de grandeza mais lento do que rotear sem eles; com os clusters lidos do cache, foi mais ou menos tão rápido quanto sem eles. Por isso os clusters vêm desligados por padrão, e por isso o cache é o que faz valer a pena ligá-los num servidor que monta o grafo a cada requisição.
- **Configuração.** Só na raiz (`LogicException` fora dela), antes do primeiro `put()`; `ttl:` (um dia por padrão: os clusters nunca ficam velhos, o TTL só libera memória), `prefix:`, `reporter:` e `codec:` como nos decoradores do exemplo 23.
