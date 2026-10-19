# Declared Feature Flags With Targeting and an Audit Trail

### 19. Declared Feature Flags With Targeting and an Audit Trail (Module 27)

`Antevemus\ASpecification\FeatureFlags` turns "is this feature on for this customer?" into a declared, audited decision. A flag is declared once, in code: its code, the texts a settings screen shows (name, description, category, audience, what turning it on and off does), where it came from and, optionally, its target. The database holds only what changes at run time: the switch, the validity window, the subject list and the history (reference DDL [`resources/sql/feature_flags.ansi.sql`](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/resources/sql/feature_flags.ansi.sql), portable SQL in the conventions of the other DDLs of the package). Every flag is born off, and every answer has a reason.

```php
use Antevemus\ASpecification\FeatureFlags\Exceptions\StaleFeatureFlagException;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagDefinition;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagReason;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagRegistry;
use Antevemus\ASpecification\FeatureFlags\FeatureFlags;
use Antevemus\ASpecification\FeatureFlags\PdoFeatureFlagStore;
use Antevemus\ASpecification\Specifications\PredicateSpecification;

final class Agency
{
    public function __construct(public readonly int $unitId, public readonly string $plan = 'basic') {}
}

// $pdo: once, with your migration tool, resources/sql/feature_flags.ansi.sql
$registry = new FeatureFlagRegistry([
    new FeatureFlagDefinition(
        'renewal_notice',
        'Renewal notice to agencies',
        impactOn: 'Agencies start receiving the renewal e-mail.',
        impactOff: 'Agencies stop receiving it.',
        origin: 'task 0033',
        subjectKey: 'unitId',
    ),
]);
$flags = new FeatureFlags($registry, new PdoFeatureFlagStore($pdo));

$seven = new Agency(unitId: 7);
$flags->isEnabled('renewal_notice', $seven);                              // false: every flag is born off

// The pilot: on, but only for agency 7 (a subject list, changed without a deploy)
$flags->toggle('renewal_notice', true, 'Pilot approved by the board', actor: 42);
$flags->addSubject('renewal_notice', 7, 'Pilot: agency 7 only', actor: 42);

$flags->isEnabled('renewal_notice', $seven);                              // true
$flags->evaluate('renewal_notice', new Agency(unitId: 8))->reason === FeatureFlagReason::NOT_IN_TARGET; // true
$flags->isEnabled('not_declared');                                        // false (UNKNOWN_FLAG), never an exception

// A flag is a specification: compose it with any other rule
$notify = $flags->spec('renewal_notice')->and(new PredicateSpecification(fn(Agency $a): bool => $a->plan !== 'trial'));
$notify->isSatisfiedBy($seven);                                           // true

try {
    $flags->toggle('renewal_notice', true, 'Second click on a stale screen');
} catch (StaleFeatureFlagException $e) {
    // it is already on: every change is a compare-and-set, and nothing was written
}
$flags->history('renewal_notice')[0]->reason;                             // 'Pilot: agency 7 only' (most recent first)
```

- **When a flag is on.** Its switch is on, **and** the reference instant is inside its validity window (`valid_from`/`valid_until`, both inclusive, a date-only bound covering the whole day; `withAsOf()` evaluates at another instant), **and** the subject is in its target. The target has two parts, both optional and combined with AND: the subject list stored with the flag (`addSubject()`/`removeSubject()`; an empty list is no restriction; the key is read from the subject through `subjectKey` with the shared accessor, and a string or integer subject is the key itself) and a specification declared in code (`target: Spec::property('plan', Spec::in('pro', 'enterprise'))`). With a target, a null subject is not in it.
- **Every answer has a reason.** `evaluate()` returns a `FeatureFlagEvaluation` (value, `FeatureFlagReason`, subject key, error; `toMetadata()`), and the failure of `spec()` carries the same metadata. The reasons map to those of [OpenFeature](https://openfeature.dev), so a provider can be written over the service later: `ENABLED` → `STATIC`, `TARGETING_MATCH` → `TARGETING_MATCH`, `DISABLED` → `DISABLED`, `NOT_IN_TARGET` and `OUTSIDE_VALIDITY` → `DEFAULT`, `UNKNOWN_FLAG` → `ERROR` (`FLAG_NOT_FOUND`), `ERROR` → `ERROR` (`GENERAL`).
- **Reading never throws and never fails in silence.** A code that is not declared is off, whatever the database says. When the store cannot be read (no table yet, the connection down) or the target throws, the flag is off with the reason `ERROR` (fail closed) and the exception goes to the `IFeatureFlagErrorReporter` given to the service, once per load; `evaluate()->error` and `lastError()` keep it for a settings screen. As a specification such a flag is a plain failure with the reason `error`, so `not()` sees it off, and its metadata never carry the exception's message.
- **Changes.** `toggle()`, `addSubject()` and `removeSubject()` each run in one transaction with their history row and require a reason (whitespace collapsed, 10 to 1 000 characters by default, `InvalidFeatureFlagReasonException`). Each is a compare-and-set: turning on expects off, so a second click on a stale screen throws `StaleFeatureFlagException` and writes nothing. Turning on an undeclared flag throws `UndeclaredFeatureFlagException` (it would have no effect); turning it off stays allowed, to clean up an orphan row. A declared flag without a row gets one, off, on its first change. The service records the actor and the origin (`FeatureFlags::ORIGIN_UI`, `ORIGIN_MIGRATION`, `ORIGIN_SCRIPT`, or a text of your own); who may change a flag is your application's decision.
- **One query per service instance**: the state of every flag and the subject lists are read on the first evaluation (flags `LEFT JOIN` subjects) and kept; `refresh()` reads again, and a change made through the instance refreshes it. Build one service per request.
- **Listing.** `all()` is what a settings screen needs: the declared flags in declaration order, then those found only in the database (marked as not declared), each with its texts (the code's win), state, subjects and last change; `history($code, $limit)` is the trail, most recent first.

`InMemoryFeatureFlagStore` has the same contract (the same test battery runs against both, and against PostgreSQL 17, MySQL 8.4 and MariaDB 11.4). Existing tables are read where they are, with the conventions of `PdoRuleCatalog`: table names by role, column names by role (canonical column => real column; `null` for a feature the table does not have), the Boolean storage of each role and a history table shared with other audit trails:

```php
use Antevemus\ASpecification\FeatureFlags\PdoFeatureFlagStore;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\BooleanStorage;

// An integer switch with soft delete, no validity window, no subject lists, and an audit table
// shared with other trails (identity key, 'true'/'false' as text, local time without offset)
$store = new PdoFeatureFlagStore(
    $pdo,
    tables: ['flag' => 'app_feature_flags', 'change' => 'audit_trail', 'subject' => null],
    columns: [
        'flag' => ['valid_from' => null, 'valid_until' => null, 'version' => null, 'deleted_at' => 'deleted_at'],
        'change' => ['change_id' => 'id', 'flag_code' => 'entity_key', 'subject_key' => null, 'old_enabled' => 'old_value', 'new_enabled' => 'new_value'],
    ],
    changeScope: ['entity_type' => 'feature_flag', 'field' => 'enabled'],  // written on every row, required on every read
    booleans: ['flag' => BooleanStorage::Integer, 'change' => ['true', 'false']],
    timestampFormat: 'Y-m-d H:i:s',
    timezone: 'America/Sao_Paulo',
    databaseGeneratedChangeId: true
);
```

A stored Boolean outside the declared values is a `FeatureFlagStoreException`, never a silent `false`; a row whose `deleted_at` is set is neither read nor changed. Since v2.3.0 the validity window is set through the service, and a percentage rollout with it (example 19.1); the DDL carries, commented out, a PostgreSQL trigger that makes the history append-only in the database too. Since v2.4.0 the target can also be a rule of the catalog, named by its code (`targetRuleCode`, example 21).
