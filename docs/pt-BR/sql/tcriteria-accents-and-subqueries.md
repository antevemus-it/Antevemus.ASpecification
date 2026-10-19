# TCriteria Sem Acento e Com Subquery

### 22.1. TCriteria Sem Acento e Com Subquery (Módulo 36)

O `toCriteria()` recusava a comparação que ignora maiúsculas e acentos (a dobra é uma expressão diferente em cada banco, e um TCriteria não sabe em que banco vai rodar) e não tinha subquery a não ser o `NOESC:(SELECT ...)` cru do Adianti, que o visitor recusa (`#`, `--` e `/*` saem do texto inteiro, e nada é ligado). Desde a v2.5.0 o banco é declarado, e as subqueries são folhas próprias, para o `toSql()` e o `toCriteria()`.

```php
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\String\TextComparison;
use Antevemus\ASpecification\Sql\Dialects\PostgreSqlDialect;

// Uma busca no PostgreSQL sem maiúsculas e acentos, escrita como o toSql() escreve; a conexão roda com prep = 1
$byName = Spec::property('name', Spec::contains('jose', TextComparison::IgnoreCaseAndAccents));
$criteria = Spec::toCriteria($byName, accentFolding: new PostgreSqlDialect('f_fold'));   // a função do exemplo 20
$criteria->dump(true);         // (f_fold(lower(name)) LIKE f_fold(lower(:aspf_1)))
$criteria->getPreparedVars();  // [':aspf_1' => '%jose%']

// Os estados de um módulo, no lugar de NOESC:(SELECT ...): os valores ligados, os literais intactos
$moduleId = 7;
$states = Spec::inSubquery('stateId', 'module_states', 'id',
    Spec::property('moduleId', Spec::equalTo($moduleId))->and(Spec::property('deletedAt', Spec::isNull())));
$criteria = Spec::toCriteria($states);
$criteria->dump(true);
// (state_id IN (SELECT sq.id FROM module_states sq WHERE sq.id IS NOT NULL AND (sq.module_id = :par_1 AND sq.deleted_at IS NULL)))
$criteria->getPreparedVars();  // [':par_1' => 7]

// Existe uma linha relacionada: um comando só para uma consulta escrita à mão
$withReport = Spec::existsIn('claim_document', ['claim_id' => 'id'], Spec::property('kind', Spec::equalTo('report')), 'claim');
$where = Spec::toSql($withReport, 'pgsql');
$where->toSql();
// EXISTS (SELECT 1 FROM "claim_document" "sq" WHERE "sq"."claim_id" = "claim"."id" AND (("sq"."kind" IS NOT NULL AND "sq"."kind" = :ps1_1)))
$where->getParameters();       // [':ps1_1' => 'report']
$withReport->evaluate((object) ['id' => 1])->isError;   // true: uma subquery é avaliada só pelo banco
```

- **Acento num TCriteria** (`Spec::toCriteria(..., accentFolding:)`, `TCriteriaBuilder::withAccentFolding()`, um dialeto ou o nome dele). As folhas do exemplo 20 viram um filtro que escreve exatamente a condição que o `toSql()` escreve para aquele dialeto, com o valor ligado cru num marcador próprio (`:aspf_N`) e dobrado pelo banco dos dois lados, mais `ESCAPE '!'` quando o padrão pede; a negação aceita a coluna NULL, como toda comparação negada. Três condições: o banco (PostgreSQL 13+ em UTF8, pela dobra embutida de cerca de 10 KB por condição ou, melhor, pela função `f_fold` do exemplo 20 com um índice de expressão; MySQL/MariaDB pela collation sem acento, com `regex()` recusada como no `toSql()`; qualquer outro dialeto continua recusando), **uma conexão com `prep = 1`** (o valor nunca é concatenado: com `prep = 0` o marcador sem valor é erro de SQL) e os limites do `toSql()` do mesmo dialeto. Sem o dialeto, a recusa é a da 2.1.0, agora apontando o `withAccentFolding()`.
- **Subqueries** (`Spec::inSubquery($property, $table, $column, $where, $innerFieldMap)` e `Spec::existsIn($table, $on, $where, $outerTable, $innerFieldMap)`). A outra tabela é lida como `sq`, o filtro dela é uma especificação comum sobre as colunas dela, traduzida pelo mesmo visitor (valores ligados, `(SELECT`/`NOESC:` continuam recusados lá dentro), a coluna selecionada é sempre filtrada com `IS NOT NULL` (o `NOT IN` continua de dois valores), e o `not()` dá `NOT IN` (verdadeiro para coluna NULL) e `NOT EXISTS`. O `$on` liga uma coluna da outra tabela a uma propriedade do candidato, que passa pelo mapeamento externo e é qualificada com o `$outerTable` (num TCriteria, a tabela do modelo). Os identificadores são validados na construção: segmentos simples (`[A-Za-z_][A-Za-z0-9_$]*`) ou entre aspas duplas (`"aflow-engine".module_states`, recitados pelo dialeto no `toSql()`); qualquer outra coisa é `UnsafeIdentifierException`, e nenhum literal entra no texto SQL. Em memória a subquery é erro de avaliação (`DatabaseOnlySpecificationException`, nunca verdadeiro nem falso); o codec a recusa (um documento de regra de um catálogo editável não pode nomear tabelas); o `PdoRepository` continua com o `Spec::inQuery()`, que aplica o mapeamento e o escopo de leitura dele. A outra tabela não recebe o soft delete nem os prefiltros de um ORM: escreva `deletedAt IS NULL` no `$where`. Fora do alcance: `JOIN`, `UNION` e agregados dentro da subquery.
