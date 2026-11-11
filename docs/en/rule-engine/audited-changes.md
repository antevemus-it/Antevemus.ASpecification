# Audited Changes to the Rule Catalog: Versions, Photos and History

### 25. Versions, Photos and History (Module 47)

Since v2.13.0 the catalog that `PdoRuleCatalog` reads has a write path with an audit trail, in the shape of the feature flags (example 19): `RuleCatalogEditor`. Every change of a rule makes a new **version** of it, with the full **photo** of the rule after the change, the hash of that photo and the **reason**, in one transaction. "Version 3 of rule `max_rent`" is then one row of the history, which an analysis or a quotation can cite: the rule as it was when it applied, not as it is today.

```php
use Antevemus\ASpecification\Engine\Exceptions\StaleRuleChangeException;
use Antevemus\ASpecification\Engine\PdoRuleCatalog;
use Antevemus\ASpecification\Engine\PdoRuleCatalogStore;
use Antevemus\ASpecification\Engine\RuleCatalogEditor;

// $pdo: the tables of resources/sql/rule_catalog.ansi.sql, after the migration 2.13.0 of its header
$catalog = new PdoRuleCatalog($pdo, columns: PdoRuleCatalog::COLUMNS_SINCE_2_13);
$editor = new RuleCatalogEditor(PdoRuleCatalogStore::forCatalog($pdo, $catalog));

$v1 = $editor->create(
    ['code' => 'max_rent', 'name' => 'Maximum rent', 'rule_type' => 'max_rent', 'scope' => 'contract', 'int_value' => 5000],
    'Ceiling approved by the credit committee',
    actor: 'ana'
);
$v2 = $editor->update('max_rent', ['int_value' => 6000], 'Ceiling raised after the market review', actor: 'bia', expectedVersion: $v1->version);

$r1 = $v2->version;                                                   // 2
$r2 = $v2->changedFields;                                             // ['int_value']
$r3 = $editor->revision('max_rent', 1)?->definition()->getIntValue(); // 5000: version 1 as it was
$r4 = $v2->isIntact();                                                // true: the hash is the hash of the photo
$r5 = $catalog->findRuleByCode('max_rent')?->getVersion();            // 2

try {
    $editor->update('max_rent', ['int_value' => 7000], 'A screen opened before the last change', expectedVersion: 1);
} catch (StaleRuleChangeException $e) {
    $r6 = $e->getMessage(); // Rule "max_rent" changed since it was read (version 1, now 2): reload and try again.
}
```

- **The migration** is additive and optional, in the header of `resources/sql/rule_catalog.ansi.sql`: `ALTER TABLE business_rule ADD COLUMN version INTEGER DEFAULT 1 NOT NULL CHECK (version >= 1)` and `CREATE TABLE IF NOT EXISTS business_rule_change (...)`. Without it nothing of the library changes: `PdoRuleCatalog` reads the same columns with the same SQL, and every failure carries the same metadata. The editor checks both before each call and refuses with a message that points to that header. `PdoRuleCatalog` reads the version only when told so (`COLUMNS_SINCE_2_13`, merged with any other mapping, e.g. `[...PdoRuleCatalog::COLUMNS_SINCE_2_4, ...PdoRuleCatalog::COLUMNS_SINCE_2_13]`), and `PdoRuleCatalogStore::forCatalog()` writes the table and the columns that catalog reads. `InMemoryRuleCatalogStore` is the same contract in memory.
- **Writing.** `create($row, $reason)` makes version 1; `update($code, $changes, $reason)` merges the given columns over the current row; `setActive($code, $active, $reason)` puts a rule in or out of use. There is no deletion: a rule leaves use through `setActive(false)`, and its history stays. The row uses the keys of `RuleDefinition::fromArray()` (`RuleCatalogEditor::INPUT_COLUMNS`): an unknown key, a Portuguese key of 1.x, a value of the wrong type, a changed `code`, `active` given to `update()` or a `version` given at all is an `InvalidArgumentException`. Product, plan and validity live in their columns, never inside `parameters`.
- **Compare-and-set of the version.** Every change is `UPDATE ... SET ..., version = version + 1 WHERE code = ? AND version = ?`: of two changes of the same version, one wins and the other gets `StaleRuleChangeException`, never both. `expectedVersion` is the version the screen showed (null = the one read in the transaction). A change that leaves the row as it is, and a rule already in the requested state, are refused the same way: nothing is written.
- **The reason** follows the rules of the feature flags: whitespace runs collapsed, the ends trimmed, 10 to 1000 characters by default (`InvalidRuleChangeReasonException`). The origin is `ORIGIN_UI`, `ORIGIN_MIGRATION`, `ORIGIN_SCRIPT` or the application's own text; the actor is recorded as given (who may change a rule is a decision of the application).
- **The photo** (`RuleSnapshot`) is the JSON of every column of the rule, `version` and `active` included, keys in alphabetical order, `parameters` with the keys of every object in order, the decimal as canonical text, dates as `YYYY-MM-DD`, null written; its hash is `sha256:` and 64 hexadecimal digits. The same row gives the same text and the same hash in PostgreSQL, MySQL, MariaDB, SQLite and memory. The form is a contract: changing it is a MAJOR change. `RuleRevision::isIntact()` computes the hash again: false means the history row was edited by hand. `definition()` gives the rule of that version as the catalog reads it.
- **Messages** name the rule, the column and the version, never a column value, the reason nor the value received; the driver message of a failed statement stays in the previous exception (`RuleCatalogStoreException`, with the SQLSTATE).
- **History.** `current($code)` is the last revision, `revision($code, $version)` one version, `history($code, $limit)` the trail, most recent first. Each row has the kind of the change (`create`, `update`, `activate`, `deactivate`, `baseline`) and the columns it changed, in alphabetical order.

### 25.1. Rules That Existed Before the Migration, and Changes Made by Hand

A rule that existed before the migration has version 1 and no history. Its first change through the editor writes, in the same transaction and before the row of the change, a `baseline` row with the photo of its current version, so that every version from then on has its photo; `adopt()` writes those baselines without changing anything. `findDrift()` is the portable safety net against a change made outside the editor.

```php
use Antevemus\ASpecification\Engine\Exceptions\RuleCatalogDriftException;
use Antevemus\ASpecification\Engine\RuleDrift;
use Antevemus\ASpecification\Engine\RuleRevision;

// min_income was in business_rule before the migration: version 1, no history
$adopted = $editor->adopt(null, 'Rules of the catalog before the audited path');
$r1 = array_map(static fn(RuleRevision $baseline): string => $baseline->code . '@' . $baseline->version, $adopted); // ['min_income@1']

$pdo->exec("UPDATE business_rule SET int_value = 4 WHERE code = 'min_income'"); // an UPDATE by hand
$r2 = array_map(static fn(RuleDrift $drift): string => $drift->code . ':' . $drift->kind->value, $editor->findDrift()); // ['min_income:modified']
try {
    $editor->update('min_income', ['int_value' => 5], 'Raise the minimum income');
} catch (RuleCatalogDriftException $e) {
    $r3 = $e->getDrift()->kind; // RuleDriftKind::Modified: the row is not the photo of its version
}
$pdo->exec("UPDATE business_rule SET version = version + 1 WHERE code = 'min_income'"); // the change by hand gets its own version
$r4 = $editor->update('min_income', ['int_value' => 5], 'Raise the minimum income')->version; // 3: the baseline of version 2 is written first
```

- **The four kinds** of `RuleDriftKind`: `no_history` (never changed nor adopted through the editor), `version_ahead` (the version was raised outside the editor), `modified` (the row is not the photo of its version: an `UPDATE` by hand that kept or lowered the version) and `deleted` (the history has the rule, the table does not). `adopt()` and the next change settle the first two with a baseline of the current version.
- **A modified row is not changed.** Writing on top of it would hide the change made by hand inside the next version: the editor refuses (`RuleCatalogDriftException`) until the version is raised by hand, which turns that change into a version of its own whose baseline the next change records. A rule deleted by hand cannot be created again with the same code.
- **What stays with the application**: a trigger that forbids writing `business_rule` outside the editor, or that makes the history append-only in the database (not portable; the header of `resources/sql/feature_flags.ansi.sql` shows one for PostgreSQL), and the alert when `findDrift()` finds something.

### 25.2. A Change Guard

`withChangeGuard($specification)` evaluates a specification over the change before anything is written, inside the transaction: the `RuleChangeCandidate` carries the kind, the rule before (null for a creation) and after, the version it leaves and the one it makes, the columns it changes, the actor, the origin and the reason. Not satisfied, or not evaluable, the change is refused (`RuleChangeRefusedException`, with the result of the guard) and nothing is written, baseline included. It is where an application plugs a rule of its own, such as "a rule that needs a legal opinion is not activated without a favourable one for this version", without the library knowing what an opinion is.

```php
use Antevemus\ASpecification\Engine\Exceptions\RuleChangeRefusedException;
use Antevemus\ASpecification\Engine\RuleChangeCandidate;
use Antevemus\ASpecification\Engine\RuleChangeKind;
use Antevemus\ASpecification\Spec;

$favourable = ['credit_profile' => [3]]; // the application's legal opinions: rule code => versions with a favourable one
$guarded = $editor->withChangeGuard(
    Spec::specify(RuleChangeCandidate::class)->must(
        static fn(RuleChangeCandidate $change): bool => $change->kind !== RuleChangeKind::Activate
            || in_array($change->toVersion, $favourable[$change->code] ?? [], true),
        'LEGAL_OPINION_REQUIRED',
        'This version of the rule has no favourable legal opinion'
    )
);

$guarded->create(['code' => 'credit_profile', 'name' => 'Credit profile', 'rule_type' => 'profile', 'scope' => 'contract', 'active' => false], 'Drafted by the risk team');
try {
    $guarded->setActive('credit_profile', true, 'Go live before the opinion');
} catch (RuleChangeRefusedException $e) {
    $r1 = $e->getResult()->getCodes(); // ['LEGAL_OPINION_REQUIRED']: nothing was written
}
$guarded->update('credit_profile', ['description' => 'Score of 600 or more'], 'Wording asked by the legal team');
$r2 = $guarded->setActive('credit_profile', true, 'Favourable opinion for version 3')->version; // 3
```

- `withChangeGuard()` returns a copy; the original editor is left untouched, and a second call replaces the guard (compose several with `and()`). The message of the exception lists the codes of the guard; its messages are in `getResult()`.
- Drafts, publication, approval in two steps and the opinion itself are not in the library: the guard is the hook where each application puts its own.

### 25.3. The Versions That Applied

A catalog that reads the version hands it to the engine: every failure of a versioned rule carries `metadata['rule_version']`, after the other keys of the rule, and `DynamicSpecificationEngine::getCompiledRules()` lists every rule compiled by the last validation, the satisfied ones included, so that the application records the code and the version of every rule that applied.

```php
use Antevemus\ASpecification\Contracts\Engine\IVersionedRuleDefinition;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\DynamicSpecificationEngine;
use Antevemus\ASpecification\Engine\RuleSpecificationRegistry;

$registry = new RuleSpecificationRegistry();
$registry->registerClosure('max_rent', static fn($rule): ISpecification => Spec::property('rent', Spec::lessThanOrEqualTo($rule->getIntValue())));
$registry->registerClosure('min_income', static fn($rule): ISpecification => Spec::property('income', Spec::greaterThanOrEqualTo($rule->getIntValue() * 1000)));
$registry->registerClosure('profile', static fn($rule): ISpecification => Spec::property('score', Spec::greaterThanOrEqualTo(600)));
$engine = new DynamicSpecificationEngine($catalog, $registry);

$verdict = $engine->validate(['rent' => 6500, 'income' => 9000, 'score' => 720], 'contract');
$r1 = $verdict->getBlockingFailures()[0]->metadata['rule_version']; // 2: max_rent failed in its version 2
$r2 = array_map(
    static fn(IVersionedRuleDefinition $rule): string => $rule->getCode() . '@' . $rule->getVersion(),
    $engine->getCompiledRules()
); // ['credit_profile@3', 'max_rent@2', 'min_income@3']: every rule that applied, the satisfied ones too
```

- `IVersionedRuleDefinition` extends `IRuleDefinition` with `getVersion()`; `RuleDefinition` implements it. `IRuleDefinition` does not change, so a class implementing it directly keeps working, and a rule without a version (a catalog without the mapping, a rule built in code) stamps exactly the metadata of 2.12.0. `getCompiledRules()` is a method of the engine class, filled by every compilation like `getSkippedRules()` (the lookup-only rules stay there).
- **Cache.** `CachedRuleCatalog` keeps the version of each rule. Give `PdoCatalogVersion::forRuleCatalog()` the history table of the editor (`changeTable: 'business_rule_change'`): every change through the editor appends to it, so the cache sees the change at once on every server. The largest `version` of the rule table would not do: changing a rule that is not the one with the largest version leaves it as it was. See [Caching](../caching/index.md).
- The full description of the engine is in [Dynamic rules](dynamic-rules.md); the lookup-only types and the precedence in [A real consumer](real-consumer.md).
