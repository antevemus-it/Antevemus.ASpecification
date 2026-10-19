# Repository Over PDO

### 17. Repository Over PDO (Module 22)

`PdoRepository` is the `IRepository` of a database table: a query is a specification compiled to SQL by the SQL visitor (any of its dialects), a write is a prepared `INSERT`/`UPDATE`/`DELETE`, and `iterate()` streams the cursor. The author of the entity maps it to the table, with attributes or with a builder; a mapped property is read and written by reflection even when it is private (the mapping is the author's opt-in). The library generates no DDL; relationships are declared on the entity and loaded explicitly, in batch (example 17.3).

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
#[Version(property: 'version')]                   // optimistic locking on the counter AbstractEntity declares
class Product extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]             // identity/auto-increment, read back after the INSERT
    private ?int $id = null;

    public function __construct(
        #[Column] public string $name,
        #[Column('unit_price', type: ColumnType::Decimal)] public string $price,   // exact decimal, as a string
        #[Column] public bool $active = true,
        #[Column] private string $cost = '0.00',  // mapped, but private without a getter: never queryable by name
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

$products = PdoRepository::forEntity($pdo, Product::class);   // the dialect comes from the PDO driver

$keyboard = new Product('Keyboard', '149.90', cost: '90.00');
$products->put($keyboard);                        // INSERT; the generated id comes back
$keyboard->getEntityId();                         // 1
$keyboard->getVersion();                          // 1
$products->putAll([new Product('Mouse', '59.90'), new Product('Cable', '19.90', active: false)]); // one transaction

$active = Spec::property('active', Spec::isTrue());
$products->count($active);                        // 2: SELECT COUNT(*), no entity is built
$products->findSingle(Spec::property('name', Spec::equalTo('Mouse')))->price;   // '59.90'
foreach ($products->iterate($active) as $product) {
    echo $product->name, PHP_EOL;                 // Keyboard, Mouse: one row at a time from the cursor
}
array_map(fn(Product $p): string => $p->name,
    $products->findPage($active, limit: 1, offset: 1, orderBy: ['name' => 'DESC']));   // ['Keyboard']
$products->toWhereClause($active)->toSql();       // '("active" IS NOT NULL AND "active" = 1)'
```

Optimistic locking: with a `#[Version]` counter, the `INSERT` writes 1 and every `UPDATE` increments it and only matches the row while it still holds the version the entity was read with.

```php
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\OptimisticLockException;

$mine   = $products->findById(1);                 // both copies carry version 1
$theirs = $products->findById(1);
$theirs->price = '139.90';
$products->update($theirs);                       // UPDATE ... WHERE id = 1 AND version = 1
$theirs->getVersion();                            // 2

$mine->price = '129.90';
try {
    $products->update($mine);                     // still version 1: the UPDATE matches no row
} catch (OptimisticLockException $e) {
    // nothing was written: reload, apply the change again
}
$products->findById(1)->price;                    // '139.90'
```

The rules that matter, and the builder for a class that cannot carry attributes:

```php
use Antevemus\ASpecification\Repositories\Pdo\Mapping\EntityMapping;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;

// A specification the SQL visitor cannot translate is refused: never a silent full-table scan
$cheap = new PredicateSpecification(fn(Product $p): bool => (float) $p->price < 100);
try {
    $products->find($cheap);
} catch (NonTranslatableSpecificationException $e) {
    // express the rule with property specifications, or opt in below
}
count(PdoRepository::forEntity($pdo, Product::class)->withInMemoryFallback()->find($cheap)); // 2: reads the whole table

// Only a mapped property a specification may read by name (public, public getter or #[Specifiable])
try {
    $products->find(Spec::property('cost', Spec::greaterThan('50')));
} catch (NonTranslatableSpecificationException $e) {
    // "cost" is mapped but private without a getter: refused in SQL as it is in memory
}

// The same mapping, built explicitly
$mapping = EntityMapping::for(Product::class)->table('product')->id('id', IdStrategy::Auto)->version('version')
    ->column('name')->column('price', 'unit_price', ColumnType::Decimal)->column('active')->column('cost');
$mapping->equals(EntityMapping::fromAttributes(Product::class));  // true
$products = new PdoRepository($pdo, $mapping);
```

- **Queries.** Every value is a bound parameter; table and column names come only from the mapping, validated as plain identifiers and quoted by the dialect. A specification that cannot be translated (a closure, a method call, external data) throws `NonTranslatableSpecificationException`; nothing is filtered in memory unless `withInMemoryFallback()` was called, and then the whole table is read, so keep it for small tables. Only a property that is mapped **and** readable by name (public, a public getter or `#[Specifiable]`) can be queried, the rule the in-memory evaluation applies, so a private column is never a read oracle through SQL. `entityId` always means the identifier column.
- **Reads.** `find*()`, `iterate*()` and `findPage()` order by the identifier (`findPage()` appends it to the given order, so pages never overlap); `iterate()` is a generator over the cursor (constant memory); `count()` builds no entity; `findSingle()` asks for at most two rows and throws when there is more than one; `findById()` reads one row. Entities are built without calling their constructor. MySQL streams only with `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY` set to `false` on the connection; with the default buffered mode the driver holds the whole result before the first row.
- **Writes** run in a transaction of the repository's `ITransactionManager` (by default a `PdoTransactionManager` on the same connection, which joins a transaction already open): `put()`/`update()` insert or update by the presence of the id and of the row, `putAll()` is all or nothing, `removeBy()` is one `DELETE ... WHERE`. A stale update or removal throws `OptimisticLockException` and writes nothing; a versioned copy whose row is gone is never re-inserted.
- **Identifiers**: `auto` (identity, serial, auto-increment; in PostgreSQL `INSERT ... RETURNING`, or `lastInsertId()` of the declared sequence), `ulid`, `uuid7`, `assigned`, and `max` (`SELECT COALESCE(MAX(id), 0) + 1` inside the write transaction, compatible with the `IDPOLICY = 'max'` of Adianti's `TRecord`). `max` is subject to a race between concurrent writers: two transactions can read the same maximum and the primary key rejects the second `INSERT`. Prefer `auto`, `ulid` or `uuid7` for new tables.
- **Null values.** A `NULL` column is a null value, and the database answers for it what the in-memory evaluation answers: `property('note', isNull())` matches the rows whose `note` is `NULL`; a comparison (`greaterThan()`, `startsWith()`, `equalTo('x')`, `in('a', 'b')`, ...) never matches them, and its negation `not(property('views', greaterThan(10)))` does. Each comparison is compiled as `(column IS NOT NULL AND ...)`, so it is false there, never SQL's unknown, and a `NOT` over it keeps the in-memory answer. An `and`/`or`/`not` written *inside* a property rejects a null value, as it rejects any null candidate: "no note, or note n2" is `anyOf(property('note', isNull()), property('note', equalTo('n2')))`, not `property('note', anyOf(isNull(), equalTo('n2')))`. `Spec::toSql()` and `Spec::toCriteria()` follow the same rule (changed in 1.11.0, see the CHANGELOG).
- **Text operators follow memory; plain equality follows the column.** Since v1.14.0, `startsWith`/`endsWith`/`contains`, the wildcards, `equalIgnoreCase` and `regex` select in the database the rows the in-memory evaluation accepts, on every column collation: on SQLite the case-sensitive form uses `GLOB` (its `LIKE` ignores ASCII case), and MySQL/MariaDB compare through `utf8mb4_bin` and give `REGEXP` the case rule as an inline flag, so `startsWith('mo')` does not match `Mouse` anywhere. Two limits remain. A case-insensitive comparison of non-ASCII letters (`É` and `é`) follows the database where it folds ASCII only (SQLite, PostgreSQL columns with the `"C"` collation). Plain `=` (`equals`) is the database's own comparison, so MySQL's default `_ci` collations ignore case and accents there: declare the column with a binary collation (`utf8mb4_bin`) for exact equality. Write a regex with the `u` modifier when it matches non-ASCII text (in memory, `.` without `u` is one byte). On PostgreSQL, PCRE's word boundaries `\b`/`\B` are sent as `\y`/`\Y` since v2.3.0 (before, a regex with `\b` never matched there: #73), and the class shorthands keep the classification of the database (`\d` is the ASCII digits only, `\w` and `\s` differ from PCRE on a few hundred code points, `.` matches a newline; example 20.1). SQLite gives a `DECIMAL` column numeric affinity (`'59.90'` comes back as `'59.9'`), so the example declares it `TEXT`; comparisons on that column are then textual.
- **The transaction port is shared** (`Persistence\ITransactionManager`): the state machine's `PdoTransactionManager` is one, so one instance gives the repository, the domain event outbox (example 18) and the machine stores the same transaction.
- **Not in this version:** joins (relationships load in batch by `IN`, example 17.3), DDL generation, second-level cache, partitions (`makePartition()` throws `PartitioningNotSupportedException`). Lifecycle columns, soft delete, hooks and the SQL log are in example 17.1, batch and set-based operations in 17.2.
