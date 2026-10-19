# Dynamic Rule Engine & Relational Database Catalogs

### 7. Dynamic Rule Engine & Relational Database Catalogs (Module 11)

Compile dynamic rules and mandatory document matrices directly from relational database tables (e.g. PostgreSQL, MySQL):

> [!NOTE]
> **Vocabulary note.** The rule engine is English end to end: getters (`getCode()`, `getIntValue()`, `getViolationMessage()`, `getLegalBasis()`), named parameters (`scope:`, `scenario:`), `fromArray()` keys and catalog columns (`code`, `rule_type`, `violation_action`, `product_code`, `valid_from`...), context keys (`product_code`, `plan_code`, `reference_date`), failure metadata (`action`, `rule_code`, `legal_basis`...) and the action values `block`/`warn`/`log`. The Portuguese vocabulary of v1.6, legacy from v1.7.0 to v1.14.x, is refused since v2.0.0 with `LegacyVocabularyException`, never ignored: a Portuguese key or action value in a catalog, a context key, a table role, a handler's metadata key. [UPGRADE-2.0.md](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/UPGRADE-2.0.md) has the key table and the SQL that migrates a catalog; `PdoRuleCatalog` still reads tables created with the old schema through two presets (below), which map names only.

```php
use Antevemus\ASpecification\Spec;

// 1. Configure the Registry with pluggable rule handlers
$registry = Spec::ruleRegistry();
$registry->registerClosure('max_occurrences_per_contract', function ($rule) {
    return Spec::specify(Contract::class)
        ->must(fn($c) => $c->getOccurrencesCount() <= $rule->getIntValue(), $rule->getCode(), $rule->getViolationMessage());
});

// 2. Instantiate the Engine connected to the relational catalog repository
$engine = Spec::engine($myCatalogRepository, $registry);

// 3. Validate target entity for a specific scope and scenario
$verdict = $engine->validate(
    target: $contract,
    scope: 'rental_contract',
    scenario: 'activation'
);

if ($verdict->hasBlockingErrors()) {
    return response()->json([
        'status' => 403,
        'errors' => $verdict->getBlockingFailures(),
        'legal_bases' => $verdict->getLegalBases(),
    ], 403);
}

if ($verdict->hasWarnings()) {
    NotificationService::dispatch($verdict->getWarningFailures());
}
```

The catalog can live in relational tables: `PdoRuleCatalog` reads the tables of the reference DDL [`resources/sql/rule_catalog.ansi.sql`](https://github.com/antevemus-it/Antevemus.ASpecification/blob/main/resources/sql/rule_catalog.ansi.sql) (`business_rule`, `document_requirement_group`, `document_requirement`) in portable, parameterized ANSI SQL and answers exactly like the in-memory catalog. Table and column names are configurable (`$tables`, `$columns`: canonical column => real column). Since v2.4.0 the body of a rule can be a serialized specification (`spec_json`, read with `columns: PdoRuleCatalog::COLUMNS_SINCE_2_4`), built by the codec instead of a handler, and `findRuleByCode()` finds a rule by its code (example 21):

```php
use Antevemus\ASpecification\Engine\PdoRuleCatalog;

// Optional: read only the rules in force on a date (validity window filtered in SQL)
$catalog = (new PdoRuleCatalog($pdo))->withAsOf(new DateTimeImmutable('2026-10-09'));

// Tables created with the Portuguese schema of v1.5/v1.6 (regra_negocio...) are read by name; their action values must be English since v2.0 (UPGRADE-2.0.md)
$legacyCatalog = new PdoRuleCatalog(
    $pdo,
    'rental_guarantee',
    PdoRuleCatalog::legacyPortugueseTables(),
    PdoRuleCatalog::legacyPortugueseColumns()
);

$verdict = Spec::engine($catalog, $registry)->validate(
    target: $contract,
    scope: 'rental_contract',
    context: ['product_code' => 'PROD_A']
);
```

---

Since v2.5.0: rule types that only hold a value the application reads (`registerLookupOnly()`, skipped and listed by `getSkippedRules()` instead of a `MissingRuleHandlerException`), and the most specific rule of a type (`RulePrecedence`, `findRulesByType()`: the scenario over the scope-global rule, then plan over product over none, then priority, then `code`); see example 22.
