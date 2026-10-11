#!/usr/bin/env bash
# Forward 028, RN-03, forward 031, RN-04 and forward 032, D5: runs Module22_PdoRepositoryTest,
# Module25_PdoRepositoryComplementsTest and Module26_PdoRelationshipsTest against real PostgreSQL 17
# and MySQL 8.4 in disposable containers (Module 26 also opens an unbuffered MySQL connection); Module 22
# also runs its text-operator parity on MariaDB 11.4 (1.14.0), which shares the MySQL dialect.
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
foreach (["Module22_PdoRepositoryTest", "Module25_PdoRepositoryComplementsTest", "Module26_PdoRelationshipsTest"] as $suite) {
    $class = "Antevemus\\ASpecification\\Tests\\Unit\\" . $suite;
    $t = new $class();
    $t->run();
    echo $suite, " OK ", $t->getAssertionCount(), " assertions\n";
}
'
