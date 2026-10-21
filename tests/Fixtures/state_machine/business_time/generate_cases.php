<?php

/**
 * Generates cases.json, the table of business-time cases of Module 41, by running the SLA clock
 * functions of the first real consumer of the state machine (Virttus.RentalGuaranteeManager:
 * AflowEngineSlaService::advance(), ::workSeconds() and ::budgetSeconds(), with the holidays read
 * by RentalGuaranteeContractManagerBusinessDayService) over a synthetic holiday table.
 *
 * The consumer's source files are loaded UNCHANGED from a read-only checkout; only what they reach
 * outside themselves is stubbed: the Adianti record base class (no database row is ever loaded),
 * the transaction helper of the app (runs the closure) and the connection, an in-memory SQLite
 * database attached as the schema `rental_guarantee_contract_manager` holding the synthetic
 * `holidays` table, with a `to_regclass()` function. No production data is read.
 *
 * Usage (from the root of the library):
 *
 *   VIRTTUS_ROOT=/path/to/Virttus.RentalGuaranteeManager php tests/Fixtures/state_machine/business_time/generate_cases.php
 *
 * The output is deterministic (fixed seed, no clock): running it again on the same sources gives
 * the same file. The origin (commit, file hashes, whether a file differs from the commit) is
 * written into the file.
 */

declare(strict_types=1);

namespace Adianti\Database {
    if (!class_exists(TRecord::class, false)) {
        abstract class TRecord
        {
            public function __construct($id = null, $callObjectLoad = true)
            {
            }

            public function addAttribute($attribute): void
            {
            }
        }
    }

    final class TTransaction
    {
        public static ?\PDO $connection = null;

        public static function get(): \PDO
        {
            \assert(self::$connection !== null);

            return self::$connection;
        }
    }
}

namespace {
    trait SystemChangeLogTrait
    {
    }

    final class RentalGuaranteeBaseService
    {
        public static function executeAtomicStatic(callable $callback, bool $readOnly = false): mixed
        {
            return $callback();
        }
    }

    $root = getenv('VIRTTUS_ROOT');
    if (!is_string($root) || !is_dir($root)) {
        fwrite(STDERR, "Set VIRTTUS_ROOT to a checkout of Virttus.RentalGuaranteeManager.\n");
        exit(1);
    }
    $root = rtrim($root, '/');
    $sources = [
        'app/model/guarantee/domain/workflow/AflowEngineSlaPolicy.php',
        'app/model/guarantee/domain/rental_guarantee_contract_manager/RentalGuaranteeContractManagerHoliday.php',
        'app/service/guarantee/rental_guarantee_contract_manager/RentalGuaranteeContractManagerBusinessDayService.php',
        'app/service/guarantee/workflow/AflowEngineSlaService.php',
    ];
    $files = [];
    foreach ($sources as $source) {
        require_once $root . '/' . $source;
        $changed = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' status --porcelain -- ' . escapeshellarg($source))) !== '';
        $files[$source] = ['sha256' => hash_file('sha256', $root . '/' . $source), 'differsFromCommit' => $changed];
    }
    $commit = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD'));

    // The synthetic holiday table: the national dates of the consumer's seed for 2026 and 2027, plus
    // rows the clock must ignore (a state holiday, an inactive row, a deleted row) and a recurring
    // holiday that only starts in 2027.
    $rows = [
        ['2026-01-01', 'New Year', 'nacional', null, true, true, null],
        ['2026-04-21', 'Tiradentes', 'nacional', null, true, true, null],
        ['2026-05-01', 'Labour Day', 'nacional', null, true, true, null],
        ['2026-09-07', 'Independence', 'nacional', null, true, true, null],
        ['2026-10-12', 'Our Lady of Aparecida', 'nacional', null, true, true, null],
        ['2026-11-02', 'All Souls', 'nacional', null, true, true, null],
        ['2026-11-15', 'Republic', 'nacional', null, true, true, null],
        ['2026-11-20', 'Black Consciousness', 'nacional', null, true, true, null],
        ['2026-12-25', 'Christmas', 'nacional', null, true, true, null],
        ['2026-02-16', 'Carnival Monday', 'nacional', null, false, true, null],
        ['2026-02-17', 'Carnival Tuesday', 'nacional', null, false, true, null],
        ['2026-04-03', 'Good Friday', 'nacional', null, false, true, null],
        ['2026-06-04', 'Corpus Christi', 'nacional', null, false, true, null],
        ['2027-02-08', 'Carnival Monday', 'nacional', null, false, true, null],
        ['2027-02-09', 'Carnival Tuesday', 'nacional', null, false, true, null],
        ['2027-03-26', 'Good Friday', 'nacional', null, false, true, null],
        ['2027-05-27', 'Corpus Christi', 'nacional', null, false, true, null],
        ['2027-03-10', 'Synthetic, every year from 2027', 'nacional', null, true, true, null],
        ['2026-07-09', 'Synthetic state holiday (ignored by the clock)', 'estadual', 'SP', false, true, null],
        ['2026-03-04', 'Synthetic inactive row (ignored)', 'nacional', null, false, false, null],
        ['2026-03-05', 'Synthetic deleted row (ignored)', 'nacional', null, false, true, '2026-01-01 00:00:00'],
    ];
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("ATTACH DATABASE ':memory:' AS rental_guarantee_contract_manager");
    $pdo->exec('CREATE TABLE rental_guarantee_contract_manager.holidays (holiday_date TEXT, name TEXT, scope TEXT, uf TEXT, city TEXT, recurring INTEGER, active INTEGER, deleted_at TEXT)');
    $pdo->sqliteCreateFunction('to_regclass', static fn(string $name): string => $name, 1);
    $insert = $pdo->prepare('INSERT INTO rental_guarantee_contract_manager.holidays VALUES (?, ?, ?, ?, NULL, ?, ?, ?)');
    foreach ($rows as [$date, $name, $scope, $uf, $recurring, $active, $deleted]) {
        $insert->execute([$date, $name, $scope, $uf, $recurring ? 1 : 0, $active ? 1 : 0, $deleted]);
    }
    \Adianti\Database\TTransaction::$connection = $pdo;

    $calendars = [
        'uteis' => ['calendar' => AflowEngineSlaPolicy::CALENDAR_UTEIS, 'cal' => ['days' => [1, 2, 3, 4, 5], 'start' => '08:00', 'end' => '18:00']],
        'time_default' => ['calendar' => AflowEngineSlaPolicy::CALENDAR_TIME, 'cal' => ['days' => [1, 2, 3, 4, 5], 'start' => '08:00', 'end' => '18:00']],
        'time_team' => ['calendar' => AflowEngineSlaPolicy::CALENDAR_TIME, 'cal' => ['days' => [1, 2, 3, 4, 5, 6], 'start' => '09:00', 'end' => '13:30']],
    ];
    $edges = [
        '2026-02-13 17:00:00', '2026-02-14 10:00:00', '2026-02-16 09:00:00', '2026-02-18 00:00:00', '2026-04-02 16:00:00',
        '2026-04-20 18:00:00', '2026-06-03 23:59:59', '2026-11-19 12:00:00', '2026-12-31 20:00:00', '2026-03-09 07:59:00',
        '2027-03-09 07:59:00', '2027-03-10 12:00:00', '2026-03-06 08:00:00', '2026-03-06 18:00:00', '2026-03-07 23:30:00',
        '2026-03-08 01:30:00', '2026-10-31 22:00:00', '2026-11-01 01:30:00', '2027-03-13 20:00:00', '2026-07-09 10:00:00',
        '2026-03-04 10:00:00', '2026-03-05 10:00:00', '2026-09-04 17:59:59', '2027-12-31 23:59:59', '2026-01-01 00:00:00',
    ];
    $balances = [0, 1, 1800, 3600, 7200, 16200, 36000, 86400, 2 * 86400, 5 * 86400, 10 * 86400];

    $cases = [];
    $budgets = [];
    foreach (['America/Sao_Paulo', 'America/New_York'] as $zone) {
        date_default_timezone_set($zone);
        mt_srand(46);
        $instants = array_map('strtotime', $edges);
        $low = strtotime('2026-01-01 00:00:00');
        $high = strtotime('2027-12-20 00:00:00');
        for ($i = 0; $i < 100; $i++) {
            $instants[] = mt_rand($low, $high);
        }
        foreach ($calendars as $name => $calendar) {
            foreach ($instants as $n => $from) {
                $advances = [$balances[$n % count($balances)], mt_rand(1, 20 * 86400)];
                foreach ($advances as $seconds) {
                    $due = AflowEngineSlaService::advance($from, $seconds, $calendar['calendar'], $calendar['cal']);
                    $cases[] = [$zone, $name, 'advance', date('c', $from), $seconds, $due === null ? null : date('c', $due)];
                    if ($due !== null) {
                        $cases[] = [$zone, $name, 'workSeconds', date('c', $from), date('c', $due), AflowEngineSlaService::workSeconds($from, $due, $calendar['calendar'], $calendar['cal'])];
                    }
                }
                $to = $from + mt_rand(-86400, 40 * 86400);
                $cases[] = [$zone, $name, 'workSeconds', date('c', $from), date('c', $to), AflowEngineSlaService::workSeconds($from, $to, $calendar['calendar'], $calendar['cal'])];
            }
            // The limit of days: a balance the clock cannot place within its 3 700 days
            $from = strtotime('2026-01-05 09:00:00');
            foreach ([2500 * 86400, 2700 * 86400] as $seconds) {
                $due = AflowEngineSlaService::advance($from, $seconds, $calendar['calendar'], $calendar['cal']);
                $cases[] = [$zone, $name, 'advance', date('c', $from), $seconds, $due === null ? null : date('c', $due)];
            }
            // The budget of a policy does not depend on the zone
            $budgets[$name] = [];
            foreach ([[1, 'horas'], [2, 'horas'], [1, 'dias'], [2, 'dias'], [5, 'dias']] as [$amount, $unit]) {
                $budgets[$name][] = [$amount, $unit, AflowEngineSlaService::budgetSeconds($amount, $unit, $calendar['calendar'], $calendar['cal'])];
            }
        }
    }

    $json = [
        'about' => 'Business-time cases of Module 41, generated by generate_cases.php from the SLA clock of the first real consumer; do not edit by hand.',
        'origin' => [
            'repository' => 'Virttus.RentalGuaranteeManager',
            'commit' => $commit,
            'files' => $files,
            'functions' => ['AflowEngineSlaService::advance', 'AflowEngineSlaService::workSeconds', 'AflowEngineSlaService::budgetSeconds', 'RentalGuaranteeContractManagerBusinessDayService::isBusinessDay', 'RentalGuaranteeContractManagerBusinessDayService::holidayOn'],
            'php' => PHP_VERSION,
            'seed' => 46,
        ],
        'holidayRows' => array_map(static fn(array $r): array => ['date' => $r[0], 'name' => $r[1], 'scope' => $r[2], 'uf' => $r[3], 'recurring' => $r[4], 'active' => $r[5], 'deleted' => $r[6] !== null], $rows),
        'calendars' => array_map(static fn(array $c): array => ['kind' => $c['calendar']] + $c['cal'], $calendars),
        'budgets' => $budgets,
        'columns' => ['zone', 'calendar', 'function', 'from', 'seconds or to', 'expected'],
        'cases' => $cases,
    ];
    $target = __DIR__ . '/cases.json';
    $text = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    // One case per line keeps the file reviewable
    $text = (string) preg_replace_callback('/\[\n\s+("America[^\]]+?)\n\s+\]/s', static fn(array $m): string => '[' . preg_replace('/,\n\s+/', ', ', $m[1]) . ']', (string) $text);
    file_put_contents($target, $text . "\n");
    fwrite(STDOUT, sprintf("%d cases written to %s\n", count($cases), $target));
}
