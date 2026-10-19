#!/usr/bin/env bash
# Forward 028, RN-03, forward 031, RN-04 and forward 032, D5: runs Module22_PdoRepositoryTest,
# Module25_PdoRepositoryComplementsTest and Module26_PdoRelationshipsTest against real PostgreSQL 17
# and MySQL 8.4 in disposable containers (Module 26 also opens an unbuffered MySQL connection); Module 22
# also runs its text-operator parity on MariaDB 11.4 (1.14.0), which shares the MySQL dialect.
# Forward 034, RN-02 (2.1.0): Module27_FeatureFlagsTest runs its PdoFeatureFlagStore contract battery
# on the three of them (PostgreSQL 17, MySQL 8.4 and MariaDB 11.4).
# Forward 037, D2/D5 (2.1.0): DateSqlParityTest on the three of them, and the soundness bench with ext-intl.
# Forward 039, RN-02 (2.3.0): Module29_WildcardRegexAccentInsensitiveTest, wildcard and regex ignoring accents,
# next to Module 28 (host PHP without intl: the refusal; intl containers: the battery on the three databases).
# Forward 040, RN-04 (2.3.0): Module30_FeatureFlagsIITest, the validity window and the percentage rollout of
# PdoFeatureFlagStore (and the additive migration of a 2.1.0 table) on the three of them.
# Forward 041 (2.4.0): Module31_SpecificationSerializationTest, the spec_json column of PdoRuleCatalog,
# findRuleByCode(), a feature flag targeted by a catalog rule and the additive migration of a 2.3.0 table,
# on the three of them; and the codec tests (SpecificationCodecTest) with ext-intl in the PHP containers.
# Forward 048 (2.5.0): Module36_RuleEngineCriteriaAndAsyncDataTest, findRulesByType()/RulePrecedence (InMemory =
# PDO = brute force) and the subquery battery on the host (no intl), and again in the PHP containers with
# ext-intl, where the accent battery of toCriteria() with withAccentFolding() runs too; the TCriteria is dumped
# by the REAL Adianti 8.1.0 and 8.7.0 of ../Antevemus.AdiantiTemplates (mounted read-only) and executed on the
# three databases.
# Ports are bound to 127.0.0.1 on random free ports; all
# containers are removed on exit, also on failure or interruption. No other database or host is touched.
#
#   bash tests/Fixtures/pdo_repository/run_portability.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TAG="aspec028-$$-${RANDOM}"
PG="${TAG}-pg"
MY="${TAG}-my"
MA="${TAG}-ma"
PHP=(env XDEBUG_MODE=off php -d xdebug.mode=off)

cleanup() {
    docker rm -f "$PG" "$MY" "$MA" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

free_port() {
    "${PHP[@]}" -r '$s = stream_socket_server("tcp://127.0.0.1:0"); $n = stream_socket_get_name($s, false); echo substr($n, strrpos($n, ":") + 1);'
}

PG_PORT="$(free_port)"
MY_PORT="$(free_port)"
MA_PORT="$(free_port)"

docker run -d --rm --name "$PG" -e POSTGRES_PASSWORD=aspec -e POSTGRES_DB=aspec \
    -p "127.0.0.1:${PG_PORT}:5432" postgres:17 >/dev/null
docker run -d --rm --name "$MY" -e MYSQL_ROOT_PASSWORD=aspec -e MYSQL_DATABASE=aspec \
    -p "127.0.0.1:${MY_PORT}:3306" mysql:8.4 >/dev/null
docker run -d --rm --name "$MA" -e MARIADB_ROOT_PASSWORD=aspec -e MARIADB_DATABASE=aspec \
    -p "127.0.0.1:${MA_PORT}:3306" mariadb:11.4 >/dev/null

export ASPEC_PDO_PGSQL_DSN="pgsql:host=127.0.0.1;port=${PG_PORT};dbname=aspec"
export ASPEC_PDO_PGSQL_USER=postgres
export ASPEC_PDO_PGSQL_PASSWORD=aspec
export ASPEC_PDO_MYSQL_DSN="mysql:host=127.0.0.1;port=${MY_PORT};dbname=aspec;charset=utf8mb4"
export ASPEC_PDO_MYSQL_USER=root
export ASPEC_PDO_MYSQL_PASSWORD=aspec
export ASPEC_PDO_MARIADB_DSN="mysql:host=127.0.0.1;port=${MA_PORT};dbname=aspec;charset=utf8mb4"
export ASPEC_PDO_MARIADB_USER=root
export ASPEC_PDO_MARIADB_PASSWORD=aspec

wait_for() {
    local family="$1"
    for _ in $(seq 1 120); do
        if "${PHP[@]}" -r '
            $f = $argv[1]; $u = strtoupper($f);
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
foreach (["Module22_PdoRepositoryTest", "Module25_PdoRepositoryComplementsTest", "Module26_PdoRelationshipsTest", "Module27_FeatureFlagsTest", "Module30_FeatureFlagsIITest", "Module31_SpecificationSerializationTest", "Module36_RuleEngineCriteriaAndAsyncDataTest"] as $suite) {
    $class = "Antevemus\\ASpecification\\Tests\\Unit\\" . $suite;
    $t = new $class();
    $t->run();
    echo $suite, " OK ", $t->getAssertionCount(), " assertions\n";
}
'

# Forward 037, D5 (2.1.0): memory × SQL parity of the date-time comparison leaves (equalTo/notEqual/in by
# instant) on the three databases, through PHPUnit (the test reads the same ASPEC_PDO_* variables).
"${PHP[@]}" vendor/bin/phpunit --do-not-cache-result tests/PhpUnit/DateSqlParityTest.php

# Forward 035, RN-02 and RN-03 (2.1.0): Module28_AccentInsensitiveTextTest. The host PHP runs it as it is
# (without ext-intl it checks the refusal of TextComparison::IgnoreCaseAndAccents); the accent-insensitive
# battery needs ext-intl, so it also runs in disposable php:8.2-cli and php:8.4-cli containers with intl,
# pdo_pgsql and pdo_mysql (each image built once, tagged aspec-portability-php<version>-intl), on the host
# network against the same three databases, the repository mounted read-only. The PostgreSQL database
# gets the unaccent extension, the documented option of PostgreSqlDialect.
docker exec "$PG" psql -U postgres -d aspec -qc 'CREATE EXTENSION IF NOT EXISTS unaccent' >/dev/null
MODULE28='
require "tests/bootstrap.php";
require_once "tests/Unit/Module28_AccentInsensitiveTextTest.php";
$t = new Antevemus\ASpecification\Tests\Unit\Module28_AccentInsensitiveTextTest();
$t->run();
echo "Module28_AccentInsensitiveTextTest (PHP ", PHP_VERSION, extension_loaded("intl") ? ", intl " . INTL_ICU_VERSION : ", sem intl", ") OK ", $t->getAssertionCount(), " assertions\n";
'
MODULE36='
require "tests/bootstrap.php";
foreach (glob("tests/Unit/*.php") as $f) { require_once $f; }
$t = new Antevemus\ASpecification\Tests\Unit\Module36_RuleEngineCriteriaAndAsyncDataTest();
$t->run();
echo "Module36_RuleEngineCriteriaAndAsyncDataTest (PHP ", PHP_VERSION, extension_loaded("intl") ? ", intl " . INTL_ICU_VERSION : ", sem intl", ") OK ", $t->getAssertionCount(), " assertions\n";
'
MODULE29='
require "tests/bootstrap.php";
require_once "tests/Unit/Module29_WildcardRegexAccentInsensitiveTest.php";
$t = new Antevemus\ASpecification\Tests\Unit\Module29_WildcardRegexAccentInsensitiveTest();
$t->run();
echo "Module29_WildcardRegexAccentInsensitiveTest (PHP ", PHP_VERSION, extension_loaded("intl") ? ", intl " . INTL_ICU_VERSION : ", sem intl", ") OK ", $t->getAssertionCount(), " assertions\n";
'
"${PHP[@]}" -r "$MODULE28"
"${PHP[@]}" -r "$MODULE29"
for version in 8.2 8.4; do
    image="aspec-portability-php${version}-intl"
    if ! docker image inspect "$image" >/dev/null 2>&1; then
        docker build -q -t "$image" - >/dev/null <<DOCKERFILE
FROM php:${version}-cli
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libpq-dev \
    && docker-php-ext-install intl pdo_pgsql pdo_mysql && rm -rf /var/lib/apt/lists/*
DOCKERFILE
    fi
    mounts=(-v "${ROOT}:/w/Antevemus.ASpecification:ro")
    if [ -d "${ROOT}/../Antevemus.AlinqCollection" ]; then
        mounts+=(-v "$(cd "${ROOT}/../Antevemus.AlinqCollection" && pwd):/w/Antevemus.AlinqCollection:ro")
    fi
    if [ -d "${ROOT}/../Antevemus.AdiantiTemplates" ]; then
        mounts+=(-v "$(cd "${ROOT}/../Antevemus.AdiantiTemplates" && pwd):/w/Antevemus.AdiantiTemplates:ro")
    fi
    docker run --rm --network host "${mounts[@]}" -w /w/Antevemus.ASpecification \
        -e ASPEC_PDO_PGSQL_DSN -e ASPEC_PDO_PGSQL_USER -e ASPEC_PDO_PGSQL_PASSWORD \
        -e ASPEC_PDO_MYSQL_DSN -e ASPEC_PDO_MYSQL_USER -e ASPEC_PDO_MYSQL_PASSWORD \
        -e ASPEC_PDO_MARIADB_DSN -e ASPEC_PDO_MARIADB_USER -e ASPEC_PDO_MARIADB_PASSWORD \
        "$image" php -d memory_limit=512M -r "$MODULE28"
    docker run --rm --network host "${mounts[@]}" -w /w/Antevemus.ASpecification \
        -e ASPEC_PDO_PGSQL_DSN -e ASPEC_PDO_PGSQL_USER -e ASPEC_PDO_PGSQL_PASSWORD \
        -e ASPEC_PDO_MYSQL_DSN -e ASPEC_PDO_MYSQL_USER -e ASPEC_PDO_MYSQL_PASSWORD \
        -e ASPEC_PDO_MARIADB_DSN -e ASPEC_PDO_MARIADB_USER -e ASPEC_PDO_MARIADB_PASSWORD \
        "$image" php -d memory_limit=512M -r "$MODULE29"
    docker run --rm --network host "${mounts[@]}" -w /w/Antevemus.ASpecification \
        -e ASPEC_PDO_PGSQL_DSN -e ASPEC_PDO_PGSQL_USER -e ASPEC_PDO_PGSQL_PASSWORD \
        -e ASPEC_PDO_MYSQL_DSN -e ASPEC_PDO_MYSQL_USER -e ASPEC_PDO_MYSQL_PASSWORD \
        -e ASPEC_PDO_MARIADB_DSN -e ASPEC_PDO_MARIADB_USER -e ASPEC_PDO_MARIADB_PASSWORD \
        "$image" php -d memory_limit=512M -r "$MODULE36"
    # Forward 037, D2 and D5 (2.1.0): the soundness bench with ext-intl (the accent-insensitive leaves join
    # the pool) and the date parity, on PHP 8.2 and 8.4.
    docker run --rm --network host "${mounts[@]}" -w /w/Antevemus.ASpecification \
        -e ASPEC_PDO_PGSQL_DSN -e ASPEC_PDO_PGSQL_USER -e ASPEC_PDO_PGSQL_PASSWORD \
        -e ASPEC_PDO_MYSQL_DSN -e ASPEC_PDO_MYSQL_USER -e ASPEC_PDO_MYSQL_PASSWORD \
        -e ASPEC_PDO_MARIADB_DSN -e ASPEC_PDO_MARIADB_USER -e ASPEC_PDO_MARIADB_PASSWORD \
        "$image" php -d memory_limit=512M vendor/bin/phpunit --do-not-cache-result \
        tests/PhpUnit/AlgebraSoundnessTest.php tests/PhpUnit/AlgebraSoundnessCasesTest.php tests/PhpUnit/DateSqlParityTest.php \
        tests/PhpUnit/SpecificationCodecTest.php
done
