# SQL Query Visitor & Multi-SGBD (WHERE Parametrizado)

### 8. SQL Query Visitor & Multi-SGBD (WHERE Parametrizado)

Traduz diretamente árvores complexas de especificações de domínio para cláusulas `WHERE` parametrizadas, imunes a injeção SQL, com quoting automático de identificadores e funções nativas por SGBD:

```php
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Sql\SqlDialect;

// 1. Constrói a regra de domínio puro
$spec = Spec::property('active', Spec::equalTo(true))
    ->and(
        Spec::property('salary', Spec::greaterThan(5000))
            ->or(Spec::property('city', Spec::wildcard('New*')))
    );

// 2. Compila para PostgreSQL com mapeamento de colunas
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
// (booleanos são renderizados pelo dialeto; os demais valores vão por binding; cada comparação leva a
// guarda IS NOT NULL, então um NOT sobre coluna NULL dá a resposta da memória)

print_r($whereClause->getBindings());
// [':p1' => 5000, ':p2' => 'New%']

// 3. Suporte a 15 drivers em 10 dialetos SQL (SQL Server, Oracle, Firebird, MySQL, SQLite, Db2, Informix, DuckDB, etc.)
$whereSqlServer = Spec::toSql($spec, SqlDialect::SQLSRV);
// '(([active] IS NOT NULL AND [active] = 1) AND (([salary] IS NOT NULL AND [salary] > :p1) OR ([city] IS NOT NULL AND [city] LIKE :p2)))'

$whereMySql = Spec::toSql($spec, SqlDialect::MYSQL);
// '((`active` IS NOT NULL AND `active` = 1) AND ((`salary` IS NOT NULL AND `salary` > :p1) OR (`city` IS NOT NULL AND CONVERT(`city` USING utf8mb4) COLLATE utf8mb4_bin LIKE :p2)))'
```

---

Desde a v2.5.0 o visitor de SQL traduz subqueries sobre qualquer tabela, `Spec::inSubquery()` e `Spec::existsIn()`, com os identificadores validados na construção e os parâmetros do filtro interno com nomes próprios (`:ps1_1`); veja o exemplo 22.1.
