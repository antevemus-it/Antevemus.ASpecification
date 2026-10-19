# Text Comparison: Case and Accents

### 20. Text Comparison: Case and Accents (Module 28)

The text operators `contains()`, `startsWith()` and `endsWith()` take a second argument, the comparison: `bool|TextComparison` since v2.1.0. `true` (the default) is `TextComparison::CaseSensitive`, `false` is `TextComparison::IgnoreCase`, and `TextComparison::IgnoreCaseAndAccents` also ignores accents, in the `Spec` facade, the DSL functions and the factory; `Spec::equalIgnoreCaseAndAccents()` is the pair of `equalIgnoreCase()`. Ignoring accents is a declared choice, never a side effect of the column collation.

```php
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\String\TextComparison;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;

$search = Spec::property('title', Spec::contains('configuracao', TextComparison::IgnoreCaseAndAccents));

$search->isSatisfiedBy((object) ['title' => 'Configuração do servidor']); // true
$search->isSatisfiedBy((object) ['title' => 'CONFIGURAÇÃO']);             // true
Spec::contains('configuracao', false)->isSatisfiedBy('Configuração');     // false: IgnoreCase compares accents
Spec::contains('strasse', TextComparison::IgnoreCaseAndAccents)
    ->isSatisfiedBy('Straße');                                            // false: ß is a letter, not s + mark
Spec::equalIgnoreCaseAndAccents('sao paulo')->isSatisfiedBy('SÃO PAULO');  // true

Spec::toSql($search, 'mysql')->getSql();
// (`title` IS NOT NULL AND CONVERT(`title` USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE :p1)
try {
    Spec::toSql($search, 'sqlite');
} catch (NonTranslatableSpecificationException $e) {
    // SQLite cannot answer it as memory does: refused, never a different answer
}
```

- **The folding.** Both sides are folded the same way: canonical decomposition (NFD), removal of the nonspacing marks (`\p{Mn}`), canonical composition (NFC) of what is left, then `mb_strtolower()`. `ç`, `ã`, `õ`, `é`, `ê`, `ñ`, `ü` and their capitals fold to the base letter. **Limit:** a letter that is not a base letter plus a mark stays itself, as with .NET's `CompareOptions.IgnoreCase | IgnoreNonSpace`: `ß` is not `ss`, `æ` is not `ae`, `œ` is not `oe`, and `ø`, `đ`, `ł` are not `o`, `d`, `l`. The ALinq visitor uses the same folding.
- **`ext-intl` is required by this option only** (the `Normalizer` class; a polyfill is not accepted). Without it, building a specification with `IgnoreCaseAndAccents` throws `MissingExtensionException`, which names the extension; `CaseSensitive` and `IgnoreCase` need nothing new, and `TextComparison::isAccentFoldingAvailable()` tells whether the runtime has it.
- **SQL, with the in-memory answer or not at all.** PostgreSQL folds both sides of the `LIKE` with an expression equal to the in-memory folding (`lower(normalize(regexp_replace(normalize(x, NFD), <marks>, '', 'g'), NFC))`: no extension, `IMMUTABLE`, a UTF8 database; measured on PostgreSQL 17 over the 50 303 letters and marks of the BMP). `new PostgreSqlDialect('unaccent')` uses the function of that extension instead (which maps `ß` to `ss` and `æ` to `ae`, so it answers differently from memory for those letters), or the name of a wrapper of your own. MySQL 8.4 and MariaDB 11.4 compare `CONVERT(col USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE :p` (`new MySqlDialect('utf8mb4_uca1400_ai_ci')` for MariaDB 10.10 to 11.4.4). SQL Server and Oracle are refused (measured: their accent-insensitive collations equate `ß` with `ss` and `æ` with `ae`), and so are SQLite, DuckDB, Db2, Informix, Firebird and ANSI, with `NonTranslatableSpecificationException`; a `PdoRepository` on those databases refuses the query unless `withInMemoryFallback()` was called. `Spec::toCriteria()` refuses it too (`NonTranslatableCriteriaException`): Adianti's `TFilter` concatenates the value it is given, so the folding cannot be applied to it.
- **Measured limits in the databases.** On MySQL/MariaDB, `ø`, `đ`, `ł` and `ð` match `o`, `d`, `l` and `d`, `й` does not match `и`, and katakana matches hiragana (memory answers the other way in each case); on PostgreSQL a column with the `"C"` collation lowers ASCII letters only, the limit of `IgnoreCase` since v1.14.0.
- **Indexes.** The comparison uses no ordinary index on the column, and the library creates none. On PostgreSQL the indexable path is a function: declare an `IMMUTABLE` wrapper whose body is `PostgreSqlDialect::builtInAccentFolding('t')` (`CREATE FUNCTION f_fold(t text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE RETURN ...`), give its name to the dialect (`new PostgreSqlDialect('f_fold')`, which then emits `f_fold(lower(col)) LIKE f_fold(lower(:p))`) and back it with an expression index over `f_fold(lower(col))` (a `pg_trgm` one for `contains()`). On MySQL/MariaDB the collation is applied to the converted column, so the condition is not indexable.
- Wildcards and regular expressions take the same option since v2.3.0 (example 20.1).

---

### 20.1. Wildcards and Regular Expressions Without Accents (Module 29)

Since v2.3.0 `wildcard()` and `regex()` take the comparison too: `Spec::wildcard($pattern, bool|TextComparison $caseSensitive = true)` (`false` is `wildcardIgnoreCase()`) and `Spec::regex($pattern, ?TextComparison $comparison = null)` (`null` keeps the pattern as written, `IgnoreCase` adds `i`, `IgnoreCaseAndAccents` folds the accents and adds `iu`); the DSL functions `matchesWildcardExpression()` and `matches()` and the factory follow. The folding is the one of example 20, with the same `ext-intl` requirement.

```php
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\String\TextComparison;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;

$byGlob = Spec::property('title', Spec::wildcard('*configura*', TextComparison::IgnoreCaseAndAccents));
$byRegex = Spec::property('title', Spec::regex('/^configura/', TextComparison::IgnoreCaseAndAccents));

$byGlob->isSatisfiedBy((object) ['title' => 'Configuração do servidor']);         // true
$byRegex->isSatisfiedBy((object) ['title' => 'CONFIGURAÇÃO']);                    // true
Spec::wildcard('*configuracao*', false)->isSatisfiedBy('Configuração');           // false: IgnoreCase compares accents
Spec::regex('/\D+/', TextComparison::IgnoreCaseAndAccents)->isSatisfiedBy('2026'); // false: \D stays "not a digit"

Spec::toSql($byRegex, 'pgsql')->getParameters();                                 // [':p1' => '^configura'], against the folded column
Spec::toSql($byGlob, 'mysql')->getSql();
// (`title` IS NOT NULL AND CONVERT(`title` USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE :p1)
try {
    Spec::toSql($byRegex, 'mysql');
} catch (NonTranslatableSpecificationException $e) {
    // MySQL's regular expressions ignore the collation: refused, never a different answer
}
```

- **The pattern is folded; its meaning is kept.** A wildcard pattern is folded as the candidate (case and accents), once, when the specification is built. A regular expression is not lowered: only the accents of its body are removed, and the candidate is folded the same way, so `\D` stays "not a digit", `\B` "not a word boundary", and `/ção/` and `/cao/` are the same expression. With a comparison the pattern must be delimited (`/…/`, `#…#`); `getPattern()`, `getBody()` and `getModifiers()` describe the pattern that is evaluated. `IgnoreCase` adds only `i`: to match non-ASCII capitals, write the `u` yourself.

| | `wildcard()` with `IgnoreCaseAndAccents` | `regex()` with `IgnoreCaseAndAccents` |
|---|---|---|
| Memory and ALinq | the folding of example 20 on both sides | accents folded in body and candidate, `iu` added |
| PostgreSQL | `fold(col) LIKE fold(:p)` (the built-in expression of example 20, or your function) | `fold(col) ~* :p`, the body folded in PHP, `\b`/`\B` written as `\y`/`\Y` |
| MySQL 8.4, MariaDB 11.4 | `CONVERT(col USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE :p` | refused |
| SQL Server, Oracle, SQLite, DuckDB, Db2, Informix, Firebird, ANSI | refused | refused |
| `Spec::toCriteria()` (TCriteria) | refused; since v2.5.0, with the database declared (`withAccentFolding()`, example 22.1), the PostgreSQL or MySQL/MariaDB condition above | refused; since v2.5.0, with `withAccentFolding('pgsql')`, the PostgreSQL condition above |

- **Refused, never answered differently.** A refusal is `NonTranslatableSpecificationException` (`NonTranslatableCriteriaException` for TCriteria), and a `PdoRepository` on those databases refuses the query unless `withInMemoryFallback()` was called. The regular expression is refused on MySQL and MariaDB because their engines (ICU, PCRE2) ignore the collation of the column: measured, `^cora[cç][aã]o$` over an `_ai_ci` column finds `coracao` and not `Coração`, with the body folded or as written, and neither database has a function that folds the column. `wildcard($p, false)` and `regex($p, TextComparison::IgnoreCase)` translate as `wildcardIgnoreCase()` and the `i` modifier always did.
- **Measured limits.** On PostgreSQL the class shorthands keep the classification of the database, in every `regex()`: `\d` is the ASCII digits only (`١٢٣` matches `\d` in memory, not there), `\w` and `\s` differ from PCRE on 605 and 5 code points of the BMP, `.` matches a newline, and a column with the `"C"` collation treats only ASCII letters as `\w`. The wildcard on MySQL/MariaDB keeps the limits of example 20 (`ø`, `ł`, `ð`, `й`, kana). `fnmatch()` does not always read `?` as one character of a multibyte text (`fnmatch('??', 'é')` is `true`); accented letters fold to ASCII, so this remains only with letters that do not fold (`ß`, `æ`, CJK).
- **`\b` on PostgreSQL (#73).** PostgreSQL reads `\b` as a backspace; its word boundary is `\y`. Up to v2.2.0 a `regex()` with `\b` sent to PostgreSQL never matched; since v2.3.0 every regular expression translated for it, with or without a comparison, has `\b`/`\B` written as `\y`/`\Y` (`ISqlRegexRewriter`), outside bracket expressions and `\Q…\E`. The SQL is the same; only the bound value changes.
- **A visitor of your own** that reads every `WildcardSpecification` as case-sensitive, or uses `RegexSpecification::getPattern()` without folding the candidate, translates these leaves wrongly: read `getComparison()`/`ignoresAccents()` first.
