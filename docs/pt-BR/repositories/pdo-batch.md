# Escrita em Lote e Operações Sobre Conjuntos

### 17.2. Escrita em Lote e Operações Sobre Conjuntos (Módulo 25)

Escritas e leituras sobre muitas linhas que não montam uma entidade por vez: um `INSERT` de várias linhas, um `UPDATE` por especificação, uma projeção em arrays, agregados agrupados calculados pelo banco e uma subconsulta sobre outro repositório.

```php
use Antevemus\ASpecification\Entities\AbstractUlidEntity;

#[Table('sale')]
#[Id(strategy: IdStrategy::Ulid, column: 'id', property: 'entityId')]   // gerado no PHP: as linhas vão num INSERT só
class Sale extends AbstractUlidEntity
{
    public function __construct(
        #[Column('product_id')] public int $productId,
        #[Column] public ?string $region,
        #[Column(type: ColumnType::Decimal)] public string $amount,
        #[Column] public int $quantity = 1,
    ) {
        parent::__construct();
    }
}

$pdo->exec('CREATE TABLE sale (id CHAR(26) PRIMARY KEY, product_id INTEGER NOT NULL, region VARCHAR(10),
    amount TEXT NOT NULL, quantity INTEGER NOT NULL)');
$log->clear();
$sales = PdoRepository::forEntity($pdo, Sale::class)->withQueryLogger($log);

$sales->insertAll([                               // entidades novas, sem o SELECT de existência do put()
    new Sale(1, 'north', '139.90'),
    new Sale(1, 'south', '279.80', quantity: 2),
    new Sale(2, 'north', '59.90'),
    new Sale(3, null, '19.90'),
]);                                               // 4
count($log->statements());                        // 1: um INSERT de várias linhas

$sales->updateWhere(Spec::property('region', Spec::isNull()), ['region' => 'west']);   // 1: um UPDATE, nenhuma entidade carregada

iterator_to_array($sales->select(Spec::property('region', Spec::equalTo('north')), ['productId', 'amount']), false);
// [['productId' => 1, 'amount' => '139.90'], ['productId' => 2, 'amount' => '59.90']]: arrays, nenhuma entidade montada

$sales->aggregate(Spec::alwaysTrue())->groupBy('region')->sum('amount')->sum('quantity')->count()->get();
// [['region' => 'north', 'sum_amount' => '199.8', 'sum_quantity' => 2, 'count' => 2],
//  ['region' => 'south', 'sum_amount' => '279.8', 'sum_quantity' => 2, 'count' => 1],
//  ['region' => 'west', 'sum_amount' => '19.9', 'sum_quantity' => 1, 'count' => 1]]

// As vendas de produtos ativos ($products do exemplo 17): product_id IN (SELECT id FROM product WHERE ...)
$sales->count(Spec::inQuery('productId', $products, Spec::property('active', Spec::isTrue()), 'entityId'));   // 3
```

- **`insertAll()`** grava entidades novas numa transação, em lotes de `withBatchSize()` linhas (1 000 por padrão) que nunca passam do limite de parâmetros do driver; uma linha que já existe falha na chave e nada da chamada fica. Identificadores gerados no PHP vão no lote; identificadores `auto` voltam por `RETURNING` no PostgreSQL e são inseridos linha a linha nos outros drivers (o MySQL não garante valores consecutivos num `INSERT` de várias linhas). Medido com 10 000 entidades: cerca de 3 vezes mais rápido que o `put()` linha a linha no SQLite, de 20 a 40 vezes no PostgreSQL e no MySQL. O **`putAll()`** mantém o upsert, mas lê a existência de um lote inteiro com um `SELECT ... IN`.
- **`updateWhere()`** converte cada valor pelo tipo da coluna e recusa o identificador, o contador de versão, as colunas de ciclo de vida e propriedades não mapeadas antes de qualquer SQL. Ele carimba o `#[UpdatedAt]`, incrementa o `#[Version]` (uma cópia carregada antes fica desatualizada) e só toca linhas vivas. Ele **não roda gancho por entidade**, e o `TriggeringRepository` não o vê (um `TriggerSweeper` percebe as mudanças): use `putAll()` quando ganchos ou gatilhos precisam rodar. No MySQL a contagem devolvida é a das linhas cujos valores mudaram (a não ser que a conexão ligue `PDO::MYSQL_ATTR_FOUND_ROWS`), e o MySQL recusa subconsulta sobre a tabela sendo atualizada.
- **`select()`** é um generator de arrays indexados pelo nome da propriedade, cada valor convertido como um valor lido; **`aggregate()`** é imutável (`groupBy()`, `count()`, `sum()`, `avg()`, `min()`, `max()`, cada um com apelido opcional) e segue as regras de nulo do SQL: `sum`/`avg`/`min`/`max` ignoram `NULL`, a soma de nada é `null`, não 0, `count()` conta linhas e `count('prop')` valores não nulos, o grupo `NULL` vem primeiro. Uma coluna `Decimal` dá texto decimal canônico (`'199.8'`, não `'199.80'`; o SQLite não tem aritmética decimal e calcula em ponto flutuante, arredondado a 15 dígitos significativos). Os dois aplicam o escopo de leitura (exclusão lógica); um agregado exige especificação traduzível mesmo com o fallback em memória.
- **`Spec::inQuery($property, $other, $where, $otherProperty)`** compila para `col IN (SELECT othercol FROM other WHERE ... AND othercol IS NOT NULL)` quando os dois repositórios compartilham a conexão, com o escopo de leitura de `$other`; senão não é traduzível. Em memória a subconsulta roda uma vez por instância da especificação e os valores ficam guardados: monte uma nova para ver dados mais novos. O `whereRelation()` (exemplo 17.3) a monta a partir de um relacionamento.
