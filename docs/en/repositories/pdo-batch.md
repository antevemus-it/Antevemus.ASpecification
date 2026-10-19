# Batch Writes and Set-Based Operations

### 17.2. Batch Writes and Set-Based Operations (Module 25)

Writes and reads over many rows that do not build one entity at a time: a multi-row `INSERT`, an `UPDATE` by specification, a projection into arrays, grouped aggregates computed by the database and a subquery over another repository.

```php
use Antevemus\ASpecification\Entities\AbstractUlidEntity;

#[Table('sale')]
#[Id(strategy: IdStrategy::Ulid, column: 'id', property: 'entityId')]   // generated in PHP: the rows go in one INSERT
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

$sales->insertAll([                               // new entities, without the existence SELECT of put()
    new Sale(1, 'north', '139.90'),
    new Sale(1, 'south', '279.80', quantity: 2),
    new Sale(2, 'north', '59.90'),
    new Sale(3, null, '19.90'),
]);                                               // 4
count($log->statements());                        // 1: one multi-row INSERT

$sales->updateWhere(Spec::property('region', Spec::isNull()), ['region' => 'west']);   // 1: one UPDATE, no entity loaded

iterator_to_array($sales->select(Spec::property('region', Spec::equalTo('north')), ['productId', 'amount']), false);
// [['productId' => 1, 'amount' => '139.90'], ['productId' => 2, 'amount' => '59.90']]: arrays, no entity built

$sales->aggregate(Spec::alwaysTrue())->groupBy('region')->sum('amount')->sum('quantity')->count()->get();
// [['region' => 'north', 'sum_amount' => '199.8', 'sum_quantity' => 2, 'count' => 2],
//  ['region' => 'south', 'sum_amount' => '279.8', 'sum_quantity' => 2, 'count' => 1],
//  ['region' => 'west', 'sum_amount' => '19.9', 'sum_quantity' => 1, 'count' => 1]]

// The sales of active products ($products of example 17): product_id IN (SELECT id FROM product WHERE ...)
$sales->count(Spec::inQuery('productId', $products, Spec::property('active', Spec::isTrue()), 'entityId'));   // 3
```

- **`insertAll()`** writes new entities in one transaction, in batches of `withBatchSize()` rows (1 000 by default) that never pass the driver's parameter limit; a row that already exists fails on its key and nothing of the call is kept. Identifiers generated in PHP go in the batch; `auto` identifiers come back by `RETURNING` on PostgreSQL and are inserted row by row on the other drivers (MySQL does not guarantee consecutive values for a multi-row `INSERT`). Measured with 10 000 entities: about 3 times faster than `put()` row by row in SQLite, 20 to 40 times in PostgreSQL and MySQL. **`putAll()`** keeps the upsert, but reads the existence of a whole batch with one `SELECT ... IN`.
- **`updateWhere()`** converts each value by the type of its column and refuses the identifier, the version counter, the lifecycle columns and unmapped properties before any SQL. It stamps `#[UpdatedAt]`, increments `#[Version]` (a copy loaded before becomes stale) and touches only live rows. It runs **no per-entity hook**, and `TriggeringRepository` does not see it (a `TriggerSweeper` notices the changes): use `putAll()` when hooks or triggers must run. On MySQL the returned count is the rows whose values changed (unless the connection sets `PDO::MYSQL_ATTR_FOUND_ROWS`), and MySQL refuses a subquery on the table being updated.
- **`select()`** is a generator of arrays keyed by property name, each value converted like a read value; **`aggregate()`** is immutable (`groupBy()`, `count()`, `sum()`, `avg()`, `min()`, `max()`, each with an optional alias) and follows SQL's null rules: `sum`/`avg`/`min`/`max` ignore `NULL`, the sum of nothing is `null`, not 0, `count()` counts rows and `count('prop')` non-null values, the `NULL` group comes first. A `Decimal` column gives canonical decimal text (`'199.8'`, not `'199.80'`; SQLite has no decimal arithmetic and computes in floating point, rounded to 15 significant digits). Both apply the read scope (soft delete); an aggregate needs a translatable specification even with the in-memory fallback.
- **`Spec::inQuery($property, $other, $where, $otherProperty)`** compiles to `col IN (SELECT othercol FROM other WHERE ... AND othercol IS NOT NULL)` when both repositories share the connection, with the read scope of `$other`; otherwise it cannot be translated. In memory the subquery runs once per instance of the specification and its values are kept: build a new one to see newer data. `whereRelation()` (example 17.3) builds it from a relationship.
