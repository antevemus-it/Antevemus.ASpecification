# Adopting the Machine in a Running System

### 24. Adopting the Machine in a Running System: Import, Effect Context, Action Panel and Batches (Module 45)

Since v2.10.0 the runner of [example 14](index.md) covers what an application meets when it moves a live workflow into the machine: **processes that already exist** enter where they stand, **effect handlers** read the motive and the actor of the event that produced them, an **action panel** tells "give the data first" from "not yours to do" and shows one reason when several approval ranges refuse, and **files of thousands of rows** are sent in chunks. Everything is additive: every result of 2.9.0 keeps its shape, `send()` is unchanged, a handler with the three-parameter signature keeps working, and a table created by the 2.9.0 DDL keeps working without the new column.

#### 24.1 Importing Processes Already Under Way

`startAt()` creates an instance already in the states it has in the other system, with one history row per region of the reserved type `machine.import` (the actor and the payload of the import recorded), **no entry action, no guard and no effect**: the states are a fact, not a transition. A region left out starts in its initial state; a state the region does not declare, or an unknown region, is refused (`InvalidSnapshotException`) before anything is written. The deadlines of the given states count from the instant of the import, exactly as at a start (a missing deadline source rejects the import and writes nothing). A final state needs `allowFinal: true`; the instance is then created done. The tenant comes last, as in every other call.

```php
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\StateMachine\Event;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;

$claim = Spec::machine('claim', 1)
    ->region('progress', 'triage')
        ->state('triage')
            ->on('START_ANALYSIS', 'analysis')->action('notify_analyst')
        ->state('analysis')->deadline('P5D', 'ANALYSIS_OVERDUE')
            ->on('APPROVE', 'approved')
            ->on('CANCEL', 'cancelled')->guard('payloadHas', ['field' => 'motive'])->action('mail_cancellation')
            ->on('ANALYSIS_OVERDUE', 'late')
        ->state('late')
            ->on('APPROVE', 'approved')
        ->finalState('approved')
        ->finalState('cancelled')
    ->region('pending', 'none')
        ->state('none')->on('REGISTER_PENDING', 'open')
        ->state('open')->on('RESOLVE_PENDING', 'none')
    ->build();

$persistence = MachinePersistence::inMemory();
$runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$claim]), $persistence);
$at = new DateTimeImmutable('2026-10-21T09:00:00-03:00');

// A claim of the old system, already in analysis, with a pending item open
$runner->startAt('claim-4711', 'claim', null, ['progress' => 'analysis', 'pending' => 'open'], null, $at, actor: 'import', payload: ['legacy_id' => 4711]);
$instance = $runner->load('claim-4711');
$instance->snapshot->getStates();                                   // ['progress' => 'analysis', 'pending' => 'open']
$instance->snapshot->dueAt('progress')->format('Y-m-d');            // '2026-10-26': the deadline counts from the import
$persistence->history->findByInstance('claim-4711')[0]->eventType;  // 'machine.import'
count($persistence->outbox->findByInstance('claim-4711'));          // 0: no entry action ran

$runner->startAt('claim-4712', 'claim', null, ['progress' => 'approved'], null, $at, allowFinal: true);
$runner->load('claim-4712')->snapshot->isDone();                    // true
```

- **`machine.import` is reserved**, like `machine.start` and `machine.migrate`: `send()` refuses an event of that type, so an import row of the history always comes from `startAt()`.
- **An id already taken** is `MachineInstanceAlreadyExistsException`, as in `start()`: an import run twice is detected before anything is written (derive the id from the source row).
- **The pure core** gives the same snapshot without persisting it: `StateMachine::snapshotAt($definition, $statesByRegion, $context, $at, $allowFinal)`.

#### 24.2 Effect Handlers That Read the Event

Every outbox entry now carries the event of its step (`EventSnapshot`: type, payload, actor, origin, instant). A handler that declares a fourth parameter, `EffectContext $context`, receives it, with the machine and instance versions of the step; the registry reads the signature once, when the handler is registered, and calls a three-parameter handler exactly as before. Synchronous handlers (run by the runner) and asynchronous ones (run by the `EffectDispatcher`) receive the same context.

```php
use Antevemus\ASpecification\StateMachine\Effect;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoMachineSchema;
use Antevemus\ASpecification\StateMachine\Runner\EffectContext;
use Antevemus\ASpecification\StateMachine\Runner\EffectDispatcher;
use Antevemus\ASpecification\StateMachine\Runner\EffectHandlerRegistry;

$mails = [];
$handlers = (new EffectHandlerRegistry())
    // A fourth parameter asks for the context; the three-parameter handlers of before keep working
    ->register('mail_cancellation', function (Effect $effect, string $key, string $instanceId, EffectContext $context) use (&$mails): void {
        $mails[] = sprintf('%s cancelled by %s: %s', $instanceId, $context->actor(), $context->payloadValue('motive'));
    })
    ->register('notify_analyst', function (Effect $effect, string $key, string $instanceId): void {
    });

$runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$claim]), $persistence, $handlers);
$runner->send('claim-4711', Event::external('CANCEL', $at->modify('+1 hour'), ['motive' => 'Duplicate claim']), actor: 'ana')->isAccepted(); // true
(new EffectDispatcher($persistence->outbox, $handlers))->dispatchPending()->deliveredCount(); // 1
$mails;                                                             // ['claim-4711 cancelled by ana: Duplicate claim']

// Over PDO the event is kept by the opt-in column of 2.10.0 (resources/sql/state_machine.ansi.sql)
$pdoPersistence = MachinePersistence::pdo($pdo, columns: PdoMachineSchema::COLUMNS_SINCE_2_10);
```

- **The column is opt-in.** The DDL of 2.10.0 adds `event_snapshot TEXT` to `state_machine_outbox`; the PDO outbox reads and writes it only with `columns: PdoMachineSchema::COLUMNS_SINCE_2_10` (merge it with `COLUMNS_SINCE_2_7` when both are wanted). An existing table gets it with the one `ADD COLUMN` commented in the DDL's header. Without it the handler sees `$context->hasEvent()` false, an empty `payload()` and null `actor()` and `occurredAt()`; the in-memory outbox always keeps the event.
- **`EffectHandlerRegistry::invoke(EffectContext)`** is what the runner and the dispatcher call; `takesContext($action)` tells which form a handler has.
- **The start of an instance** writes its entry effects with the event `machine.start`, an empty payload and the actor of `start()`.

#### 24.3 The Action Panel: "Give the Data" Versus "Not Yours", and One Reason to Show

`nextEvents()` tests each event with an empty payload, so an event guarded by `payloadHas` used to look refused. Each listed result now carries `getCandidateReason()`: `ALLOWED`, `MISSING_PAYLOAD` (accepted once the payload gives the fields of `getMissingPayload()`; keep the button and ask for them), `ROLE_REFUSED` (a guard marked as a role guard refuses the caller in every candidate of a region; hide it) or `GUARD_REFUSED` (any other refusal; show it disabled with its reason). The status and the reason of each result are those of `can()`, unchanged.

When several transitions answer the same event (approval by ranges) and all refuse, the reason lists the failures of every candidate. `getPrimaryReason()` is the one to show: the candidate that came closest (a guard that could not be evaluated first; then the candidates not refused by a role guard nor internal-only; then the fewest failures; then priority order, the most specific first), and `getDetails()` keeps the others, one result per candidate.

```php
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\StateMachine\CandidateReason;
use Antevemus\ASpecification\StateMachine\GuardExpression as G;
use Antevemus\ASpecification\StateMachine\TransitionCandidate;

$guards = Spec::guardRegistry()
    ->register('hasAnyRole', fn(array $p) => (new PredicateSpecification(
        fn(TransitionCandidate $c): bool => array_intersect($c->context['roles'] ?? [], $p['roles']) !== []
    ))->because('Your role cannot do this.')->withCode('ROLE_NOT_ALLOWED'))
    ->register('amountUpTo', fn(array $p) => Spec::property('context.amount', Spec::lessThanOrEqualTo($p['max']))
        ->because('The amount is above your approval limit.')->withCode('ABOVE_APPROVAL_LIMIT'))
    ->markRoleGuards('hasAnyRole');                                 // who acts: its refusals are "not yours"

$approval = Spec::machine('approval', 1)
    ->region('flow', 'awaiting_approval')
        ->state('awaiting_approval')
            ->on('APPROVE', 'approved', 'approve_analyst')
                ->guard(G::allOf(G::ref('hasAnyRole', ['roles' => ['analyst']]), G::ref('amountUpTo', ['max' => 10000])))
            ->on('APPROVE', 'approved', 'approve_manager')
                ->guard(G::allOf(G::ref('hasAnyRole', ['roles' => ['manager']]), G::ref('amountUpTo', ['max' => 50000])))
            ->on('APPROVE', 'approved', 'approve_board')->guard('hasAnyRole', ['roles' => ['board']])
            ->on('REJECT', 'rejected')
                ->guard(G::allOf(G::ref('hasAnyRole', ['roles' => ['analyst', 'manager']]), G::ref('payloadHas', ['field' => 'motive'])))
        ->finalState('approved')
        ->finalState('rejected')
    ->build();

$machine = Spec::stateMachine($guards);
$start = $machine->initialSnapshot($approval, null, $at)->snapshot;

$result = $machine->can($approval, $start, Event::external('APPROVE', $at), ['roles' => ['manager'], 'amount' => 90000]);
$result->getReason()->getCodes();           // ['ROLE_NOT_ALLOWED', 'ABOVE_APPROVAL_LIMIT', 'ABOVE_APPROVAL_LIMIT', 'ROLE_NOT_ALLOWED']
$result->getPrimaryReason()->getCodes();    // ['ABOVE_APPROVAL_LIMIT']: the manager's own range
count($result->getDetails());               // 2: the other two candidates

$panel = $machine->nextEvents($approval, $start, ['roles' => ['analyst'], 'amount' => 8000], $at);
$panel['APPROVE']->getCandidateReason();    // CandidateReason::ALLOWED
$panel['REJECT']->getCandidateReason();     // CandidateReason::MISSING_PAYLOAD: show the button, ask for the motive
$panel['REJECT']->getMissingPayload();      // ['motive']
$panel['REJECT']->isRejected();             // true: status and reason as can() gives them

$board = $machine->nextEvents($approval, $start, ['roles' => ['board'], 'amount' => 8000], $at);
$board['REJECT']->getCandidateReason();     // CandidateReason::ROLE_REFUSED: not the board's to send
```

- **Marking changes no evaluation**: `GuardRegistry::markRoleGuards()` only tells the classification and the primary reason which guards check who acts. Without marks, nothing is `ROLE_REFUSED` and the primary reason still prefers the candidate with the fewest failures.
- **"Requires" is read from the guard**: `payloadHas` leaves that must hold (not those under a `not`) are assumed provided; the event is `MISSING_PAYLOAD` when the rest of the guard passes, and the missing fields are listed in the order of the guard.
- **One candidate** gives a primary reason equal to the reason and no details; an accepted or unhandled result has the reason itself as primary. None of the new members enters `toArray()`.

#### 24.4 Files of Thousands of Rows: `sendMany()`

`sendMany()` does the work of one `send()` per event, in order, with one transaction per chunk (100 events by default) instead of one per event, and the idempotency keys of a chunk looked up together (one `IN (...)` query in the PDO store). It answers one `BatchEventOutcome` per event: the result `send()` would have returned, or the exception it would have thrown for that event alone (unknown instance, version moved, synchronous effect veto), with nothing of that event written. `send()` itself is unchanged.

```php
use Antevemus\ASpecification\StateMachine\Runner\BatchEvent;

// $pdo: the tables of resources/sql/state_machine.ansi.sql; one row of a file per event, keys derived from the row
$runner = new MachineRunner(Spec::stateMachine(), new InMemoryMachineDefinitionRepository([$claim]), MachinePersistence::pdo($pdo));
$rows = [['id' => 'claim-1'], ['id' => 'claim-2'], ['id' => 'claim-3']];
foreach ($rows as $row) {
    $runner->startAt($row['id'], 'claim', null, ['progress' => 'triage'], null, $at, actor: 'import');
}
$events = array_map(
    fn(array $row): BatchEvent => new BatchEvent($row['id'], Event::external('START_ANALYSIS', $at, [], 'file-7:' . $row['id'] . ':START_ANALYSIS')),
    $rows
);
$outcomes = $runner->sendMany($events, actor: 'import', chunkSize: 100);  // one transaction, one idempotency query
count(array_filter($outcomes, fn($outcome) => $outcome->isAccepted()));  // 3
$again = $runner->sendMany($events, actor: 'import');                    // the same file again: nothing written
$again[0]->result->getTransitions()[0]->to;                              // 'analysis': the original result
$runner->sendMany([new BatchEvent('claim-9', Event::external('START_ANALYSIS', $at))])[0]->errorMessage(); // 'Machine instance "claim-9" does not exist.'
```

- **A failure after the first write of an event** (a synchronous effect veto, a key recorded meanwhile, a database error) cannot be undone alone inside the chunk. When the runner opened the chunk's transaction, it rolls it back and sends that chunk again one event at a time through `send()`, so every outcome is still that of `send()`. When the application opened the transaction (the runner joins it), the exception propagates, as it would from `send()`, and the application rolls back. The chunks before stay committed.
- **A store of your own** gains the batch lookup by implementing `IBatchIdempotencyStore` (`findMany()`); without it `sendMany()` calls `find()` per event. No existing interface gained a method.
- **Inside a transaction of the application** (one per block of the file, with its checkpoint), `sendMany()` joins it like `send()`; the gain is then the idempotency query per chunk instead of per event.

#### Wide JSON Columns

The JSON columns of the reference DDL (`payload`, `transition_result`, `snapshot`, `params`, `reason`) are `VARCHAR(4000)`, so the script runs on every database. The PDO stores never truncate nor check their length: a longer value is refused by the database itself (PostgreSQL `22001` "value too long"; MySQL and MariaDB in strict mode, `1406` "Data too long"; SQLite does not enforce the length), and the step is rolled back. When a payload can be larger (a free-text observation, an HTML body), widen the columns that carry it once, with the migration tool of the application; the header of the DDL lists the statement of each database:

```sql
-- PostgreSQL
ALTER TABLE state_machine_history ALTER COLUMN payload TYPE TEXT;
ALTER TABLE state_machine_idempotency ALTER COLUMN transition_result TYPE TEXT;
-- MySQL, MariaDB (TEXT holds 64 KiB, MEDIUMTEXT 16 MiB)
ALTER TABLE state_machine_history MODIFY payload MEDIUMTEXT;
ALTER TABLE state_machine_idempotency MODIFY transition_result MEDIUMTEXT NOT NULL;
```

SQL Server takes `NVARCHAR(MAX)`, Oracle and DB2 a `CLOB`; the same applies to `state_machine_rejection.payload` and `reason` and to `state_machine_instance.snapshot`.
