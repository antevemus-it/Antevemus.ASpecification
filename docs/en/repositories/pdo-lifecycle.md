# Lifecycle: Timestamps, Soft Delete, Hooks and the SQL Log

### 17.1. Lifecycle: Timestamps, Soft Delete, Hooks and the SQL Log (Module 25)

The bookkeeping an application usually writes by hand around a table, as options of the mapping and of the repository: creation and modification timestamps, a removal that marks the row instead of deleting it, callables around each write, a log of every statement, booleans stored as integers or as text and the time zone of columns that hold local time without an offset (the Adianti tables, for one). Without them nothing changes.

```php
use Antevemus\ASpecification\Persistence\InMemoryQueryLogger;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\BooleanStorage;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\CreatedAt;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\SoftDelete;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\UpdatedAt;

#[Table('ticket', timezone: 'America/Sao_Paulo', booleanAs: BooleanStorage::Integer)]   // local time without offset, flags as 1/0
#[SoftDelete('deleted_at')]                       // remove() marks the row instead of deleting it
class Ticket extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Auto)]
    private ?int $id = null;

    #[CreatedAt] public ?DateTimeImmutable $createdAt = null;   // filled by the INSERT, never updated
    #[UpdatedAt] public ?DateTimeImmutable $updatedAt = null;   // set by every write of the row

    public function __construct(
        #[Column] public string $subject,
        #[Column] public bool $urgent = false,
    ) {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }
}

// $pdo of example 17
$pdo->exec('CREATE TABLE ticket (id INTEGER PRIMARY KEY AUTOINCREMENT, subject VARCHAR(80) NOT NULL,
    urgent SMALLINT NOT NULL, created_at TEXT, updated_at TEXT, deleted_at TEXT)');

$now = new DateTimeImmutable('2026-03-10 14:30:00.750 UTC');
$log = new InMemoryQueryLogger();
$tickets = PdoRepository::forEntity($pdo, Ticket::class)
    ->withClock(function () use (&$now): DateTimeImmutable { return $now; })   // the system clock by default
    ->withQueryLogger($log)                       // parameter values stay out unless logParameters: true
    ->beforePut(function (Ticket $ticket): void { // also afterPut(), beforeRemove(), afterRemove()
        if (trim($ticket->subject) === '') {
            throw new InvalidArgumentException('A ticket needs a subject.');
        }
    });

$ticket = new Ticket('Printer on fire', urgent: true);
$tickets->put($ticket);
$ticket->createdAt->format('Y-m-d H:i:s P');      // '2026-03-10 11:30:00 -03:00': in the mapping's zone, to the second
$pdo->query('SELECT created_at, urgent FROM ticket')->fetch(PDO::FETCH_NUM);   // ['2026-03-10 11:30:00', 1]
$log->entries()[0]['parameterCount'];             // 5
$log->entries()[0]['parameters'];                 // null: values can be personal data

$now = $now->modify('+2 hours');
$ticket->subject = 'Printer on fire, 3rd floor';
$tickets->put($ticket);
$ticket->updatedAt->format('H:i');                // '13:30' (createdAt keeps '11:30')
```

Removal marks the row; every read leaves the marked rows out, and two views see them:

```php
try {
    $tickets->put(new Ticket('  '));
} catch (InvalidArgumentException $e) {
    // the beforePut hook refused it inside the transaction: nothing was written
}

$tickets->remove($ticket);                        // UPDATE ... SET deleted_at: the row stays
$tickets->count(Spec::alwaysTrue());              // 0: find, iterate, count, findById, select, aggregate skip it
$tickets->withTrashed()->count(Spec::alwaysTrue());   // 1: a view that also sees the marked rows
$tickets->onlyTrashed()->findById(1)->subject;    // 'Printer on fire, 3rd floor'
$tickets->forceRemove($ticket);                   // DELETE: gone for real
$tickets->withTrashed()->count(Spec::alwaysTrue());   // 0
```

Legacy flags stored as text, such as the `CHAR(1)` `'Y'`/`'N'` columns of the Adianti tables, are read strictly:

```php
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\DataIntegrityException;

#[Table('member')]
class Member extends AbstractEntity
{
    public function __construct(
        #[Id(strategy: IdStrategy::Assigned)] private int $id,
        #[Column(booleanAs: BooleanStorage::Char)] public bool $active = true,   // 'Y' / 'N'
        #[Column(booleanAs: BooleanStorage::Char, trueValue: 'S', falseValue: 'N')] public ?bool $admin = null,
    ) {
        parent::__construct();
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}

$pdo->exec('CREATE TABLE member (id INTEGER PRIMARY KEY, active CHAR(1) NOT NULL, admin CHAR(1))');
$members = PdoRepository::forEntity($pdo, Member::class);
$members->putAll([new Member(1), new Member(2, active: false, admin: true)]);
$pdo->query('SELECT active, admin FROM member WHERE id = 2')->fetch(PDO::FETCH_NUM);   // ['N', 'S']
$members->toWhereClause(Spec::property('active', Spec::isTrue()))->toSql();
// ("active" IS NOT NULL AND "active" IN ('Y', 'y')): every case form a read accepts

$pdo->exec("UPDATE member SET active = 'X' WHERE id = 1");   // a value the mapping does not declare
try {
    $members->findById(1);
} catch (DataIntegrityException $e) {
    $e->getStoredValue();                         // 'X': an integrity error, never read as false
}
```

- **Time zones.** A temporal column is written and read in the zone it declares (`#[Column(timezone: ...)]`), else in the default of the mapping (`#[Table(timezone: ...)]`, `EntityMapping::timezone()`), else in the default of the repository (`withTimezone()`), else in UTC.
- **Timestamps.** `#[CreatedAt]` is filled by the `INSERT` when the entity holds no value (an imported value is kept) and never updated; `#[UpdatedAt]` is set by the `INSERT`, every `UPDATE` and the soft removal. The instant comes from the repository's clock (`withClock()`, read once per call) and is truncated to the second, so the entity holds exactly what any timestamp column stores.
- **Soft delete.** `#[SoftDelete('deleted_at')]` on the class (the column alone; add `property:` to hydrate it) or on a nullable date property. `remove()` and `removeBy()` mark only live rows and increment the `#[Version]` counter like any write; `withTrashed()` and `onlyTrashed()` return views (copies of the repository); `forceRemove()` deletes. A marked row is removed for every reader: `TriggeringRepository` publishes the exit and `TriggerSweeper` sees it gone. `put()` of an entity whose row is marked behaves as after a `DELETE`: a versioned copy throws `OptimisticLockException`, an unversioned one is written back and visible again. `toWhereClause()` does not show the soft delete condition.
- **Hooks** belong to the repository (the entity stays a plain object) and run inside the write's transaction: an exception in a `before*` or an `after*` hook rolls the write back and propagates. `beforePut` runs before the identifier is generated and the timestamps are set. With a remove hook, `removeBy()` loads the matching entities and removes them one by one, so a hook is never bypassed; without one it stays one statement.
- **SQL log.** `IQueryLogger::log(string $sql, int $parameterCount, float $milliseconds, ?array $parameters)` receives every statement after it ran or failed; the values are passed only with `withQueryLogger($logger, logParameters: true)`. An exception thrown by a logger is ignored. `InMemoryQueryLogger` keeps at most 10 000 entries by default (`entries()`, `statements()`, `clear()`).
- **Booleans.** `BooleanStorage::Integer`, per column (`#[Column(booleanAs: ...)]`) or as the default of the mapping, writes 1/0 on every driver, PostgreSQL included (the `SMALLINT` flags of legacy schemas), and a specification compiles to `"urgent" = 1`; reading accepts both forms. `BooleanStorage::Char` writes text, `'Y'`/`'N'` unless `trueValue:`/`falseValue:` say otherwise (on the column, or on `#[Table]`/`EntityMapping::booleanAs()` as the default; 1 to 16 letters or digits, different whatever the case). It reads the two texts in any case (and without the spaces a `CHAR` column pads with) and `NULL` as `null`; any other stored value throws `DataIntegrityException` with the column and the value. A comparison compiles to every case form of the text (`IN ('Y', 'y')`; `LOWER(col) = 'false'` for a text of more than two letters), so the database finds exactly the rows a read accepts, and the null semantics of 1.11.0 still hold. `Native` stays the default.
- **`extract()` and `hydrate()`.** `EntityMapping::extract($entity)` gives the row of an entity (column => property value) and `hydrate($row)` builds an entity from one without calling its constructor, both without a database: the `toArray()`/`fromArray()` of an active record.
