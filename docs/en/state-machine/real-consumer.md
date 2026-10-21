# A State Machine for a Real Consumer: Legacy Codes, Versioned Definitions and Tenants

### 23. A State Machine for a Real Consumer: Legacy Codes, Versioned Definitions and Tenants (Module 40)

Since v2.7.0 the machine of [example 14](index.md) covers three things an application that replaces its own workflow tables needs: the **integer ids of the legacy states** kept as data of the definition, a **definition edited by a screen** (drafts, validation, publication of an immutable version, migration of the live instances) stored in the database, and **several tenants** (agencies, units, customers) sharing one definition with their instances kept apart. Everything is additive: a definition without codes keeps its fingerprint, a store without the new columns works as before, and every method keeps its signature (the new parameters come last).

#### 23.1 Legacy State Codes

A state may carry a `code`: the id of the row of the legacy table it replaces (an integer, or a text). Codes are unique in the whole machine (a duplicate is refused with `KIND_DUPLICATE` when the definition is built), and a canonical integer text is the same code as the integer (`"20"` finds `20`), because several PDO drivers read an integer column as text. The code is data of the definition, versioned with it; it changes nothing in how the machine runs.

```php
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\StateMachine\Event;

$quote = Spec::machine('quote', 1)
    ->region('progress', 'registering')
        ->state('registering')->code(1)
            ->on('COMPLETE_CALCULATION', 'calculation_done')
        ->state('calculation_done')->code(2)->deadline('P2D', 'EXPIRE')
            ->on('SEND_PROPOSAL', 'proposal_sent')
            ->on('EXPIRE', 'expired')
        ->finalState('proposal_sent')->code(8)
        ->finalState('expired')
    ->region('pending', 'none')
        ->state('none')
            ->on('REGISTER_PENDING', 'open')
        ->state('open')->code(20)
            ->on('RESOLVE_PENDING', 'none')
    ->build();

$quote->stateByCode(20)['region'];               // 'pending'
$quote->stateByCode('20')['state']->name;        // 'open': the text "20" is the same code as 20
$quote->codeOf('progress', 'calculation_done');  // 2

$machine = Spec::stateMachine();
$start = $machine->initialSnapshot($quote, null, new DateTimeImmutable('2026-10-21T09:00:00-03:00'));
$step = $machine->transition($quote, $start->snapshot, Event::external('COMPLETE_CALCULATION', new DateTimeImmutable('2026-10-21T10:00:00-03:00')));
$quote->codesOf($step->snapshot);                // ['progress' => 2, 'pending' => null]
```

- **Array form.** `"code": 20` appears only on a state that has one, so the fingerprint of every definition written before 2.7.0 is unchanged. A float, a boolean, an empty or padded text is refused (`KIND_MALFORMED`); `"020"` and `"20 "` are other codes.
- **Transitions keep their text id** (the legacy key fits it); there is no transition code.
- **In the database**, the PDO stores project the codes next to the state names when told so (example 23.3): `state_machine_region.current_state_code` and `state_machine_history.from_state_code`/`to_state_code`, written as integers when the codes are, so a report joins the legacy tables in plain SQL. The snapshot keeps the names: they are the truth.

#### 23.2 A Definition Edited by a Screen: Drafts, Publication and Migration

`PdoMachineDefinitionRepository` keeps the definitions in the database (tables `state_machine_definition` and `state_machine_definition_draft` of the reference DDL, the definition as `TEXT`). It is the same port the runner already reads (`register()`, `get()`, `has()`, `latestVersion()`): `get()` remembers each (key, version) it read, because a published version never changes, and checks the fingerprint of the row against its content, so a row edited by hand never runs. On top of it, `IMachineDefinitionDrafts` (also implemented by `InMemoryMachineDefinitionRepository`) gives a screen one draft per machine, kept as the array it saved, a validation report it can show, and the publication of the next version.

```php
use Antevemus\ASpecification\StateMachine\GuardRegistry;
use Antevemus\ASpecification\StateMachine\Persistence\MachinePersistence;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Runner\MachineMigrations;
use Antevemus\ASpecification\StateMachine\Runner\MachineRunner;

// $pdo: the tables of resources/sql/state_machine.ansi.sql (2.7.0); $quote: the machine of 23.1
$definitions = new PdoMachineDefinitionRepository($pdo);
$definitions->register($quote);                     // version 1, shipped with the application (a no-op when it is there)
$guards = Spec::guardRegistry()->enableSpecGuard(); // the `spec` guard is opt-in
$at = new DateTimeImmutable('2026-10-21T09:00:00-03:00');

$runner = new MachineRunner(Spec::stateMachine($guards), $definitions, MachinePersistence::pdo($pdo));
$runner->start('q-1', 'quote', 1, null, $at);

// The screen starts a draft from version 1 and adds a transition whose condition reads the context
$info = $definitions->draftFrom('quote', 1, null, 'ana', $at);
$draft = $definitions->draft('quote', $guards)->definition;
$draft['regions'][0]['states'][0]['transitions'][] = [
    'id' => 'fast_track',
    'event' => 'FAST_TRACK',
    'target' => 'proposal_sent',
    'guard' => GuardRegistry::specRef(Spec::property('amount', Spec::lessThan(10000)))->toArray(),
];
$draft['regions'][0]['states'][0]['transitions'][] = ['id' => 'broken', 'event' => 'BROKEN', 'target' => 'nowhere'];
$info = $definitions->saveDraft('quote', $draft, $info->revision, 'ana', $at);
$definitions->draft('quote', $guards)->report->errorKinds(); // ['unknown_target']: saved, reported, never published

array_pop($draft['regions'][0]['states'][0]['transitions']);
$info = $definitions->saveDraft('quote', $draft, $info->revision, 'ana', $at);
$published = $definitions->publish('quote', $info->revision, $guards, null, 'Fast track for small amounts', 'ana', $at);
$published->version();                               // 2: the repository gives the version, never the screen
$definitions->get('quote', 1)->diff($published->definition)->addedTransitions; // ['fast_track']

// Live instances stay on version 1 until they are migrated; no state was removed, so by name
$runner->registerMigration('quote', 1, 2, MachineMigrations::byStateName());
$report = $runner->migrateAll('quote', 1, 2, $at->modify('+1 hour'), actor: 'ana');
$report[0]->isMigrated();                            // true
$runner->send('q-1', Event::external('FAST_TRACK', $at->modify('+2 hours')), ['amount' => 8000])->isAccepted(); // true
```

- **Every write is a compare-and-set on the draft revision**: `$expectedRevision` is the revision the screen read (`null` when it creates the draft). Someone else's save, discard or publication in between throws `DefinitionDraftConflictException` (a `ConcurrencyConflictException`) and nothing is written.
- **`publish()`** runs in one transaction (joining one already open on the connection): the revision check, the definition built from the draft with the next version (`latestVersion + 1`; the `version` of the array is ignored), the validator with the guards (and actions) the machine runs with (an error refuses it and nothing is written; the warnings come back in `PublishedMachineDefinition::$warnings`); a definition whose deadlines count in a named calendar ([business time](business-time.md)) is checked against the calendars too when `publish()` and `draft()` receive the `DeadlineCalendarRegistry` as their last argument (`unknown_calendar` for a name the registry does not know; without the registry that check is skipped, as in the validator), the insert (a publication that took the version first is a conflict), the removal of the draft. A reason is required; the instant and the author come from the caller (the library reads no clock). A published version is never updated nor deleted.
- **Drafts are arrays**: a half-done graph (no initial state, a target that does not exist yet) is saved and reported (`MachineDefinitionDraft::$report`, the errors and warnings of `MachineDefinitionValidator::analyze()` with region, state and transition), never refused on load.
- **`MachineDefinition::diff()`** lists the regions, states and transitions added, removed and changed between two versions. **`MachineMigrations::byStateName()`** keeps every region in its state (by name) and moves the snapshot to the target version, keeping its deadlines as they are; the runner refuses it for an instance whose state no longer exists. **`migrateAll()`** migrates the instances of a version in batches (`$limit`, `$after`), one transaction each, and reports each one (`InstanceMigration`): a conflict or a refused snapshot is skipped and retried on the next run.
- **The `spec` guard** (`GuardRegistry::enableSpecGuard()`, opt-in) takes a document of the specification codec (`{"document": {"v": 1, "spec": {...}}}`, `GuardRegistry::specRef()` writes it) and evaluates it over the context given to `send()`/`can()`, so a screen can edit a condition such as `amount < 10000` with no code; a document the codec refuses is a validation error. Enabling it lets whoever edits the definition write any condition the codec reads over the context (the codec's limits apply; it reads no callback, table or service).
- **Presentation data stays out of the definition**: a label, a color, an icon or the list of reasons of a screen belong to the application, keyed by machine key and state name or transition id, so changing a color never creates a version.

#### 23.3 Tenants: One Machine, Several Agencies

An instance may belong to a tenant: an opaque text chosen by the application (an agency id 7 goes as `"7"`), fixed when it starts and kept by every step and migration. The tenant is whose instance it is, not who acts on it (that is the `actor`). One definition serves every tenant. With a tenant, the runner loads the instance through `ITenantScopedInstanceStore::loadForTenant()`: another tenant's instance is `MachineInstanceNotFoundException`, the same error as an unknown id, so a screen of one agency never learns that an id of another exists. `findDue()` and `deliverDue()` without a tenant stay global (the operator's routine delivers the deadlines of everybody).

```php
use Antevemus\ASpecification\StateMachine\Exceptions\MachineInstanceNotFoundException;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoMachineSchema;

// The same $pdo, $definitions, $guards and $at; the opt-in columns of 2.7.0 (the codes come from $definitions)
$persistence = MachinePersistence::pdo($pdo, columns: PdoMachineSchema::COLUMNS_SINCE_2_7, definitions: $definitions);
$runner = new MachineRunner(Spec::stateMachine($guards), $definitions, $persistence);

$runner->start('q-7-1', 'quote', null, null, $at, actor: 'ana', tenant: '7');    // agency 7, latest version
$runner->start('q-12-1', 'quote', null, null, $at, actor: 'bob', tenant: '12');  // agency 12, the same definition
$calculated = $at->modify('+1 hour');
$runner->send('q-7-1', Event::external('COMPLETE_CALCULATION', $calculated), tenant: '7')->isAccepted();   // true
$runner->send('q-12-1', Event::external('COMPLETE_CALCULATION', $calculated), tenant: '12')->isAccepted(); // true

try {
    $runner->send('q-7-1', Event::external('SEND_PROPOSAL', $calculated), tenant: '12');
} catch (MachineInstanceNotFoundException $e) {
    $e->getMessage();                                // 'Machine instance "q-7-1" does not exist.'
}

$due = $runner->findDue($calculated->modify('+2 days'), tenant: '7');
array_map(fn($deadline) => $deadline->instanceId, $due); // ['q-7-1']: agency 12's deadline is not there
$runner->deliverDue($calculated->modify('+2 days'), tenant: '7')[0]->isDelivered(); // true

$pdo->query("SELECT current_state_code FROM state_machine_region WHERE instance_id = 'q-12-1' AND region = 'progress'")->fetchColumn(); // '2' ('2' in a VARCHAR column, 2 in an INTEGER one)
```

The projection of the codes lets the legacy reports keep working in SQL, with the column declared `INTEGER` and a foreign key to the legacy table when every code is an integer:

```sql
SELECT r.instance_id, r.tenant, s.label
  FROM state_machine_region r
  JOIN legacy_states s ON s.id = r.current_state_code
 WHERE r.region = 'progress' AND r.tenant = '7';
```

- **The new columns are opt-in.** The DDL of 2.7.0 adds `tenant` to `state_machine_instance` and `state_machine_region` (with `UNIQUE (tenant, machine_key, due_at, instance_id, region)`, so `findDueForTenant()` reads the projection without a join), `current_state_code` to `state_machine_region` and `from_state_code`/`to_state_code` to `state_machine_history`. The stores read and write them only with `columns: PdoMachineSchema::COLUMNS_SINCE_2_7` (and the definition repository: mapping the code columns without it is refused when the store is built), so a table created by an older DDL keeps working untouched after the update. A new installation passes the constant; an existing one runs once the additive migration commented in the DDL's header (five `ADD COLUMN` and one `CREATE UNIQUE INDEX`, for SQLite, PostgreSQL, MySQL and MariaDB) and then passes it. Without the columns, an instance without tenant is written as before, while `start(tenant: ...)` and a tenant search are refused with a message that names the column.
- **History, outbox, idempotency and rejections have no tenant column**: they are read by instance, after a load already confined to the tenant.
- **A store of your own** gains the tenant by implementing `ITenantScopedInstanceStore` (and `IMachineInstanceLister` for `migrateAll()`); asking a tenant of a store without it is a `StateMachineException`. No existing interface gained a method.

> [!NOTE]
> **Deadlines are materialized.** The due instant is computed when the state is entered (and when a paused deadline resumes) and then stored; a later change of the inputs, such as a holiday registered after the start, does not move a deadline already running. An application that needs it moves the instances with a new version and a migration.
