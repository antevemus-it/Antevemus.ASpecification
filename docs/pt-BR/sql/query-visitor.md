# SQL Query Visitor & Multi-SGBD (WHERE Parametrizado)

### 8. SQL Query Visitor & Multi-SGBD (WHERE Parametrizado)

Traduz diretamente árvores complexas de especificações de domínio para cláusulas `WHERE` parametrizadas, imunes a injeção SQL, com quoting automático de identificadores e funções nativas por SGBD:

```php
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Sql\SqlDialect;

// 1. Constrói a regra de domínio puro
$spec = Spec::property('ativo', Spec::equalTo(true))
    ->and(
        Spec::property('salario', Spec::greaterThan(5000))
            ->or(Spec::property('cidade', Spec::wildcard('São*')))
    );

// 2. Compila para PostgreSQL com mapeamento de colunas
$whereClause = Spec::toSql(
    specification: $spec,
    dialect: SqlDialect::POSTGRESQL,
    fieldMapper: [
        'ativo'   => 'st_ativo',
        'salario' => 'vl_salario',
        'cidade'  => 'ds_cidade'
    ]
);

echo $whereClause->getSql();
// '(("st_ativo" IS NOT NULL AND "st_ativo" = TRUE) AND (("vl_salario" IS NOT NULL AND "vl_salario" > :p1) OR ("ds_cidade" IS NOT NULL AND "ds_cidade" LIKE :p2)))'
// (booleanos são renderizados pelo dialeto; os demais valores vão por binding; cada comparação leva a
// guarda IS NOT NULL, então um NOT sobre coluna NULL dá a resposta da memória)

print_r($whereClause->getBindings());
// [':p1' => 5000, ':p2' => 'São%']

// 3. Suporte a 15 drivers em 10 dialetos SQL (SQL Server, Oracle, Firebird, MySQL, SQLite, Db2, Informix, DuckDB, etc.)
$whereSqlServer = Spec::toSql($spec, SqlDialect::SQLSRV);
// '(([ativo] IS NOT NULL AND [ativo] = 1) AND (([salario] IS NOT NULL AND [salario] > :p1) OR ([cidade] IS NOT NULL AND [cidade] LIKE :p2)))'

$whereMySql = Spec::toSql($spec, SqlDialect::MYSQL);
// '((`ativo` IS NOT NULL AND `ativo` = 1) AND ((`salario` IS NOT NULL AND `salario` > :p1) OR (`cidade` IS NOT NULL AND BINARY `cidade` LIKE :p2)))'
```

---

Desde a v2.5.0 o visitor de SQL traduz subqueries sobre qualquer tabela, `Spec::inSubquery()` e `Spec::existsIn()`, com os identificadores validados na construção e os parâmetros do filtro interno com nomes próprios (`:ps1_1`); veja o exemplo 22.1.
