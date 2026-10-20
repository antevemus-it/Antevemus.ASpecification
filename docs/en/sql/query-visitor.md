# SQL Query Visitor & Multi-DBMS (`WHERE` Parameterization)

### 8. SQL Query Visitor & Multi-DBMS (`WHERE` Parameterization)

Translate pure domain specification trees directly into parameterized, injection-proof `WHERE` clauses with automatic identifier quoting and native dialect functions:

```php
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Sql\SqlDialect;

// 1. Build pure domain specification
$spec = Spec::property('active', Spec::equalTo(true))
    ->and(
        Spec::property('salary', Spec::greaterThan(5000))
            ->or(Spec::property('city', Spec::wildcard('New*')))
    );

// 2. Compile to PostgreSQL with domain-to-column mapping
$whereClause = Spec::toSql(
    specification: $spec,
    dialect: SqlDialect::POSTGRESQL,
    fieldMapper: [
        'active' => 'is_active',
        'salary' => 'val_salary',
        'city'   => 'txt_city'
    ]
);

echo $whereClause->getSql();
// '(("is_active" IS NOT NULL AND "is_active" = TRUE) AND (("val_salary" IS NOT NULL AND "val_salary" > :p1) OR ("txt_city" IS NOT NULL AND "txt_city" LIKE :p2)))'
// (booleans are rendered by the dialect; the remaining values are bound; each comparison carries an
// IS NOT NULL guard, so a NOT over a NULL column gives the in-memory answer)

print_r($whereClause->getBindings());
// [':p1' => 5000, ':p2' => 'New%']

// 3. First-class support for 15 database drivers across 10 SQL dialects (SQL Server, Oracle, Firebird, MySQL, SQLite, Db2, Informix, DuckDB, etc.)
$whereSqlServer = Spec::toSql($spec, SqlDialect::SQLSRV);
// '(([active] IS NOT NULL AND [active] = 1) AND (([salary] IS NOT NULL AND [salary] > :p1) OR ([city] IS NOT NULL AND [city] LIKE :p2)))'

$whereMySql = Spec::toSql($spec, SqlDialect::MYSQL);
// '((`active` IS NOT NULL AND `active` = 1) AND ((`salary` IS NOT NULL AND `salary` > :p1) OR (`city` IS NOT NULL AND CONVERT(`city` USING utf8mb4) COLLATE utf8mb4_bin LIKE :p2)))'
```

---

Since v2.5.0 the SQL visitor translates subqueries over any table, `Spec::inSubquery()` and `Spec::existsIn()`, with identifiers validated when they are built and the parameters of the inner filter named apart (`:ps1_1`); see example 22.1.
