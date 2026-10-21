# 🛡️ Permanent Guarantees

Since v2.1.0 the quality of the specification algebra is guaranteed by automatic checks, none of which depends on someone remembering to run it. No version is published with any of the five red: the tag of a release waits for the `Tests` workflow to be green on the release commit.

| Guarantee | What it checks | Where it runs | Local command |
| :--- | :--- | :--- | :--- |
| **Soundness bench (fixed part)** | For every pair of the pool and every candidate, `equals()`, generalization and special case, disjointness, tautology and contradiction, `simplify()`, the deduplication of `and()`/`or()` and the partitions never promise more than the evaluation delivers (`tests/PhpUnit/AlgebraSoundnessTest.php`, `AlgebraSoundnessCasesTest.php`) | Workflow `Tests`, on every push and pull request: PHP 8.2, 8.3 and 8.4 with `intl`, PHP 8.4 without `intl` and PHP 8.4 with ALinq | `vendor/bin/phpunit` |
| **Soundness bench (extended run)** | More trees, deeper, and every pair of composites (about 10 minutes) | Workflow `Quality`, weekly and on demand | `ASPEC_SOUNDNESS_EXTENDED=1 vendor/bin/phpunit tests/PhpUnit/AlgebraSoundnessTest.php tests/PhpUnit/AlgebraSoundnessCasesTest.php` (`ASPEC_SOUNDNESS_DUMP=<file>` writes each violation as a JSON line) |
| **Pool coverage guard** | Every concrete specification class of `src/` is in the pool of the bench; a new class outside it fails the suite | With the bench | `vendor/bin/phpunit` |
| **Memory × SQL parity** | The PDO repository, the feature flag store, the text comparison without accents, the date-time leaves, the soundness bench, and the rule precedence, the subqueries and the accents of `toCriteria()` give in PostgreSQL 17, MySQL 8.4 and MariaDB 11.4 the answer they give in memory | Workflow `Tests`, job `SQL parity` (PHP 8.2 and 8.4 with `intl`, the three databases as service containers) | `bash tests/Fixtures/pdo_repository/run_portability.sh` (the three databases in disposable containers) |
| **Mutation testing** | Infection plants defects in the specifications, the serialization, the rule engine, the TCriteria bridge, the asynchronous sources and the caching decorators (the exact scope is in `infection.json5`: `===` swapped for `==`, a branch removed, a condition inverted) and measures how many the whole suite catches; a score below the floor fails | Workflow `Quality`, weekly and on demand | `vendor/bin/infection --threads=max --only-covering-test-cases`, with a coverage driver (pcov) |

## The Infection Floor

The floor lives in `infection.json5` (`minMsi` and `minCoveredMsi`) and only rises: when the measured score rises, the floor follows; it is never lowered to let a run pass (a surviving mutant is killed with a test, or declared equivalent in `infection.json5` with the reason).

| Version | Scope | Mutants | MSI measured | Floor |
| :--- | :--- | ---: | ---: | ---: |
| 2.1.0 | Specifications and the two abstract bases | 2 558 | 85.81% | 84 |
| 2.2.0 | Same | 2 709 | 86.82% | 85 |
| 2.3.0 | Same | 2 763 | 87.12% | 86 |
| 2.4.0 | Same + serialization | 3 323 | 89.38% | 88 |
| 2.5.0 | Same + rule engine, TCriteria bridge and asynchronous sources | 4 481 | 90.54% | 89 |

## What Every Change Brings

- **A new specification class** brings, in the same commit, its evaluation semantics in the class docblock (how it compares objects, dates and nulls) and its place in the pool of the bench with boundary candidates for the type of value it holds; without it the pool guard fails the suite.
- **Every violation** found by the bench, by the parity runs or by a relevant surviving mutant becomes a permanent reproduction test, and its fix ships before the release.

The tests behind each guarantee, and the numbers of the current version, are in [Tests](tests.md).
