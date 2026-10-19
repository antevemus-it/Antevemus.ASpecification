# Comparação de Texto: Maiúsculas e Acentos

### 20. Comparação de Texto: Maiúsculas e Acentos (Módulo 28)

Os operadores de texto `contains()`, `startsWith()` e `endsWith()` recebem um segundo argumento, a comparação: `bool|TextComparison` desde a v2.1.0. `true` (o padrão) é `TextComparison::CaseSensitive`, `false` é `TextComparison::IgnoreCase`, e `TextComparison::IgnoreCaseAndAccents` também ignora acentos, na fachada `Spec`, nas funções do DSL e na fábrica; `Spec::equalIgnoreCaseAndAccents()` é o par do `equalIgnoreCase()`. Ignorar acentos é uma escolha declarada, nunca efeito colateral da collation da coluna.

```php
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\String\TextComparison;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;

$search = Spec::property('title', Spec::contains('configuracao', TextComparison::IgnoreCaseAndAccents));

$search->isSatisfiedBy((object) ['title' => 'Configuração do servidor']); // true
$search->isSatisfiedBy((object) ['title' => 'CONFIGURAÇÃO']);             // true
Spec::contains('configuracao', false)->isSatisfiedBy('Configuração');     // false: IgnoreCase compara acentos
Spec::contains('strasse', TextComparison::IgnoreCaseAndAccents)
    ->isSatisfiedBy('Straße');                                            // false: ß é uma letra, não s + marca
Spec::equalIgnoreCaseAndAccents('sao paulo')->isSatisfiedBy('SÃO PAULO');  // true

Spec::toSql($search, 'mysql')->getSql();
// (`title` IS NOT NULL AND CONVERT(`title` USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE :p1)
try {
    Spec::toSql($search, 'sqlite');
} catch (NonTranslatableSpecificationException $e) {
    // o SQLite não consegue responder como a memória: recusa, nunca uma resposta diferente
}
```

- **A dobra.** Os dois lados são dobrados do mesmo jeito: decomposição canônica (NFD), remoção das marcas não espaçantes (`\p{Mn}`), composição canônica (NFC) do que sobra e `mb_strtolower()`. `ç`, `ã`, `õ`, `é`, `ê`, `ñ`, `ü` e as maiúsculas viram a letra base. **Limite:** letra que não é letra base mais marca continua ela mesma, como no `CompareOptions.IgnoreCase | IgnoreNonSpace` do .NET: `ß` não é `ss`, `æ` não é `ae`, `œ` não é `oe`, e `ø`, `đ`, `ł` não são `o`, `d`, `l`. O visitor do ALinq usa a mesma dobra.
- **A `ext-intl` é exigida só por essa opção** (a classe `Normalizer`; polyfill não é aceito). Sem ela, montar uma especificação com `IgnoreCaseAndAccents` lança `MissingExtensionException`, que cita a extensão; `CaseSensitive` e `IgnoreCase` não precisam de nada novo, e `TextComparison::isAccentFoldingAvailable()` diz se o runtime a tem.
- **SQL, com a resposta da memória ou nenhuma.** O PostgreSQL dobra os dois lados do `LIKE` com uma expressão igual à dobra em memória (`lower(normalize(regexp_replace(normalize(x, NFD), <marcas>, '', 'g'), NFC))`: sem extensão, `IMMUTABLE`, banco UTF8; medido no PostgreSQL 17 sobre as 50 303 letras e marcas do BMP). `new PostgreSqlDialect('unaccent')` usa a função dessa extensão (que leva `ß` a `ss` e `æ` a `ae`, então responde diferente da memória para essas letras), ou o nome de um invólucro seu. MySQL 8.4 e MariaDB 11.4 comparam `CONVERT(col USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE :p` (`new MySqlDialect('utf8mb4_uca1400_ai_ci')` para o MariaDB de 10.10 a 11.4.4). SQL Server e Oracle são recusados (medido: as collations sem acento deles igualam `ß` a `ss` e `æ` a `ae`), assim como SQLite, DuckDB, Db2, Informix, Firebird e ANSI, com `NonTranslatableSpecificationException`; um `PdoRepository` nesses bancos recusa a consulta a menos que `withInMemoryFallback()` tenha sido chamado. O `Spec::toCriteria()` também recusa (`NonTranslatableCriteriaException`): o `TFilter` do Adianti concatena o valor que recebe, então a dobra não pode ser aplicada a ele.
- **Limites medidos nos bancos.** No MySQL/MariaDB, `ø`, `đ`, `ł` e `ð` casam com `o`, `d`, `l` e `d`, `й` não casa com `и`, e katakana casa com hiragana (a memória responde o contrário em cada caso); no PostgreSQL, uma coluna com a collation `"C"` só passa para minúsculas as letras ASCII, o limite do `IgnoreCase` desde a v1.14.0.
- **Índices.** A comparação não usa índice comum da coluna, e a biblioteca não cria nenhum. No PostgreSQL o caminho indexável é uma função: declare um invólucro `IMMUTABLE` cujo corpo é `PostgreSqlDialect::builtInAccentFolding('t')` (`CREATE FUNCTION f_fold(t text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE RETURN ...`), dê o nome dele ao dialeto (`new PostgreSqlDialect('f_fold')`, que passa a emitir `f_fold(lower(col)) LIKE f_fold(lower(:p))`) e apoie com um índice de expressão sobre `f_fold(lower(col))` (um `pg_trgm` para o `contains()`). No MySQL/MariaDB a collation é aplicada à coluna convertida, então a condição não é indexável.
- Curingas e expressões regulares recebem a mesma opção desde a v2.3.0 (exemplo 20.1).

---

### 20.1. Curingas e Expressões Regulares Sem Acento (Módulo 29)

Desde a v2.3.0 `wildcard()` e `regex()` também recebem a comparação: `Spec::wildcard($pattern, bool|TextComparison $caseSensitive = true)` (`false` é o `wildcardIgnoreCase()`) e `Spec::regex($pattern, ?TextComparison $comparison = null)` (`null` mantém o padrão como escrito, `IgnoreCase` acrescenta `i`, `IgnoreCaseAndAccents` dobra os acentos e acrescenta `iu`); as funções do DSL `matchesWildcardExpression()` e `matches()` e a fábrica acompanham. A dobra é a do exemplo 20, com a mesma exigência da `ext-intl`.

```php
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\String\TextComparison;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;

$byGlob = Spec::property('title', Spec::wildcard('*configura*', TextComparison::IgnoreCaseAndAccents));
$byRegex = Spec::property('title', Spec::regex('/^configura/', TextComparison::IgnoreCaseAndAccents));

$byGlob->isSatisfiedBy((object) ['title' => 'Configuração do servidor']);         // true
$byRegex->isSatisfiedBy((object) ['title' => 'CONFIGURAÇÃO']);                    // true
Spec::wildcard('*configuracao*', false)->isSatisfiedBy('Configuração');           // false: IgnoreCase compara acentos
Spec::regex('/\D+/', TextComparison::IgnoreCaseAndAccents)->isSatisfiedBy('2026'); // false: \D continua "não dígito"

Spec::toSql($byRegex, 'pgsql')->getParameters();                                 // [':p1' => '^configura'], contra a coluna dobrada
Spec::toSql($byGlob, 'mysql')->getSql();
// (`title` IS NOT NULL AND CONVERT(`title` USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE :p1)
try {
    Spec::toSql($byRegex, 'mysql');
} catch (NonTranslatableSpecificationException $e) {
    // as expressões regulares do MySQL ignoram a collation: recusa, nunca uma resposta diferente
}
```

- **O padrão é dobrado; o significado fica.** O padrão do curinga é dobrado como o candidato (maiúsculas e acentos), uma vez, na construção da especificação. A expressão regular não passa para minúsculas: só os acentos do corpo saem, e o candidato é dobrado do mesmo jeito, então `\D` continua "não dígito", `\B` "não fronteira de palavra", e `/ção/` e `/cao/` são a mesma expressão. Com comparação, o padrão precisa de delimitadores (`/…/`, `#…#`); `getPattern()`, `getBody()` e `getModifiers()` descrevem o padrão avaliado. `IgnoreCase` acrescenta só `i`: para casar maiúsculas não ASCII, escreva o `u` você mesmo.

| | `wildcard()` com `IgnoreCaseAndAccents` | `regex()` com `IgnoreCaseAndAccents` |
|---|---|---|
| Memória e ALinq | a dobra do exemplo 20 nos dois lados | acentos dobrados no corpo e no candidato, `iu` acrescentado |
| PostgreSQL | `fold(col) LIKE fold(:p)` (a expressão embutida do exemplo 20, ou a sua função) | `fold(col) ~* :p`, o corpo dobrado no PHP, `\b`/`\B` escritos como `\y`/`\Y` |
| MySQL 8.4, MariaDB 11.4 | `CONVERT(col USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE :p` | recusado |
| SQL Server, Oracle, SQLite, DuckDB, Db2, Informix, Firebird, ANSI | recusado | recusado |
| `Spec::toCriteria()` (TCriteria) | recusado; desde a v2.5.0, com o banco declarado (`withAccentFolding()`, exemplo 22.1), a condição do PostgreSQL ou do MySQL/MariaDB acima | recusado; desde a v2.5.0, com `withAccentFolding('pgsql')`, a condição do PostgreSQL acima |

- **Recusa, nunca resposta diferente.** A recusa é `NonTranslatableSpecificationException` (`NonTranslatableCriteriaException` no TCriteria), e um `PdoRepository` nesses bancos recusa a consulta a menos que `withInMemoryFallback()` tenha sido chamado. A expressão regular é recusada no MySQL e no MariaDB porque os motores deles (ICU, PCRE2) ignoram a collation da coluna: medido, `^cora[cç][aã]o$` sobre uma coluna `_ai_ci` acha `coracao` e não `Coração`, com o corpo dobrado ou como escrito, e nenhum dos dois tem função que dobre a coluna. `wildcard($p, false)` e `regex($p, TextComparison::IgnoreCase)` são traduzidos como o `wildcardIgnoreCase()` e o modificador `i` sempre foram.
- **Limites medidos.** No PostgreSQL as classes abreviadas mantêm a classificação do banco, em todo `regex()`: `\d` são só os dígitos ASCII (`١٢٣` casa `\d` em memória, lá não), `\w` e `\s` diferem do PCRE em 605 e 5 code points do BMP, `.` casa quebra de linha, e uma coluna com a collation `"C"` só trata letras ASCII como `\w`. O curinga no MySQL/MariaDB mantém os limites do exemplo 20 (`ø`, `ł`, `ð`, `й`, kana). O `fnmatch()` nem sempre lê o `?` como um caractere de texto multibyte (`fnmatch('??', 'é')` é `true`); as letras acentuadas viram ASCII na dobra, então isso só sobra nas letras que não dobram (`ß`, `æ`, CJK).
- **`\b` no PostgreSQL (#73).** O PostgreSQL lê `\b` como backspace; a fronteira de palavra dele é `\y`. Até a v2.2.0 um `regex()` com `\b` enviado ao PostgreSQL nunca casava; desde a v2.3.0 toda expressão regular traduzida para ele, com ou sem comparação, tem `\b`/`\B` escritos como `\y`/`\Y` (`ISqlRegexRewriter`), fora de colchetes e de `\Q…\E`. O SQL é o mesmo; só o valor ligado muda.
- **Um visitor seu** que lê todo `WildcardSpecification` como sensível a maiúsculas, ou usa o `RegexSpecification::getPattern()` sem dobrar o candidato, traduz essas folhas errado: leia antes `getComparison()`/`ignoresAccents()`.
