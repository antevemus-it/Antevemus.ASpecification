# Validity Window and Percentage Rollout of a Flag

### 19.1. Validity Window and Percentage Rollout of a Flag (Module 30)

Since v2.3.0 the service sets the two things that only SQL or a migration could write before: the validity window of a flag and a percentage rollout. Both go through the audited path of `toggle()` (a reason, one transaction with its history row) with a compare-and-set on the row version, so a screen that read an old version cannot overwrite a newer change. `Agency` is the class of example 19.

```php
use Antevemus\ASpecification\FeatureFlags\FeatureFlagChange;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagDefinition;
use Antevemus\ASpecification\FeatureFlags\FeatureFlagRegistry;
use Antevemus\ASpecification\FeatureFlags\FeatureFlags;
use Antevemus\ASpecification\FeatureFlags\PdoFeatureFlagStore;

// $pdo: the tables of resources/sql/feature_flags.ansi.sql (2.3.0), or the 2.1.0 ones after the migration in its header
$registry = new FeatureFlagRegistry([
    new FeatureFlagDefinition('renewal_notice', 'Renewal notice to agencies', subjectKey: 'unitId'),
]);
$store = new PdoFeatureFlagStore($pdo, columns: PdoFeatureFlagStore::COLUMNS_SINCE_2_3);
$flags = new FeatureFlags($registry, $store);

$flags->toggle('renewal_notice', true, 'Launch approved by the board', actor: 42);

// Only from November 1st to December 31st (both inclusive)
$flags->setValidity(
    'renewal_notice',
    new DateTimeImmutable('2026-11-01T00:00:00Z'),
    new DateTimeImmutable('2026-12-31T23:59:59Z'),
    'Year-end campaign',
    actor: 42,
);

// A quarter of the agencies first: a stable bucket per agency (0 to 9999)
$flags->setRollout('renewal_notice', 25, 'Gradual launch, first quarter', actor: 42);

$agency = new Agency(unitId: 7);
$november = $flags->withAsOf(new DateTimeImmutable('2026-11-15T12:00:00Z'));
$evaluation = $november->evaluate('renewal_notice', $agency);
$evaluation->reason;    // FeatureFlagReason::OUTSIDE_ROLLOUT (TARGETING_MATCH when the bucket is below 2500)
$evaluation->bucket;    // 8912: the same number in every request, process and machine
$flags->withAsOf(new DateTimeImmutable('2027-01-01T00:00:00Z'))->evaluate('renewal_notice', $agency)->reason; // OUTSIDE_VALIDITY

$flags->setRollout('renewal_notice', 100, 'Every agency now', actor: 42);
$flags->withAsOf(new DateTimeImmutable('2026-11-15T12:00:00Z'))->isEnabled('renewal_notice', $agency); // true

array_map(
    fn(FeatureFlagChange $change): string => $change->kind->value . ($change->detail !== null ? ': ' . $change->detail : ''),
    $flags->history('renewal_notice'),
);
// ['rollout: 25% → 100%', 'rollout: none → 25%', 'validity: 2026-11-01T00:00:00+00:00 → 2026-12-31T23:59:59+00:00', 'toggle']
```

- **Validity window.** `setValidity($code, $from, $until, $reason, $actor, $origin, $expectedVersion)`: both bounds inclusive, `null` an open side, both `null` clear the window; `from > until` is refused with `InvalidArgumentException` before anything is written. The switch is not touched: the window does not turn the flag on, it limits when "on" holds (`OUTSIDE_VALIDITY` outside it).
- **Rollout.** `setRollout($code, $percent, ...)`, from 0 to 100, `null` for none (every subject). A subject is in when `FeatureFlags::bucket($code, $subjectKey) < $percent * 100`. The bucket is the first 8 hex digits of `xxh3($code . "\0" . $subjectKey)` modulo `FeatureFlags::ROLLOUT_BUCKETS` (10 000): the same in every request, process and machine, independent between flags, and part of the contract (changing it would be a MAJOR); raising the percentage never takes a subject out. Measured over 10 000 subjects, 1 %, 10 %, 25 %, 50 % and 90 % land within ±1 % of what was asked.
- **Where the rollout enters the evaluation.** Last: switch, validity window, subject list, target in code, then rollout. With a rollout, a subject without a key is `NOT_IN_TARGET` (100 % included); one inside is `TARGETING_MATCH` and one outside `OUTSIDE_ROLLOUT` (OpenFeature `DEFAULT`). `toMetadata()`, and so the failure of `spec()`, carries `bucket` and `rollout_percent`, so a panel can say why an agency is out.
- **Concurrency.** Each change is a compare-and-set on `version` (`toggle()` increments it too, so a window read before a toggle is stale after it); pass `expectedVersion:` with the version your screen showed to refuse a save over someone else's change. Setting what is already there throws `StaleFeatureFlagException` and writes nothing, as a second `toggle()` does. Both changes require a declared flag.
- **History and listing.** `FeatureFlagChange::$kind` (`FeatureFlagChangeKind`: `Toggle`, `Subject`, `Validity`, `Rollout`) and `$detail` (`2026-11-01T00:00:00+00:00 → open`, `cleared`, `none → 25%`, `25% → 100%`); `all()` gives the `validFrom`, `validUntil` and `rolloutPercent` of each flag.
- **The new columns are opt-in.** The DDL of 2.3.0 adds `feature_flag.rollout_percent` and `feature_flag_change.change_kind`/`detail`, and `PdoFeatureFlagStore` reads and writes them only when told so, with `columns: PdoFeatureFlagStore::COLUMNS_SINCE_2_3`, so that a table created by the DDL of 2.1.0 keeps working untouched after the update. A new installation passes the constant; an existing one runs once the additive migration commented at the end of the DDL's header (three `ADD COLUMN` and one `UPDATE`, for SQLite, PostgreSQL, MySQL 8.0.16+ and MariaDB) and then passes it. Without them there is no rollout (`setRollout()` is refused naming `rollout_percent`), the window works (its columns exist since 2.1.0) and the history has no kind nor detail. A store whose window columns are mapped to `null` refuses `setValidity()` with a message that names them.
- **Breaking for a store of your own:** `IFeatureFlagStore` gains `supportsValidity()`, `compareAndSetValidity()`, `supportsRollout()` and `compareAndSetRollout()`; `InMemoryFeatureFlagStore` and `PdoFeatureFlagStore` implement them.
