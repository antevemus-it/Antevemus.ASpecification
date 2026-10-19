# Domain Events From the Passage of Time

### 18.1. Domain Events From the Passage of Time (Module 24)

"Unpaid and due more than 30 days ago" turns true at midnight while nobody writes the invoice: only the clock moved, so the write-time triggers of example 18 never see it. A **time-based trigger** builds its specification from "now"; a **state store** keeps, for every trigger and entity, the last known answer (`satisfied`, `evaluated_at`); a **sweeper**, run by your cron with the instant injected, compares the two, records each crossing and publishes it through the same publisher and outbox, with the same kind of idempotency key. `TriggeringRepository` keeps the same store on every write, so a write and a sweep never publish the same crossing twice.

```php
use Antevemus\ASpecification\DomainEvents\State\InMemoryTriggerStateStore;
use Antevemus\ASpecification\DomainEvents\TriggerSweeper;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;

#[Table('invoice')]                               // mapped for the PDO block below; used as is in memory
class Invoice extends AbstractEntity
{
    #[Column] public bool $paid = false;

    public function __construct(
        #[Id(strategy: IdStrategy::Assigned)] private string $number,
        #[Column('due_date')] public DateTimeImmutable $dueDate,
    ) {
        parent::__construct();
    }

    public function getEntityId(): string
    {
        return $this->number;
    }
}

$overdue = new TriggerSet(SpecificationTrigger::timed(
    'invoice.overdue',
    fn(DateTimeImmutable $now) => Spec::property('paid', Spec::isFalse())
        ->and(Spec::property('dueDate', Spec::lessThan($now->modify('-30 days')))),
    onEnter: 'collection.started',
    onExit: 'collection.ended',
    crossedAt: fn(Invoice $invoice) => $invoice->dueDate->modify('+30 days'),   // the real instant of the crossing
));

$today = new DateTimeImmutable('2026-01-01');
$clock = function () use (&$today): DateTimeImmutable { return $today; };   // the system clock by default

$states = new InMemoryTriggerStateStore();        // PdoTriggerStateStore in production
$outbox = new InMemoryDomainEventOutbox();
$repository = new InMemoryRepository();
$invoices = new TriggeringRepository($repository, $overdue, new OutboxDomainEventPublisher($outbox), clock: $clock, states: $states);
$sweeper = new TriggerSweeper($repository, Invoice::class, $overdue, $states, new OutboxDomainEventPublisher($outbox));

$invoice = new Invoice('INV-1', new DateTimeImmutable('2026-01-05'));
$invoices->put($invoice);                         // due in 4 days: no event; its state is recorded
$sweeper->sweep(new DateTimeImmutable('2026-01-20'))->enteredCount();   // 0
$sweeper->sweep(new DateTimeImmutable('2026-02-05'))->enteredCount();   // 1: nobody wrote it, time did
$sweeper->sweep(new DateTimeImmutable('2026-02-06'))->enteredCount();   // 0: already announced
$outbox->findByEntity(Invoice::class, 'INV-1')[0]->event->occurredAt == $invoice->dueDate->modify('+30 days');   // true

$today = new DateTimeImmutable('2026-02-09');
$invoice->paid = true;
$invoices->update($invoice);                      // collection.ended, published by the write
$sweeper->sweep(new DateTimeImmutable('2026-02-10'))->exitedCount();    // 0: the write already did
$outbox->count();                                 // 2
```

> [!WARNING]
> ⚠️ **Introducing a trigger over existing data? Run `baseline()` first.** An entity without a recorded state counts as "not satisfied", so the first sweep publishes an enter for every invoice that is already overdue. That is the truth (they are inside and nobody was told), but rarely what a mailing wants. `baseline($now)` records the current state of every trigger for every entity and publishes nothing; the following sweeps publish only new crossings.

```php
// A trigger introduced over existing data: baseline() first, or the first sweep announces everything already inside
$archive = new InMemoryRepository([
    new Invoice('INV-2', new DateTimeImmutable('2025-11-03')),   // overdue since December
    new Invoice('INV-3', new DateTimeImmutable('2026-03-02')),
]);
$catchUp = new TriggerSweeper($archive, Invoice::class, $overdue, new InMemoryTriggerStateStore(), new OutboxDomainEventPublisher($outbox));
$catchUp->baseline(new DateTimeImmutable('2026-02-10'))->recordedCount();   // 2: one state per invoice, no event
$catchUp->sweep(new DateTimeImmutable('2026-02-11'))->enteredCount();      // 0: INV-2 was already inside
$catchUp->sweep(new DateTimeImmutable('2026-04-02'))->eventNames();        // ['collection.started']: INV-3, a new crossing

// The cron (every hour, every night): the instant is the cron's, the limit slices a large backlog
do {
    $report = $catchUp->sweep(new DateTimeImmutable(), limit: 500);
} while ($report->limitReached());
```

In production the store is a table, `domain_event_trigger_state`, in the same `resources/sql/domain_events.ansi.sql` (running the script again on a 1.11 database only adds it). Give the store, the outbox and the repository the same connection and transaction manager, so that the state of a crossing and its event commit together:

```php
use Antevemus\ASpecification\DomainEvents\State\PdoTriggerStateStore;

// $pdo of example 17; once, with your migration tool: the invoice table and resources/sql/domain_events.ansi.sql
$transactions = new PdoTransactionManager($pdo);
$repository = PdoRepository::forEntity($pdo, Invoice::class, $transactions);
$states = new PdoTriggerStateStore($pdo);         // table domain_event_trigger_state
$publisher = new OutboxDomainEventPublisher($events = new PdoDomainEventOutbox($pdo));
$invoices = new TriggeringRepository($repository, $overdue, $publisher, $transactions, states: $states);
$sweeper = new TriggerSweeper($repository, Invoice::class, $overdue, $states, $publisher, $transactions);

$invoices->put(new Invoice('INV-4', new DateTimeImmutable('2026-01-05')));   // overdue already: the write publishes the enter
$sweeper->sweep(new DateTimeImmutable())->enteredCount();                   // 0: its state says inside
count($events->findByEntity(Invoice::class, 'INV-4'));                      // 1
```

- **How a sweep finds the crossings, and what it costs.** Per time-based trigger, the states recorded as inside are read in pages of 500 (by entity id) and, per page, one `COUNT(*)` of `spec(now) AND id IN (page)` tells whether they are all still inside; only when they are not are those entities loaded (a removed one is an exit). Then, when the number confirmed inside equals the `COUNT(*)` of `spec(now)`, nothing can enter and the entry pass is skipped; otherwise the entities satisfying `spec(now)` are streamed (in SQL with a `PdoRepository`) and those not recorded inside are entries. A sweep that finds no crossing loads no entity: with 10 000 invoices in SQLite, 5 000 of them overdue, it is 22 statements, a few tens of milliseconds, and the same memory peak (one page) as with 1 000 invoices. The SQL only narrows: each crossing is confirmed in PHP on the loaded entity, as on a write.
- **One transaction per crossing, compare-and-set on the state.** The state is written only if it is still at the version the sweep read, and the event is published only when that write won, so two sweepers (two cron hosts, an overlapping run) or a sweeper and a write racing on one crossing publish it once; the lost one is counted in `conflictCount()`. The outbox's unique idempotency key is the second barrier. Two sweepers are correct together, not faster: the work is not partitioned between them, and there is no embedded scheduler (the cron is yours, as with `deliverDue()` of the state machine).
- **The instant.** An event found by a sweep is stamped with the sweep's `$now`, unless the trigger declares `crossedAt` (it receives the entity, `$now` and the kind of crossing): its instant is used when it lies after the last recorded evaluation and not after `$now`, which keeps the idempotency key the same in every sweep and every process, while a re-entry after an exit still gets a key of its own. A crossing seen by a write carries the write's instant.
- **`limit`** bounds the crossings handled by one call: loop while `limitReached()`. `SweepReport` gives the counts, the events, the lost compare-and-sets, the evaluation errors (also sent to the `IDomainEventErrorReporter`) and two cost counters (`entitiesLoaded()`, `statesRead()`).
- **Limits:** one sweeper per concrete entity class (the states are keyed by it; an entity of another class found in the repository is reported, never recorded); on MySQL with unbuffered queries, give the sweeper a repository on its own connection (it reads states while the entity cursor is open); the plain triggers of the set are not swept (they only change on writes), but `baseline()` records them too.
