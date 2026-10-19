# Relacionamentos: Referências, Composições e Carga Ansiosa

### 17.3. Relacionamentos: Referências, Composições e Carga Ansiosa (Módulo 26)

Relacionamentos são declarados na entidade e carregados **explicitamente**, nunca de forma preguiçosa e sem proxies. Uma referência (`#[ManyToOne]`) é uma agregação: nunca é gravada junto com a entidade. Uma coleção (`#[OneToMany]`) é só leitura, a não ser que `cascade: true` a torne uma composição, gravada e removida com o pai. Uma associação (`#[ManyToMany]`) passa por uma tabela de junção: gravar o dono grava só os vínculos. Os repositórios das classes relacionadas são dados com `withRelated()`.

```php
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ManyToOne;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\OneToMany;

#[Table('customer')]
class Customer extends AbstractEntity
{
    public function __construct(
        #[Id(strategy: IdStrategy::Assigned)] private int $id,
        #[Column] public string $name,
    ) {
        parent::__construct();
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}

#[Table('orders')]
#[SoftDelete('deleted_at')]
class Order extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    #[Column('customer_id')] public ?int $customerId = null;                       // a verdade gravada da referência
    #[ManyToOne(Customer::class, 'customer_id')] public ?Customer $customer = null;   // agregação: nunca em cascata

    /** @var list<OrderLine> */
    #[OneToMany(OrderLine::class, mappedBy: 'orderId', cascade: true)] public array $lines = [];   // composição

    public function __construct(#[Column] public string $number)
    {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}

#[Table('order_line')]
class OrderLine extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    #[Column('order_id')] public ?int $orderId = null;   // preenchida pela cascata

    public function __construct(
        #[Column] public string $sku,
        #[Column] public int $quantity,
    ) {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}

$pdo->exec('CREATE TABLE customer (id INTEGER PRIMARY KEY, name VARCHAR(80) NOT NULL)');
$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, number VARCHAR(20) NOT NULL,
    customer_id INTEGER REFERENCES customer (id), deleted_at TEXT)');
$pdo->exec('CREATE TABLE order_line (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL REFERENCES orders (id),
    sku VARCHAR(20) NOT NULL, quantity INTEGER NOT NULL)');

$customers = PdoRepository::forEntity($pdo, Customer::class);
$lines = PdoRepository::forEntity($pdo, OrderLine::class);
$orders = PdoRepository::forEntity($pdo, Order::class)->withRelated($customers, $lines);

$customers->putAll([new Customer(1, 'Ana'), new Customer(2, 'Bruno')]);
foreach (range(1, 100) as $n) {
    $order = new Order("PO-{$n}");
    $order->customer = $customers->findById($n % 2 + 1);   // uma referência precisa de identificador: grave-a antes
    $order->lines = [new OrderLine('KB-1', 1), new OrderLine('MS-2', $n)];
    $orders->put($order);                         // o pedido, depois as linhas (order_id preenchido), numa transação
}
```

A carga ansiosa é uma visão, `with()`; cada relação custa uma consulta por lote de entidades, nunca uma por entidade:

```php
$log->clear();
foreach ([$orders, $customers, $lines] as $repository) {
    $repository->withQueryLogger($log);
}
$all = $orders->with('lines', 'customer')->find(Spec::alwaysTrue());
count($all);                                      // 100
count($log->statements());                        // 3: os pedidos, depois as linhas e os clientes deles por IN (...)
$all[0]->customer->name;                          // 'Bruno'
$all[0]->customer === $all[2]->customer;          // true: uma instância por cliente
count($all[0]->lines);                            // 2

// O filtro por um relacionamento é uma subconsulta sobre a chave estrangeira
$orders->count($orders->whereRelation('customer', Spec::property('name', Spec::equalTo('Ana'))));   // 50
$orders->count($orders->whereRelation('lines', Spec::property('quantity', Spec::greaterThan(95))));   // 5
```

Uma coleção não carregada nunca é tomada como vazia, e só uma carregada tem órfãos:

```php
$first = $orders->with('lines')->findById(1);
array_pop($first->lines);                         // coleção carregada: a linha que saiu dela é órfã
$orders->put($first);                             // ...e é apagada
$lines->count(Spec::property('orderId', Spec::equalTo(1)));   // 1

$bare = $orders->findById(1);                     // sem with(): linhas e cliente não carregados
$orders->isLoaded($bare, 'lines');                // false: [] aqui quer dizer "não sei", não "nenhuma"
$orders->put($bare);                              // não remove linha nenhuma e mantém customer_id
$lines->count(Spec::property('orderId', Spec::equalTo(1)));   // 1

$orders->remove($first);                          // Order tem #[SoftDelete]: marcado; OrderLine não tem, as linhas ficam
$lines->count(Spec::property('orderId', Spec::equalTo(1)));   // 1
$orders->forceRemove($first);                     // uma remoção física apaga antes todas as linhas
$lines->count(Spec::property('orderId', Spec::equalTo(1)));   // 0
```

Uma associação nunca é composição: os alvos vivem por conta própria, e uma coleção carregada só acrescenta e tira vínculos:

```php
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ManyToMany;

#[Table('tag')]
class Tag extends AbstractEntity
{
    public function __construct(
        #[Id(strategy: IdStrategy::Assigned)] private int $id,
        #[Column] public string $name,
    ) {
        parent::__construct();
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}

#[Table('article')]
class Article extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    /** @var list<Tag> */
    #[ManyToMany(Tag::class, 'article_tag', joinColumn: 'article_id', inverseJoinColumn: 'tag_id')]
    public array $tags = [];                          // associação: só os vínculos são gravados

    public function __construct(#[Column] public string $title)
    {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}

$pdo->exec('CREATE TABLE tag (id INTEGER PRIMARY KEY, name VARCHAR(30) NOT NULL)');
$pdo->exec('CREATE TABLE article (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(80) NOT NULL)');
$pdo->exec('CREATE TABLE article_tag (article_id INTEGER NOT NULL REFERENCES article (id),
    tag_id INTEGER NOT NULL REFERENCES tag (id), PRIMARY KEY (article_id, tag_id))');

$tags = PdoRepository::forEntity($pdo, Tag::class);
$articles = PdoRepository::forEntity($pdo, Article::class)->withRelated($tags);
[$php, $sql, $orm] = [new Tag(1, 'php'), new Tag(2, 'sql'), new Tag(3, 'orm')];
$tags->putAll([$php, $sql, $orm]);                // os alvos são gravados pelo repositório deles

$article = new Article('Specifications over PDO');
$article->tags = [$php, $sql];
$articles->put($article);                         // o artigo, depois duas linhas de article_tag

$log->clear();
$tags->withQueryLogger($log);
$articles->withQueryLogger($log);
$loaded = $articles->with('tags')->findById(1);
count($log->statements());                        // 3: o artigo, os vínculos, as etiquetas
$loaded->tags = [$sql, $orm];
$articles->put($loaded);                          // desvincula php, vincula orm: nenhuma etiqueta é gravada
$articles->count($articles->whereRelation('tags', Spec::property('name', Spec::equalTo('orm'))));   // 1
```

Uma coleção montada à mão pode ser declarada carregada, e `iterate()` carrega relações página a página:

```php
$edited = $articles->findById(1);                 // sem with(): etiquetas não carregadas, put() não as tocaria
$edited->tags = [$php];
$articles->markLoaded($edited, 'tags');           // PERIGO: declarada INTEIRA, o que faltar é removido
$articles->put($edited);                          // vincula php, desvincula sql e orm
count($articles->with('tags')->findById(1)->tags);   // 1

$view = $articles->with('tags')->withBatchSize(500);
foreach ($view->iterate(Spec::alwaysTrue()) as $one) {
    $one->tags[0]->name;                          // 'php': páginas de 500 por id, cada uma lida antes das etiquetas dela
}
```

- **Carga ansiosa.** `with('lines', 'customer', 'lines.product')` devolve uma visão cujas leituras (`find*()`, `iterate*()`, `findSingle()`, `findPage()`, `findById()`) carregam essas relações para cada lote de `withBatchSize()` entidades com um `SELECT ... WHERE fk IN (...)` por relação (dois numa associação: a tabela de junção, depois os alvos; mais só além do limite de parâmetros do driver), no escopo de leitura do repositório relacionado (as linhas dele removidas logicamente não são carregadas). Um caminho com pontos vai tão fundo quanto as relações vão, uma consulta a mais por segmento e lote. Com 1 000 pedidos de 10 itens cada: 2 consultas para `with('items')` e 4 para `with('items.product', 'customer')`, contra 1 001 de um laço por pedido, no SQLite, no PostgreSQL e no MySQL.
- **Não carregado não é vazio.** Uma relação não carregada mantém o seu padrão (`[]`, `null`) e o repositório sabe disso (`isLoaded()`; o estado fica fora da entidade). O `put()` de um pai cuja coleção não foi carregada não remove filho nenhum, e uma referência não carregada nunca limpa a chave estrangeira: só uma referência carregada não nula e depois posta em `null` a limpa.
- **Referências** nunca vão em cascata: o `put()` grava o identificador do objeto referenciado na propriedade da chave estrangeira e recusa objeto sem identificador.
- **Composições.** `put()`, `putAll()` e `insertAll()` gravam os filhos depois do pai, na transação dele (mesma conexão obrigatória), preenchendo a chave estrangeira (e a referência de volta do filho quando o `mappedBy` nomeia uma). Órfãos só são removidos quando a coleção foi carregada (ou o pai acabou de ser inserido), e só os filhos carregados com ela que ainda pertencem a ele, cada um com a versão conferida: um filho acrescentado por outro nesse meio-tempo nunca é removido. Mover um filho entre dois pais carregados é seguro dentro de um `putAll()`.
- **Remover um pai** remove antes os filhos. Um pai com `#[SoftDelete]` marca os filhos que têm `#[SoftDelete]` e mantém os outros (a linha dele fica, então, se o pai for revivido, eles continuam lá); uma remoção física (`forceRemove()`, ou um pai sem exclusão lógica) apaga todas as linhas dos filhos, marcadas ou não, já que nenhuma linha filha pode sobreviver à do pai.
- **Eventos de domínio.** Registre um `TriggeringRepository` com `withRelated()` e as cascatas e os órfãos passam por ele, publicando as entradas e saídas dos filhos (ele lê pelo seu `PdoRepository`); um `PdoRepository` sem decorador não publica nada e um `TriggerSweeper` percebe. Um `TriggeringRepository` sobre o pai lê o "antes" sem relações: os gatilhos dele devem ler colunas.
- **`whereRelation('customer', $spec)`** é `customer_id IN (SELECT id FROM customer WHERE ...)` e **`whereRelation('lines', $spec)`** é `id IN (SELECT order_id FROM order_line WHERE ...)`, com o escopo de leitura do repositório relacionado; a propriedade da chave estrangeira precisa ser legível por uma especificação. Uma propriedade de relação não é coluna: uma especificação não a lê.
- **Associações.** `#[ManyToMany(Tag::class, 'article_tag', joinColumn: 'article_id', inverseJoinColumn: 'tag_id')]` (ou `EntityMapping::manyToMany()`); os nomes da tabela e das colunas vêm só do mapeamento, validados como identificadores, e o repositório do alvo tem de usar a mesma conexão. `put()`, `putAll()` e `insertAll()` de um dono cuja coleção foi carregada (ou que acabou de ser inserido) leem os vínculos gravados dele uma vez por lote, inserem os novos (várias linhas por comando) e apagam os dos alvos que saíram da coleção (só alvos carregados com ela), sem nunca gravar nem apagar um alvo; um alvo sem identificador é recusado. Uma coleção **não** carregada nunca toca a tabela de junção, mesmo trazendo alvos. Um alvo removido logicamente fica fora da coleção carregada e o vínculo dele permanece. A remoção física do dono apaga antes os vínculos dele; a remoção lógica os mantém. `whereRelation('tags', $spec)` é `id IN (SELECT article_id FROM article_tag WHERE tag_id IN (SELECT id FROM tag WHERE ...))`. Com 1 000 donos de 5 alvos cada, `with('tags')` são 3 consultas no SQLite, no PostgreSQL e no MySQL.
- **`markLoaded($entity, 'lines', 'tags')`** declara coleções montadas à mão (de um formulário, de um cache) como carregadas e **inteiras**: o próximo `put()` remove os órfãos (composição `#[OneToMany]`) ou os vínculos (`#[ManyToMany]`, fora os alvos removidos logicamente) que faltam na coleção, relativos ao que ela traz nesse `put()`. **Declarar uma coleção incompleta apaga o que falta nela**, inclusive um filho ou vínculo que outro acrescentou: uma coleção carregada por `with()` nunca remove esses. Depois da gravação a coleção fica carregada como de costume. `markUnloaded()` faz uma coleção voltar a não dizer nada.
- **`iterate()` com relações** pagina por chave, `WHERE ... AND id > :last ORDER BY id LIMIT lote`: cada página é lida inteira e o cursor dela fechado antes de as relações dela carregarem, então funciona com consultas sem buffer do MySQL (`PDO::MYSQL_ATTR_USE_BUFFERED_QUERY = false`) e guarda uma página de entidades por vez (10 000 linhas com `with('tags')` em páginas de 500: cerca de 1 MB no pico, contra 20 MB do `find()`, no SQLite). `find*()` e `findPage()` terminam a leitura antes de carregar. Sem relações, `iterate()` lê um cursor só, como antes.
