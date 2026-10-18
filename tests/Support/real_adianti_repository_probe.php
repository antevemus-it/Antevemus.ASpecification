<?php

declare(strict_types=1);

/**
 * Child-process probe of BUG-20261013-4CLQ: the criteria compiled by the library goes through the REAL
 * Adianti TRepository (load(), count(), delete()) of a model with DELETEDAT and PREFILTERS, over SQLite in
 * memory, plain and prepared (prep = 1). The real classes are autoloaded BEFORE the test bootstrap, so the
 * stubs of tests/Stubs/Adianti are never reached.
 *
 * Usage: php real_adianti_repository_probe.php <path to lib/adianti>
 * Prints JSON on stdout: {"cases": {label: {...}}, "trees": {...}}.
 *
 * Every row combines n, m (null, 1, 5, 9), s (null, apple, banana, cherry, kiwi), deleted_at (null or a
 * date) and unit_id (1 or 2); the model keeps unit 1 (PREFILTERS) and the live rows (DELETEDAT). The
 * expected answer of a specification is its in-memory evaluation AND live AND unit 1.
 */

$adianti = rtrim((string) ($argv[1] ?? ''), '/');
if ($adianti === '' || !is_file($adianti . '/database/TRepository.php')) {
    fwrite(STDERR, "usage: real_adianti_repository_probe.php <path to lib/adianti>\n");
    exit(2);
}

// The real framework first (lib/adianti/<lowercase namespace>/<Class>.php), as AdiantiCoreLoader does
spl_autoload_register(static function (string $class) use ($adianti): void {
    if (strncmp($class, 'Adianti\\', 8) !== 0) {
        return;
    }
    $parts = explode('\\', substr($class, 8));
    $name = array_pop($parts);
    $file = $adianti . '/' . strtolower(implode('/', $parts)) . '/' . $name . '.php';
    if (is_file($file)) {
        require_once $file;
    }
}, true, true);
// TRepository checks is_subclass_of($class, 'TRecord') by the short name, as the framework aliases it
foreach (['TRecord', 'TRepository', 'TCriteria', 'TFilter', 'TExpression', 'TTransaction'] as $short) {
    class_alias('Adianti\\Database\\' . $short, $short);
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Adianti\Database\TCriteria;
use Adianti\Database\TFilter;
use Adianti\Database\TRecord;
use Adianti\Database\TRepository;
use Adianti\Database\TTransaction;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Tests\Support\NullParityTrees;

final class Aspec4clqDoc extends TRecord
{
    const TABLENAME = 'aspec_4clq_doc';
    const PRIMARYKEY = 'id';
    const IDPOLICY = 'max';
    const PREFILTERS = ['unit_id' => 1];
    const DELETEDAT = 'deleted_at';
}

$rows = [];
$id = 0;
foreach (NullParityTrees::INTS as $n) {
    foreach (NullParityTrees::INTS as $m) {
        foreach (NullParityTrees::STRINGS as $s) {
            foreach ([null, '2026-01-01'] as $deletedAt) {
                foreach ([1, 2] as $unit) {
                    $rows[] = (object) ['id' => ++$id, 'n' => $n, 'm' => $m, 's' => $s, 'deleted_at' => $deletedAt, 'unit_id' => $unit];
                }
            }
        }
    }
}

/** Opens a transaction over a fresh SQLite database in memory holding the rows. */
$open = static function (string $prep) use ($rows): PDO {
    $pdo = TTransaction::open(null, ['type' => 'sqlite', 'name' => ':memory:', 'prep' => $prep]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE aspec_4clq_doc (id INTEGER PRIMARY KEY, n INTEGER NULL, m INTEGER NULL, s VARCHAR(10) NULL, deleted_at VARCHAR(20) NULL, unit_id INTEGER NOT NULL)');
    $insert = $pdo->prepare('INSERT INTO aspec_4clq_doc (id, n, m, s, deleted_at, unit_id) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($rows as $row) {
        $insert->execute([$row->id, $row->n, $row->m, $row->s, $row->deleted_at, $row->unit_id]);
    }

    return $pdo;
};

$expected = static function (ISpecification $spec) use ($rows): array {
    $ids = [];
    foreach ($rows as $row) {
        if ($row->deleted_at === null && $row->unit_id === 1 && $spec->isSatisfiedBy($row)) {
            $ids[] = $row->id;
        }
    }

    return $ids;
};

/** The answers of the real TRepository for one specification, in one prepared mode. */
$answer = static function (ISpecification $spec, string $prep) use ($open): array {
    $out = [];

    $open($prep);
    $criteria = Spec::toCriteria($spec);
    $before = $criteria->dump();
    $loaded = (new TRepository(Aspec4clqDoc::class))->load($criteria, false);
    $out['load'] = array_map(static fn(object $o): int => (int) $o->id, $loaded);
    sort($out['load']);
    $out['compiled'] = $before;
    $out['effective'] = $criteria->dump();
    $out['count'] = (int) (new TRepository(Aspec4clqDoc::class))->count(Spec::toCriteria($spec));

    // Composition by mergeCriteria(): the caller's criteria first, the compiled one merged into it
    $merged = new TCriteria();
    $merged->add(new TFilter('deleted_at', 'IS', null));
    $merged->mergeCriteria(Spec::toCriteria($spec));
    $loaded = (new TRepository(Aspec4clqDoc::class, true))->load($merged, false);
    $out['merge'] = array_map(static fn(object $o): int => (int) $o->id, $loaded);
    sort($out['merge']);
    TTransaction::rollback();

    // delete() writes with the same composition; with DELETEDAT it is an UPDATE of deleted_at (soft
    // delete): the rows it touched are those whose deleted_at changed
    $pdo = $open($prep);
    (new TRepository(Aspec4clqDoc::class))->delete(Spec::toCriteria($spec), false);
    $touched = $pdo->query("SELECT id FROM aspec_4clq_doc WHERE COALESCE(deleted_at, '') NOT IN ('', '2026-01-01') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    TTransaction::rollback();
    $out['delete'] = array_map('intval', $touched);

    return $out;
};

$cases = [
    'or'              => Spec::property('n', Spec::equalTo(1))->or(Spec::property('n', Spec::equalTo(5))),
    'anyOf'           => Spec::anyOf(Spec::property('n', Spec::equalTo(1)), Spec::property('m', Spec::equalTo(5))),
    'notAnd'          => Spec::not(Spec::property('n', Spec::equalTo(1))->and(Spec::property('m', Spec::equalTo(5)))),
    'notNor'          => Spec::not(Spec::nor(Spec::property('n', Spec::equalTo(1)), Spec::property('m', Spec::equalTo(5)))),
    'notGreaterThan'  => Spec::not(Spec::property('n', Spec::greaterThan(1))),
    'inWithNull'      => Spec::property('n', Spec::in(1, null)),
    'notEqualRoot'    => Spec::not(Spec::property('s', Spec::equalTo('apple'))),
    'and'             => Spec::property('n', Spec::equalTo(1))->and(Spec::property('m', Spec::equalTo(5))),
    'leaf'            => Spec::property('n', Spec::equalTo(1)),
];

$result = ['cases' => [], 'trees' => ['count' => 0, 'violations' => []]];
foreach ($cases as $label => $spec) {
    $result['cases'][$label] = ['expected' => $expected($spec), 'plain' => $answer($spec, '0'), 'prepared' => $answer($spec, '1')];
}

// The same property as Module 13 with the real classes: generated trees, every answer = memory AND filter
mt_srand(20261013);
$generator = new NullParityTrees();
for ($i = 0; $i < 60; $i++) {
    $tree = $generator->tree(3);
    $want = $expected($tree);
    foreach (['0', '1'] as $prep) {
        $got = $answer($tree, $prep);
        // merge: a withTrashed repository (no DELETEDAT filter) still adds PREFILTERS; the caller's criteria
        // filters deleted_at, so the expected answer is the same
        foreach (['load', 'merge', 'delete'] as $leg) {
            if ($got[$leg] !== $want) {
                $result['trees']['violations'][] = sprintf('#%d prep=%s %s: %s | %s', $i, $prep, $leg, (string) $tree, $got['effective']);
            }
        }
        if ($got['count'] !== count($want)) {
            $result['trees']['violations'][] = sprintf('#%d prep=%s count: %s', $i, $prep, (string) $tree);
        }
    }
    $result['trees']['count']++;
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
