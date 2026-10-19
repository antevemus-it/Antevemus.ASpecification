# Repositório Sobre PDO

### 17. Repositório Sobre PDO (Módulo 22)

O `PdoRepository` é o `IRepository` de uma tabela do banco: a consulta é uma especificação compilada para SQL pelo visitor de SQL (em qualquer dos seus dialetos), a escrita é um `INSERT`/`UPDATE`/`DELETE` preparado e o `iterate()` lê o cursor em streaming. Quem escreve a entidade a mapeia para a tabela, com atributos ou com um builder; uma propriedade mapeada é lida e escrita por reflexão mesmo sendo privada (o mapeamento é o opt-in do autor da classe). A biblioteca não gera DDL; relacionamentos são declarados na entidade e carregados explicitamente, em lote (exemplo 17.3).

```php
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Version;
use Antevemus\ASpecification\Repositories\Pdo\PdoRepository;
use Antevemus\ASpecification\Spec;

#[Table('product')]
#[Version(property: 'version')]                   // bloqueio otimista no contador que o AbstractEntity declara
class Product extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]             // identity/auto-increment, lido de volta depois do INSERT
    private ?int $id = null;

    public function __construct(
        #[Column] public string $name,
        #[Column('unit_price', type: ColumnType::Decimal)] public string $price,   // decimal exato, como string
        #[Column] public bool $active = true,
        #[Column] private string $cost = '0.00',  // mapeada, mas privada sem getter: nunca consultável por nome
    ) {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}

$pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE product (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(80) NOT NULL,
    unit_price TEXT NOT NULL, active INTEGER NOT NULL, cost TEXT NOT NULL, version INTEGER NOT NULL)');

$products = PdoRepository::forEntity($pdo, Product::class);   // o dialeto vem do driver do PDO

$keyboard = new Product('Keyboard', '149.90', cost: '90.00');
$products->put($keyboard);                        // INSERT; o id gerado volta para a entidade
$keyboard->getEntityId();                         // 1
$keyboard->getVersion();                          // 1
$products->putAll([new Product('Mouse', '59.90'), new Product('Cable', '19.90', active: false)]); // uma transação

$active = Spec::property('active', Spec::isTrue());
$products->count($active);                        // 2: SELECT COUNT(*), nenhuma entidade montada
$products->findSingle(Spec::property('name', Spec::equalTo('Mouse')))->price;   // '59.90'
foreach ($products->iterate($active) as $product) {
    echo $product->name, PHP_EOL;                 // Keyboard, Mouse: uma linha por vez, do cursor
}
array_map(fn(Product $p): string => $p->name,
    $products->findPage($active, limit: 1, offset: 1, orderBy: ['name' => 'DESC']));   // ['Keyboard']
$products->toWhereClause($active)->toSql();       // '("active" IS NOT NULL AND "active" = 1)'
```

Bloqueio otimista: com um contador `#[Version]`, o `INSERT` grava 1 e cada `UPDATE` o incrementa e só casa com a linha enquanto ela ainda guarda a versão com que a entidade foi lida.

```php
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\OptimisticLockException;

$mine   = $products->findById(1);                 // as duas cópias têm a versão 1
$theirs = $products->findById(1);
$theirs->price = '139.90';
$products->update($theirs);                       // UPDATE ... WHERE id = 1 AND version = 1
$theirs->getVersion();                            // 2

$mine->price = '129.90';
try {
    $products->update($mine);                     // ainda na versão 1: o UPDATE não casa com nenhuma linha
} catch (OptimisticLockException $e) {
    // nada foi gravado: recarregue e aplique a mudança de novo
}
$products->findById(1)->price;                    // '139.90'
```

As regras que importam, e o builder para uma classe que não pode receber atributos:

```php
use Antevemus\ASpecification\Repositories\Pdo\Mapping\EntityMapping;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;

// Especificação que o visitor de SQL não traduz é recusada: nunca uma varredura da tabela em silêncio
$cheap = new PredicateSpecification(fn(Product $p): bool => (float) $p->price < 100);
try {
    $products->find($cheap);
} catch (NonTranslatableSpecificationException $e) {
    // escreva a regra com especificações de propriedade, ou faça o opt-in abaixo
}
count(PdoRepository::forEntity($pdo, Product::class)->withInMemoryFallback()->find($cheap)); // 2: lê a tabela inteira

// Só propriedade mapeada que uma especificação pode ler por nome (pública, getter público ou #[Specifiable])
try {
    $products->find(Spec::property('cost', Spec::greaterThan('50')));
} catch (NonTranslatableSpecificationException $e) {
    // "cost" é mapeada, mas privada sem getter: recusada no SQL como é recusada em memória
}

// O mesmo mapeamento, montado explicitamente
$mapping = EntityMapping::for(Product::class)->table('product')->id('id', IdStrategy::Auto)->version('version')
    ->column('name')->column('price', 'unit_price', ColumnType::Decimal)->column('active')->column('cost');
$mapping->equals(EntityMapping::fromAttributes(Product::class));  // true
$products = new PdoRepository($pdo, $mapping);
```

- **Consultas.** Todo valor vai por parâmetro preparado; nome de tabela e de coluna só vem do mapeamento, validado como identificador simples e citado pelo dialeto. Especificação que não se traduz (closure, chamada de método, dado externo) lança `NonTranslatableSpecificationException`; nada é filtrado em memória, a não ser que `withInMemoryFallback()` tenha sido chamado, e aí a tabela inteira é lida, então deixe isso para tabelas pequenas. Só se consulta propriedade mapeada **e** legível por nome (pública, getter público ou `#[Specifiable]`), a regra da avaliação em memória, então uma coluna privada nunca vira oráculo de leitura pelo SQL. `entityId` sempre significa a coluna do identificador.
- **Leituras.** `find*()`, `iterate*()` e `findPage()` ordenam pelo identificador (o `findPage()` o acrescenta ao fim da ordem pedida, então as páginas nunca se sobrepõem); `iterate()` é um generator sobre o cursor (memória constante); `count()` não monta entidade; `findSingle()` pede no máximo duas linhas e lança quando há mais de uma; `findById()` lê uma linha. As entidades são montadas sem chamar o construtor. O MySQL só faz streaming com `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY` em `false` na conexão; no modo bufferizado padrão o driver guarda o resultado inteiro antes da primeira linha.
- **Escritas** rodam numa transação do `ITransactionManager` do repositório (por padrão um `PdoTransactionManager` na mesma conexão, que entra numa transação já aberta): `put()`/`update()` inserem ou atualizam pela presença do id e da linha, `putAll()` é tudo ou nada, `removeBy()` é um `DELETE ... WHERE`. Atualização ou remoção a partir de cópia desatualizada lança `OptimisticLockException` e não grava nada; uma cópia versionada cuja linha sumiu nunca é reinserida.
- **Identificadores**: `auto` (identity, serial, auto-increment; no PostgreSQL `INSERT ... RETURNING`, ou `lastInsertId()` da sequência declarada), `ulid`, `uuid7`, `assigned` e `max` (`SELECT COALESCE(MAX(id), 0) + 1` dentro da transação da escrita, compatível com o `IDPOLICY = 'max'` do `TRecord` do Adianti). O `max` está sujeito a corrida entre escritores concorrentes: duas transações podem ler o mesmo máximo e a chave primária recusa o segundo `INSERT`. Prefira `auto`, `ulid` ou `uuid7` em tabelas novas.
- **Valores nulos.** Uma coluna `NULL` é um valor nulo, e o banco responde para ela o que a avaliação em memória responde: `property('note', isNull())` casa com as linhas cuja `note` é `NULL`; uma comparação (`greaterThan()`, `startsWith()`, `equalTo('x')`, `in('a', 'b')`, ...) nunca casa com elas, e a sua negação `not(property('views', greaterThan(10)))` casa. Cada comparação é compilada como `(coluna IS NOT NULL AND ...)`, então é falsa ali, nunca o desconhecido do SQL, e um `NOT` sobre ela mantém a resposta da memória. Um `and`/`or`/`not` escrito *dentro* de uma propriedade recusa o valor nulo, como recusa qualquer candidato nulo: "sem nota, ou nota n2" é `anyOf(property('note', isNull()), property('note', equalTo('n2')))`, não `property('note', anyOf(isNull(), equalTo('n2')))`. O `Spec::toSql()` e o `Spec::toCriteria()` seguem a mesma regra (mudou na 1.11.0, ver o CHANGELOG).
- **Operadores de texto seguem a memória; a igualdade simples segue a coluna.** Desde a v1.14.0, `startsWith`/`endsWith`/`contains`, os curingas, `equalIgnoreCase` e `regex` selecionam no banco as linhas que a avaliação em memória aceita, em qualquer collation da coluna: no SQLite a forma sensível a maiúsculas usa `GLOB` (o `LIKE` dele ignora maiúsculas ASCII), e MySQL/MariaDB comparam por `utf8mb4_bin` e passam ao `REGEXP` a regra de maiúsculas como flag embutida; assim `startsWith('mo')` não casa com `Mouse` em lugar nenhum. Restam dois limites. A comparação sem distinção de maiúsculas de letras não ASCII (`É` e `é`) segue o banco onde ele só dobra ASCII (SQLite, colunas PostgreSQL com a collation `"C"`). O `=` simples (`equals`) é a comparação do próprio banco, então as collations `_ci` padrão do MySQL ignoram maiúsculas e acentos ali: declare a coluna com collation binária (`utf8mb4_bin`) para igualdade exata. Escreva a regex com o modificador `u` quando ela casar texto não ASCII (em memória, `.` sem `u` é um byte). No PostgreSQL, as fronteiras de palavra `\b`/`\B` do PCRE vão como `\y`/`\Y` desde a v2.3.0 (antes, uma regex com `\b` nunca casava lá: #73), e as classes abreviadas mantêm a classificação do banco (`\d` são só os dígitos ASCII, `\w` e `\s` diferem do PCRE em algumas centenas de code points, `.` casa quebra de linha; exemplo 20.1). O SQLite dá afinidade numérica a uma coluna `DECIMAL` (`'59.90'` volta como `'59.9'`), por isso o exemplo a declara `TEXT`; comparações nessa coluna passam a ser textuais.
- **A porta de transação é compartilhada** (`Persistence\ITransactionManager`): o `PdoTransactionManager` da máquina de estados é uma delas, então uma instância dá a mesma transação ao repositório, à outbox de eventos de domínio (exemplo 18) e aos stores da máquina.
- **Fora desta versão:** joins (relacionamentos carregam em lote por `IN`, exemplo 17.3), geração de DDL, cache de segundo nível, partições (`makePartition()` lança `PartitioningNotSupportedException`). Colunas de ciclo de vida, exclusão lógica, ganchos e o log de SQL estão no exemplo 17.1, operações em lote e sobre conjuntos no 17.2.
