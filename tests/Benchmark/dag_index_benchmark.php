<?php

declare(strict_types=1);

/**
 * DAG index benchmark (forward 020, RN-06, v1.6.0)
 *
 * Compares PartitionRepository::put() and findAll() in three modes, on a DAG of 200 partitions in
 * 4 levels (fan-out 5, 3, 3, 3) and on a DAG of 5 partitions (one level):
 *
 *  - off:       no index (the recursive walk of 1.4.4, put() tests every direct partition);
 *  - traversal: subtree index only (RN-06 (ii), PartitionRepository::setDagIndexEnabled());
 *  - full:      subtree index plus routing clusters (RN-06 (i), PartitionRepository::setDagRoutingEnabled()).
 *
 * with two kinds of partition specification:
 *
 *  - "range": a benchmark-local interval specification with exact algebra and an O(1) evaluation
 *    (the cheapest possible evaluation, i.e. the least favourable case for the index);
 *  - "library": Spec::property('bucket', Spec::in(...)), the library's own property and set
 *    specifications (property access plus set membership, a realistic evaluation cost).
 *
 * Each scenario runs --runs times, rotating the order of the modes. The gates use the fastest run of
 * each mode (the minimum is the estimator least disturbed by other processes on the machine); the
 * median is reported next to it. The results of every mode are compared (same entities, same order)
 * before any number is reported. Each part of the index has its own gate (see verdict()).
 *
 * Usage:
 *   php -d xdebug.mode=off tests/Benchmark/dag_index_benchmark.php [--entities=20000] [--runs=11] [--queries=20] [--no-report]
 *
 * Writes tests/Benchmark/REPORT-dag-index.md unless --no-report is given.
 *
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Benchmark
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */

namespace Antevemus\ASpecification\Tests\Benchmark;

require_once __DIR__ . '/../bootstrap.php';

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Repositories\IPartitionRepository;
use Antevemus\ASpecification\Entities\AbstractUUIDEntity;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use RuntimeException;

final class BenchEntity extends AbstractUUIDEntity
{
    public function __construct(public int $bucket)
    {
        parent::__construct();
    }

    public function getBucket(): int
    {
        return $this->bucket;
    }
}

/** Half-open interval [lo, hi) over BenchEntity::$bucket, with exact interval algebra. */
final class BenchRange extends AbstractSpecification
{
    public static int $calls = 0;

    public function __construct(public readonly int $lo, public readonly int $hi)
    {
    }

    public function getType(): string
    {
        return BenchEntity::class;
    }

    public function isSatisfiedBy(?object $candidate): bool
    {
        self::$calls++;
        return $candidate instanceof BenchEntity && $candidate->bucket >= $this->lo && $candidate->bucket < $this->hi;
    }

    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        return $otherSpecification instanceof self && $otherSpecification->lo >= $this->lo && $otherSpecification->hi <= $this->hi;
    }

    public function isSpecialCaseOf(ISpecification $otherSpecification): bool
    {
        return $otherSpecification instanceof self && $this->lo >= $otherSpecification->lo && $this->hi <= $otherSpecification->hi;
    }

    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        return $otherSpecification instanceof self && ($otherSpecification->hi <= $this->lo || $otherSpecification->lo >= $this->hi);
    }

    public function equals(mixed $other): bool
    {
        return $other instanceof self && $other->lo === $this->lo && $other->hi === $this->hi;
    }

    public function __toString(): string
    {
        return "BenchRange[{$this->lo},{$this->hi})";
    }
}

final class DagIndexBenchmark
{
    private const MODES = ['off', 'traversal', 'full'];

    /** @var array<string, int|bool> */
    private array $options;

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /** @param array<string, int|bool> $options */
    public function __construct(array $options)
    {
        $this->options = $options;
    }

    public function run(): int
    {
        $dags = [
            'large' => ['label' => '200 partitions, 4 levels (5/3/3/3)', 'fanouts' => [5, 3, 3, 3], 'leafWidth' => 6],
            'small' => ['label' => '5 partitions, 1 level', 'fanouts' => [5], 'leafWidth' => 162],
        ];

        foreach (['range', 'library'] as $kind) {
            foreach ($dags as $dagKey => $dag) {
                $this->scenario($kind, $dagKey, $dag);
            }
        }

        $this->probeDisjointness();
        $verdict = $this->verdict();
        $this->printTable($verdict);
        if (!$this->options['no-report']) {
            $this->writeReport($verdict);
        }

        return 0;
    }

    /**
     * @param array{label: string, fanouts: list<int>, leafWidth: int} $dag
     */
    private function scenario(string $kind, string $dagKey, array $dag): void
    {
        $domain = $dag['leafWidth'] * (int) array_product($dag['fanouts']);
        mt_srand(20261009);
        $entities = [];
        for ($i = 0; $i < $this->options['entities']; $i++) {
            $entities[] = new BenchEntity(mt_rand(0, $domain - 1));
        }

        $specs = $this->specs($kind, $dag['fanouts'], $domain);
        $selectiveWidth = intdiv($domain, $dag['fanouts'][0] * ($dag['fanouts'][1] ?? 1));
        $selective = $kind === 'range'
            ? new BenchRange(0, $selectiveWidth)
            : Spec::property('bucket', Spec::lessThan($selectiveWidth));

        $modes = self::MODES;
        $samples = [];
        foreach ($modes as $mode) {
            $samples[$mode] = ['put' => [], 'all' => [], 'sel' => []];
        }
        $evaluations = [];
        $reference = null;

        for ($run = 0; $run < $this->options['runs']; $run++) {
            $order = array_merge(array_slice($modes, $run % 3), array_slice($modes, 0, $run % 3));
            foreach ($order as $mode) {
                PartitionRepository::setDagIndexEnabled($mode !== 'off');
                PartitionRepository::setDagRoutingEnabled($mode === 'full');
                $repo = $this->build($specs);
                gc_collect_cycles();

                BenchRange::$calls = 0;
                $t = hrtime(true);
                foreach ($entities as $entity) {
                    $repo->put($entity);
                }
                $samples[$mode]['put'][] = (hrtime(true) - $t) / 1e6;
                if ($run === 0 && $kind === 'range') {
                    $evaluations[$mode] = BenchRange::$calls / count($entities);
                }

                $all = new AlwaysTrueSpecification();
                $t = hrtime(true);
                for ($q = 0; $q < $this->options['queries']; $q++) {
                    $found = $repo->findAll($all);
                }
                $samples[$mode]['all'][] = (hrtime(true) - $t) / 1e6 / $this->options['queries'];

                $t = hrtime(true);
                for ($q = 0; $q < $this->options['queries']; $q++) {
                    $foundSelective = $repo->findAll($selective);
                }
                $samples[$mode]['sel'][] = (hrtime(true) - $t) / 1e6 / $this->options['queries'];

                // Every mode must return the same entities in the same order
                $signature = md5(implode(',', array_map(static fn($e) => $e->getEntityId(), $found))
                    . '|' . implode(',', array_map(static fn($e) => $e->getEntityId(), $foundSelective)));
                if (count($found) !== count($entities)) {
                    throw new RuntimeException("{$kind}/{$dagKey}/{$mode}: findAll returned " . count($found) . ' of ' . count($entities));
                }
                $reference ??= $signature;
                if ($signature !== $reference) {
                    throw new RuntimeException("{$kind}/{$dagKey}: results differ between modes ({$mode})");
                }
            }
        }

        foreach (['put' => 'put()', 'all' => 'findAll(all)', 'sel' => 'findAll(selective)'] as $op => $label) {
            $off = min($samples['off'][$op]);
            $traversal = min($samples['traversal'][$op]);
            $full = min($samples['full'][$op]);
            $this->rows[] = [
                'kind' => $kind,
                'dag' => $dagKey,
                'dagLabel' => $dag['label'],
                'op' => $label,
                'off' => $off,
                'traversal' => $traversal,
                'full' => $full,
                'medOff' => self::median($samples['off'][$op]),
                'medTraversal' => self::median($samples['traversal'][$op]),
                'medFull' => self::median($samples['full'][$op]),
                'gainTraversal' => ($off - $traversal) / $off * 100,
                'gainRouting' => ($traversal - $full) / $traversal * 100,
                'evals' => $op === 'put' && $evaluations !== []
                    ? sprintf('%.2f / %.2f / %.2f', $evaluations['off'], $evaluations['traversal'], $evaluations['full'])
                    : '',
            ];
        }
    }

    /** @var array<int, float> Cost in ms of one isDisjointWith() between two library sibling specs, by set size */
    private array $disjointnessCost = [];

    /**
     * Measures what building one routing cluster pair costs with the library specifications: one
     * isDisjointWith() between two disjoint Spec::property('bucket', Spec::in(...)) of each set size used.
     */
    private function probeDisjointness(): void
    {
        foreach ([6, 18, 54, 162] as $size) {
            $a = Spec::property('bucket', Spec::in(range(0, $size - 1)));
            $b = Spec::property('bucket', Spec::in(range($size, 2 * $size - 1)));
            $samples = [];
            for ($i = 0; $i < 3; $i++) {
                $t = hrtime(true);
                $a->isDisjointWith($b);
                $samples[] = (hrtime(true) - $t) / 1e6;
            }
            $this->disjointnessCost[$size] = min($samples);
        }
    }

    /**
     * Partition specifications from the most general to the most specific.
     *
     * @param list<int> $fanouts
     * @return list<ISpecification>
     */
    private function specs(string $kind, array $fanouts, int $domain): array
    {
        $specs = [];
        $ranges = [[0, $domain]];
        foreach ($fanouts as $fanout) {
            $next = [];
            foreach ($ranges as [$lo, $hi]) {
                $width = intdiv($hi - $lo, $fanout);
                for ($k = 0; $k < $fanout; $k++) {
                    $next[] = [$lo + $k * $width, $lo + ($k + 1) * $width];
                }
            }
            foreach ($next as [$lo, $hi]) {
                $specs[] = $this->spec($kind, $lo, $hi);
            }
            $ranges = $next;
        }
        return $specs;
    }

    private function spec(string $kind, int $lo, int $hi): ISpecification
    {
        if ($kind === 'range') {
            return new BenchRange($lo, $hi);
        }
        return Spec::property('bucket', Spec::in(range($lo, $hi - 1)));
    }

    /**
     * @param list<ISpecification> $specs
     */
    private function build(array $specs): IPartitionRepository
    {
        $repo = PartitionRepository::create(new InMemoryRepository());
        foreach ($specs as $spec) {
            $repo->addPartition($spec);
        }
        if (count($repo->getAllPartitions()) !== count($specs)) {
            throw new RuntimeException('Unexpected graph: ' . count($repo->getAllPartitions()) . ' partitions for ' . count($specs) . ' specifications');
        }
        return $repo;
    }

    /** @param list<float> $values */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    /**
     * Gates of RN-06, one per part of the index, each over both kinds of specification:
     * - traversal (off -> traversal): faster findAll(all) on the large DAG, and no operation slower
     *   than the noise margin on the small DAG;
     * - routing (traversal -> full): faster put() on the large DAG, and put() not slower than the
     *   noise margin on the small DAG.
     *
     * @return array{traversal: array{keep: bool, reasons: list<string>}, routing: array{keep: bool, reasons: list<string>}}
     */
    private function verdict(): array
    {
        $margin = 5.0;
        $verdict = ['traversal' => ['keep' => true, 'reasons' => []], 'routing' => ['keep' => true, 'reasons' => []]];
        foreach ($this->rows as $row) {
            $id = "{$row['kind']} / {$row['dag']} / {$row['op']}";
            if ($row['dag'] === 'large' && $row['op'] === 'findAll(all)' && $row['gainTraversal'] <= 0.0) {
                $verdict['traversal']['keep'] = false;
                $verdict['traversal']['reasons'][] = sprintf('no gain on %s (%+.1f%%)', $id, $row['gainTraversal']);
            }
            if ($row['dag'] === 'small' && $row['gainTraversal'] < -$margin) {
                $verdict['traversal']['keep'] = false;
                $verdict['traversal']['reasons'][] = sprintf('regression on %s (%+.1f%%, margin -%.0f%%)', $id, $row['gainTraversal'], $margin);
            }
            if ($row['op'] === 'put()') {
                if ($row['dag'] === 'large' && $row['gainRouting'] <= 0.0) {
                    $verdict['routing']['keep'] = false;
                    $verdict['routing']['reasons'][] = sprintf('no gain on %s (%+.1f%%)', $id, $row['gainRouting']);
                }
                if ($row['dag'] === 'small' && $row['gainRouting'] < -$margin) {
                    $verdict['routing']['keep'] = false;
                    $verdict['routing']['reasons'][] = sprintf('regression on %s (%+.1f%%, margin -%.0f%%)', $id, $row['gainRouting'], $margin);
                }
            }
        }
        return $verdict;
    }

    /** @param array{traversal: array{keep: bool, reasons: list<string>}, routing: array{keep: bool, reasons: list<string>}} $verdict */
    private function printTable(array $verdict): void
    {
        printf("%-8s %-6s %-19s %11s %11s %11s %10s %10s  %s\n", 'specs', 'dag', 'operation', 'off', 'traversal', 'full', 'trav gain', 'rout gain', 'evals/put off/trav/full');
        foreach ($this->rows as $row) {
            printf(
                "%-8s %-6s %-19s %11.3f %11.3f %11.3f %+9.1f%% %+9.1f%%  %s\n",
                $row['kind'],
                $row['dag'],
                $row['op'],
                $row['off'],
                $row['traversal'],
                $row['full'],
                $row['gainTraversal'],
                $row['gainRouting'],
                $row['evals']
            );
        }
        foreach (['traversal' => 'subtree index (RN-06 (ii))', 'routing' => 'routing clusters (RN-06 (i))'] as $part => $label) {
            echo "\nVERDICT {$label}: " . ($verdict[$part]['keep'] ? 'enabled by default' : 'disabled by default') . "\n";
            foreach ($verdict[$part]['reasons'] as $reason) {
                echo "  - {$reason}\n";
            }
        }
    }

    /** @param array{traversal: array{keep: bool, reasons: list<string>}, routing: array{keep: bool, reasons: list<string>}} $verdict */
    private function writeReport(array $verdict): void
    {
        $lines = [];
        $lines[] = '# Benchmark: materialized DAG index of PartitionRepository (RN-06, v1.6.0)';
        $lines[] = '';
        $lines[] = '> Generated by `tests/Benchmark/dag_index_benchmark.php` on ' . date('Y-m-d H:i:s T') . '.';
        $lines[] = '> Reproduce with `php -d xdebug.mode=off tests/Benchmark/dag_index_benchmark.php' . sprintf(' --entities=%d --runs=%d --queries=%d', $this->options['entities'], $this->options['runs'], $this->options['queries']) . '`.';
        $lines[] = '';
        $lines[] = '## Environment';
        $lines[] = '';
        $lines[] = '| Item | Value |';
        $lines[] = '|---|---|';
        $lines[] = '| PHP | ' . PHP_VERSION . ' (' . PHP_OS_FAMILY . ', ' . (PHP_INT_SIZE * 8) . '-bit) |';
        $lines[] = '| Xdebug mode | ' . (extension_loaded('xdebug') ? (string) ini_get('xdebug.mode') : 'not loaded') . ' |';
        $lines[] = '| OPcache (CLI) | ' . (function_exists('opcache_get_status') && (bool) ini_get('opcache.enable_cli') ? 'on' : 'off') . ' |';
        $lines[] = '| Entities per put() run | ' . $this->options['entities'] . ' |';
        $lines[] = '| Runs per scenario (mode order rotated; gates on the fastest run, median shown too) | ' . $this->options['runs'] . ' |';
        $lines[] = '| findAll() calls per run (time per call reported) | ' . $this->options['queries'] . ' |';
        $lines[] = '';
        $lines[] = '## Scenarios';
        $lines[] = '';
        $lines[] = '- **large**: 200 partitions in 4 levels below the root, fan-out 5/3/3/3 (5 + 15 + 45 + 135), sibling partitions mutually disjoint, every entity routed to a leaf.';
        $lines[] = '- **small**: 5 disjoint partitions in 1 level.';
        $lines[] = '- **range** specifications: benchmark-local interval `[lo, hi)` with exact algebra, O(1) evaluation and O(1) disjunction.';
        $lines[] = '- **library** specifications: `Spec::property(\'bucket\', Spec::in(...))` (property access plus set membership; the large DAG has sets of 162, 54, 18 and 6 values, the small one sets of 162 values).';
        $lines[] = '- Modes: **off** (no index: recursive walk of 1.4.4, `put()` tests every direct partition), **traversal** (subtree index, RN-06 (ii)), **full** (subtree index plus routing clusters, RN-06 (i)).';
        $lines[] = '- `put()`: time to put every entity into a freshly built graph (the lazy index build is included). `findAll(all)`: `AlwaysTrueSpecification` over the loaded graph. `findAll(selective)`: the entities of one level-2 partition (one level-1 partition in the small DAG), pruning by disjunction applies.';
        $lines[] = '- The results of every mode are compared (same entities, same order) before the numbers are kept.';
        $lines[] = '';
        $lines[] = '## Results (milliseconds: fastest run, median in parentheses)';
        $lines[] = '';
        $lines[] = '| Specs | DAG | Operation | off | traversal | full | Gain of traversal (off -> traversal) | Gain of routing (traversal -> full) | Evaluations per put (off / traversal / full) |';
        $lines[] = '|---|---|---|---:|---:|---:|---:|---:|---|';
        foreach ($this->rows as $row) {
            $lines[] = sprintf(
                '| %s | %s | %s | %.3f (%.3f) | %.3f (%.3f) | %.3f (%.3f) | %+.1f%% | %+.1f%% | %s |',
                $row['kind'],
                $row['dag'],
                $row['op'],
                $row['off'],
                $row['medOff'],
                $row['traversal'],
                $row['medTraversal'],
                $row['full'],
                $row['medFull'],
                $row['gainTraversal'],
                $row['gainRouting'],
                $row['evals']
            );
        }
        $lines[] = '';
        $lines[] = '## Verdict';
        $lines[] = '';
        $lines[] = 'Gates (RN-06), each over both kinds of specification, with a 5% noise margin for regressions:';
        $lines[] = '';
        $lines[] = '- **Subtree index (RN-06 (ii))**: faster `findAll(all)` on the large DAG, and no operation slower on the small DAG.';
        $lines[] = '- **Routing clusters (RN-06 (i))**: faster `put()` on the large DAG, and `put()` not slower on the small DAG.';
        $lines[] = '';
        $lines[] = '## Cost of the routing clusters';
        $lines[] = '';
        $lines[] = 'Building the clusters of a node costs one `isDisjointWith()` pair (both directions) per pair of sibling partitions, once per graph (then maintained incrementally). The pair is O(1) for the range specifications, but for the library set specifications it grows with the product of the set sizes. One `isDisjointWith()` between two disjoint `Spec::property(\'bucket\', Spec::in(...))` measured in this run:';
        $lines[] = '';
        $lines[] = '| Values per set | One isDisjointWith() (ms) |';
        $lines[] = '|---:|---:|';
        foreach ($this->disjointnessCost as $size => $ms) {
            $lines[] = sprintf('| %d | %.3f |', $size, $ms);
        }
        $lines[] = '';
        $lines[] = 'The evaluations the clusters save (about a third per `put()`, see the last column of the results) are worth far less than that one-time build on these workloads, so the clusters only pay off when the partition specifications have cheap disjunction or the graph receives a very large number of puts; the switch stays available for those cases.';
        $lines[] = '';
        foreach (['traversal' => ['Subtree index (RN-06 (ii))', 'setDagIndexEnabled'], 'routing' => ['Routing clusters (RN-06 (i))', 'setDagRoutingEnabled']] as $part => [$label, $switch]) {
            $lines[] = $verdict[$part]['keep']
                ? "**{$label}: gate passed, enabled by default** (`PartitionRepository::{$switch}(false)` turns it off)."
                : "**{$label}: gate failed, disabled by default** (`PartitionRepository::{$switch}(true)` turns it on).";
            foreach ($verdict[$part]['reasons'] as $reason) {
                $lines[] = "- {$reason}";
            }
            $lines[] = '';
        }

        file_put_contents(__DIR__ . '/REPORT-dag-index.md', implode("\n", $lines));
        echo "\nReport written to tests/Benchmark/REPORT-dag-index.md\n";
    }
}

$options = ['entities' => 20000, 'runs' => 11, 'queries' => 20, 'no-report' => false];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--no-report') {
        $options['no-report'] = true;
    } elseif (preg_match('/^--(entities|runs|queries)=(\d+)$/', $arg, $m) === 1) {
        $options[$m[1]] = max(1, (int) $m[2]);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
}

$initialIndex = PartitionRepository::isDagIndexEnabled();
$initialRouting = PartitionRepository::isDagRoutingEnabled();
try {
    exit((new DagIndexBenchmark($options))->run());
} finally {
    PartitionRepository::setDagIndexEnabled($initialIndex);
    PartitionRepository::setDagRoutingEnabled($initialRouting);
}
