# TCriteria Without Accents and With Subqueries

### 22.1. TCriteria Without Accents and With Subqueries (Module 36)

`toCriteria()` refused a comparison that ignores case and accents (the folding is a different expression per database, and a TCriteria does not know which database will run it) and had no subquery but the raw `NOESC:(SELECT ...)` of Adianti, which the visitor refuses (`#`, `--` and `/*` are removed from the whole text, and nothing is bound). Since v2.5.0 the database is declared, and the subqueries are leaves of their own, for `toSql()` and `toCriteria()`.

```php
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\String\TextComparison;
use Antevemus\ASpecification\Sql\Dialects\PostgreSqlDialect;

// A search on PostgreSQL ignoring case and accents, written as toSql() writes it; the connection runs with prep = 1
$byName = Spec::property('name', Spec::contains('jose', TextComparison::IgnoreCaseAndAccents));
$criteria = Spec::toCriteria($byName, accentFolding: new PostgreSqlDialect('f_fold'));   // the function of example 20
$criteria->dump(true);         // (f_fold(lower(name)) LIKE f_fold(lower(:aspf_1)))
$criteria->getPreparedVars();  // [':aspf_1' => '%jose%']

// The states of a module, instead of NOESC:(SELECT ...): its values bound, its literals intact
$moduleId = 7;
$states = Spec::inSubquery('stateId', 'module_states', 'id',
    Spec::property('moduleId', Spec::equalTo($moduleId))->and(Spec::property('deletedAt', Spec::isNull())));
$criteria = Spec::toCriteria($states);
$criteria->dump(true);
// (state_id IN (SELECT sq.id FROM module_states sq WHERE sq.id IS NOT NULL AND (sq.module_id = :par_1 AND sq.deleted_at IS NULL)))
$criteria->getPreparedVars();  // [':par_1' => 7]

// A related row exists: one statement for a query written by hand
$withReport = Spec::existsIn('claim_document', ['claim_id' => 'id'], Spec::property('kind', Spec::equalTo('report')), 'claim');
$where = Spec::toSql($withReport, 'pgsql');
$where->toSql();
// EXISTS (SELECT 1 FROM "claim_document" "sq" WHERE "sq"."claim_id" = "claim"."id" AND (("sq"."kind" IS NOT NULL AND "sq"."kind" = :ps1_1)))
$where->getParameters();       // [':ps1_1' => 'report']
$withReport->evaluate((object) ['id' => 1])->isError;   // true: a subquery is evaluated by the database only
```

- **Accents in a TCriteria** (`Spec::toCriteria(..., accentFolding:)`, `TCriteriaBuilder::withAccentFolding()`, a dialect or its name). The leaves of example 20 become a filter that writes exactly the condition `toSql()` writes for that dialect, with the value bound raw under a placeholder of its own (`:aspf_N`) and folded by the database on both sides, plus `ESCAPE '!'` when the pattern needs it; a negation accepts the NULL column, as every negated comparison. Three conditions: the database (PostgreSQL 13+ in UTF8, through the built-in folding of about 10 KB per condition or, better, the `f_fold` function of example 20 with an expression index; MySQL/MariaDB through the accent-insensitive collation, `regex()` refused as in `toSql()`; any other dialect keeps refusing), **a connection with `prep = 1`** (the value is never concatenated: with `prep = 0` the unbound placeholder is an SQL error), and the limits of `toSql()` for the same dialect. Without the dialect, the refusal is the one of 2.1.0, now pointing to `withAccentFolding()`.
- **Subqueries** (`Spec::inSubquery($property, $table, $column, $where, $innerFieldMap)` and `Spec::existsIn($table, $on, $where, $outerTable, $innerFieldMap)`). The other table is read as `sq`, its filter is an ordinary specification over its columns translated by the same visitor (values bound, `(SELECT`/`NOESC:` still refused inside), the selected column is always filtered with `IS NOT NULL` (so `NOT IN` stays two-valued), and `not()` gives `NOT IN` (true for a NULL column) and `NOT EXISTS`. `$on` maps a column of the other table to a property of the candidate, which goes through the outer field mapping and is qualified with `$outerTable` (in a TCriteria, the table of the model). Identifiers are validated when the specification is built: plain segments (`[A-Za-z_][A-Za-z0-9_$]*`) or segments between double quotes (`"aflow-engine".module_states`, re-quoted by the dialect in `toSql()`), anything else is `UnsafeIdentifierException`; no literal ever enters the SQL text. In memory a subquery is an evaluation error (`DatabaseOnlySpecificationException`, never true nor false); the codec refuses it (a rule document of an editable catalog must not name tables); a `PdoRepository` keeps `Spec::inQuery()`, which applies its mapping and read scope. The other table gets no soft delete nor prefilters of an ORM: write `deletedAt IS NULL` in `$where`. Out of reach: `JOIN`, `UNION` and aggregates inside the subquery.
