# Relationships: References, Compositions and Eager Loading

### 17.3. Relationships: References, Compositions and Eager Loading (Module 26)

Relationships are declared on the entity and loaded **explicitly**, never lazily and without proxies. A reference (`#[ManyToOne]`) is an aggregation: it is never saved with the entity. A collection (`#[OneToMany]`) is read-only unless `cascade: true` makes it a composition, saved and removed with its parent. An association (`#[ManyToMany]`) goes through a join table: saving the owner writes only the links. The repositories of the related classes are given with `withRelated()`.

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

    #[Column('customer_id')] public ?int $customerId = null;                       // the stored truth of the reference
    #[ManyToOne(Customer::class, 'customer_id')] public ?Customer $customer = null;   // aggregation: never cascaded

    /** @var list<OrderLine> */
    #[OneToMany(OrderLine::class, mappedBy: 'orderId', cascade: true)] public array $lines = [];   // composition

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

    #[Column('order_id')] public ?int $orderId = null;   // set by the cascade

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
    $order->customer = $customers->findById($n % 2 + 1);   // a reference needs an identifier: put it first
    $order->lines = [new OrderLine('KB-1', 1), new OrderLine('MS-2', $n)];
    $orders->put($order);                         // the order, then its lines (order_id set), in one transaction
}
```

Eager loading is a view, `with()`; each relation costs one query per batch of entities, never one per entity:

```php
$log->clear();
foreach ([$orders, $customers, $lines] as $repository) {
    $repository->withQueryLogger($log);
}
$all = $orders->with('lines', 'customer')->find(Spec::alwaysTrue());
count($all);                                      // 100
count($log->statements());                        // 3: the orders, then their lines and their customers by IN (...)
$all[0]->customer->name;                          // 'Bruno'
$all[0]->customer === $all[2]->customer;          // true: one instance per customer
count($all[0]->lines);                            // 2

// The filter through a relationship is a subquery on the foreign key
$orders->count($orders->whereRelation('customer', Spec::property('name', Spec::equalTo('Ana'))));   // 50
$orders->count($orders->whereRelation('lines', Spec::property('quantity', Spec::greaterThan(95))));   // 5
```

A collection that was not loaded is never taken as empty, and only a loaded one has orphans:

```php
$first = $orders->with('lines')->findById(1);
array_pop($first->lines);                         // a loaded collection: the line that left it is an orphan
$orders->put($first);                             // ...and is deleted
$lines->count(Spec::property('orderId', Spec::equalTo(1)));   // 1

$bare = $orders->findById(1);                     // no with(): lines and customer not loaded
$orders->isLoaded($bare, 'lines');                // false: [] here means "unknown", not "none"
$orders->put($bare);                              // removes no line, keeps customer_id
$lines->count(Spec::property('orderId', Spec::equalTo(1)));   // 1

$orders->remove($first);                          // Order has #[SoftDelete]: marked; OrderLine has none, its rows stay
$lines->count(Spec::property('orderId', Spec::equalTo(1)));   // 1
$orders->forceRemove($first);                     // a hard delete removes every line row first
$lines->count(Spec::property('orderId', Spec::equalTo(1)));   // 0
```

An association is never a composition: the targets live on their own, and a loaded collection only adds and removes links:

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
    public array $tags = [];                          // association: only the links are written

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
$tags->putAll([$php, $sql, $orm]);                // the targets are written by their own repository

$article = new Article('Specifications over PDO');
$article->tags = [$php, $sql];
$articles->put($article);                         // the article, then two rows of article_tag

$log->clear();
$tags->withQueryLogger($log);
$articles->withQueryLogger($log);
$loaded = $articles->with('tags')->findById(1);
count($log->statements());                        // 3: the article, its links, its tags
$loaded->tags = [$sql, $orm];
$articles->put($loaded);                          // unlinks php, links orm: no tag row is written
$articles->count($articles->whereRelation('tags', Spec::property('name', Spec::equalTo('orm'))));   // 1
```

A collection built by hand can be declared loaded, and `iterate()` loads relations page by page:

```php
$edited = $articles->findById(1);                 // no with(): tags not loaded, put() would not touch them
$edited->tags = [$php];
$articles->markLoaded($edited, 'tags');           // DANGER: declared WHOLE, what it lacks is removed
$articles->put($edited);                          // links php, unlinks sql and orm
count($articles->with('tags')->findById(1)->tags);   // 1

$view = $articles->with('tags')->withBatchSize(500);
foreach ($view->iterate(Spec::alwaysTrue()) as $one) {
    $one->tags[0]->name;                          // 'php': pages of 500 by id, each read before its tags load
}
```

- **Eager loading.** `with('lines', 'customer', 'lines.product')` returns a view whose reads (`find*()`, `iterate*()`, `findSingle()`, `findPage()`, `findById()`) load those relations for each batch of `withBatchSize()` entities with one `SELECT ... WHERE fk IN (...)` per relation (two for an association: its join table, then its targets; more only past the driver's parameter limit), in the read scope of the related repository (its soft-removed rows are not loaded). A dotted path goes as deep as the relations go, one more query per segment and batch. With 1 000 orders of 10 items each: 2 queries for `with('items')` and 4 for `with('items.product', 'customer')`, against 1 001 for a loop per order, in SQLite, PostgreSQL and MySQL.
- **Not loaded is not empty.** A relation that was not loaded keeps its default (`[]`, `null`) and the repository knows it (`isLoaded()`; the state lives outside the entity). `put()` of a parent whose collection was not loaded removes no child, and a reference that was not loaded never clears the foreign key: only a reference loaded non-null and then set to `null` clears it.
- **References** are never cascaded: `put()` writes the identifier of the referenced object into the foreign key property and refuses an object without one.
- **Compositions.** `put()`, `putAll()` and `insertAll()` write the children after their parent, in its transaction (same connection required), setting the foreign key (and the child's back reference when `mappedBy` names one). Orphans are removed only when the collection was loaded (or the parent was just inserted), and only the children loaded with it that still belong to it, each with its version checked: a child added by someone else in between is never removed. Moving a child between two loaded parents is safe within one `putAll()`.
- **Removing a parent** removes its children first. A parent with `#[SoftDelete]` marks the children that have `#[SoftDelete]` and keeps the others (its row stays, so a revival finds them); a hard delete (`forceRemove()`, or a parent without soft delete) deletes every child row, marked or not, since no child row may outlive its parent's.
- **Domain events.** Register a `TriggeringRepository` with `withRelated()` and the cascades and orphans go through it, publishing the children's entries and exits (it reads through its `PdoRepository`); a bare `PdoRepository` publishes nothing and a `TriggerSweeper` notices. A `TriggeringRepository` over the parent reads its "before" without relations: its triggers should read columns.
- **`whereRelation('customer', $spec)`** is `customer_id IN (SELECT id FROM customer WHERE ...)` and **`whereRelation('lines', $spec)`** is `id IN (SELECT order_id FROM order_line WHERE ...)`, with the related repository's read scope; the foreign key property must be readable by a specification. A relation property is not a column: a specification cannot read it.
- **Associations.** `#[ManyToMany(Tag::class, 'article_tag', joinColumn: 'article_id', inverseJoinColumn: 'tag_id')]` (or `EntityMapping::manyToMany()`); the table and column names come only from the mapping, validated as identifiers, and the target repository must use the same connection. `put()`, `putAll()` and `insertAll()` of an owner whose collection was loaded (or that was just inserted) read its stored links once per batch, insert the new ones (multi-row) and delete those of the targets that left the collection (only targets loaded with it), never writing or deleting a target; a target without an identifier is refused. A collection that was **not** loaded never touches the join table, even when it holds targets. A soft-removed target is left out of a loaded collection and its link stays. A hard removal of the owner deletes its links first; a soft removal keeps them. `whereRelation('tags', $spec)` is `id IN (SELECT article_id FROM article_tag WHERE tag_id IN (SELECT id FROM tag WHERE ...))`. With 1 000 owners of 5 targets each, `with('tags')` is 3 queries in SQLite, PostgreSQL and MySQL.
- **`markLoaded($entity, 'lines', 'tags')`** declares collections built by hand (from a form, a cache) as loaded and **whole**: the next `put()` removes the orphans (`#[OneToMany]` composition) or the links (`#[ManyToMany]`, soft-removed targets aside) that the collection lacks, relative to what it holds at that `put()`. **Declaring an incomplete collection deletes what it lacks**, including a child or link someone else added: a collection loaded by `with()` never removes those. After the write the collection is loaded as usual. `markUnloaded()` makes a collection say nothing again.
- **`iterate()` with relations** pages by key, `WHERE ... AND id > :last ORDER BY id LIMIT batch`: each page is read whole and its cursor closed before its relations load, so it works on MySQL unbuffered queries (`PDO::MYSQL_ATTR_USE_BUFFERED_QUERY = false`) and holds one page of entities at a time (10 000 rows with `with('tags')` in pages of 500: about 1 MB at the peak, against 20 MB for `find()`, in SQLite). `find*()` and `findPage()` finish their read before loading. Without relations `iterate()` streams one cursor, as before.
