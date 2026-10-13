#!/usr/bin/env bash
# shellcheck disable=SC2016 # the single-quoted arguments are PHP code, expanded by PHP, not by the shell
# Forward 037, D8 item 3 (2.1.0): the memory × SQL parity suites of run_portability.sh, against databases
# that are already up. The CI job "SQL parity" (.github/workflows/tests.yml) runs it against its service
# containers (PostgreSQL 17, MySQL 8.4, MariaDB 11.4); run_portability.sh brings the same three up in
# disposable local containers. The suites are the same:
#
#   - Module22_PdoRepositoryTest, Module25_PdoRepositoryComplementsTest, Module26_PdoRelationshipsTest and
#     Module27_FeatureFlagsTest (forwards 028, 031, 032 and 034);
#   - Module28_AccentInsensitiveTextTest (forward 035): the accent-insensitive battery when ext-intl is
#     loaded, the refusal of TextComparison::IgnoreCaseAndAccents when it is not; the test itself creates the
#     PostgreSQL unaccent extension for the documented PostgreSqlDialect('unaccent') option (the official
#     postgres image ships it);
#   - DateSqlParityTest and the soundness bench (AlgebraSoundnessTest, AlgebraSoundnessCasesTest), forward
#     037 D2/D5.
#
# Every ASPEC_PDO_{PGSQL,MYSQL,MARIADB}_{DSN,USER,PASSWORD} variable must be set: without them the suites
# warn and skip the database, which would turn this run green without testing anything, so a missing DSN
# is refused here.
#
#   ASPEC_PDO_PGSQL_DSN=... ASPEC_PDO_MYSQL_DSN=... ASPEC_PDO_MARIADB_DSN=... (and _USER/_PASSWORD) \
#       bash tests/Fixtures/pdo_repository/run_parity_suites.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
PHP=(env XDEBUG_MODE=off php -d xdebug.mode=off -d memory_limit=512M)

for family in PGSQL MYSQL MARIADB; do
    for suffix in DSN USER PASSWORD; do
        name="ASPEC_PDO_${family}_${suffix}"
        if [ -z "${!name+set}" ]; then
            echo "run_parity_suites.sh: ${name} is not set" >&2
            exit 2
        fi
    done
done

wait_for() {
    local family="$1"
    for _ in $(seq 1 120); do
        if "${PHP[@]}" -r '
            $u = strtoupper($argv[1]);
            try { $p = new PDO(getenv("ASPEC_PDO_{$u}_DSN"), getenv("ASPEC_PDO_{$u}_USER"), getenv("ASPEC_PDO_{$u}_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $p->query("SELECT 1"); exit(0); }
            catch (Throwable $e) { exit(1); }' "$family" 2>/dev/null; then
            return 0
        fi
        sleep 1
    done
    echo "timeout waiting for ${family}" >&2
    return 1
}

wait_for pgsql
wait_for mysql
wait_for mariadb

cd "$ROOT"
"${PHP[@]}" -r '
require "tests/bootstrap.php";
foreach (glob("tests/Unit/*.php") as $f) { require_once $f; }
foreach (["Module22_PdoRepositoryTest", "Module25_PdoRepositoryComplementsTest", "Module26_PdoRelationshipsTest", "Module27_FeatureFlagsTest", "Module28_AccentInsensitiveTextTest"] as $suite) {
    $class = "Antevemus\\ASpecification\\Tests\\Unit\\" . $suite;
    $t = new $class();
    $t->run();
    echo $suite, " (PHP ", PHP_VERSION, extension_loaded("intl") ? ", intl " . INTL_ICU_VERSION : ", without intl", ") OK ", $t->getAssertionCount(), " assertions\n";
}
'

"${PHP[@]}" vendor/bin/phpunit --no-coverage --do-not-cache-result \
    tests/PhpUnit/DateSqlParityTest.php tests/PhpUnit/AlgebraSoundnessTest.php tests/PhpUnit/AlgebraSoundnessCasesTest.php
