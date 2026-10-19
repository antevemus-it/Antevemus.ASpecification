<?php

declare(strict_types=1);

/**
 * Child-process probe of forward 048 (2.5.0): builds the TCriteria of the accent and subquery cases with
 * the REAL Adianti classes (TExpression, TFilter, TCriteria of a template or an application), not the
 * stubs of the suite.
 *
 * Usage: php real_adianti_048_probe.php <path to lib/adianti/database> <cases.json>
 * cases.json: {"<label>": {"type": "accent"|"subquery", ...the case of Module36Cases}}
 * Prints a JSON map label => {prepared, vars, plain, plainVars} (or {error}) on stdout.
 *
 * The real classes are required BEFORE the test bootstrap, so the autoloader never reaches
 * tests/Stubs/Adianti/ for them (as real_adianti_probe.php does).
 */

$adianti = rtrim((string) ($argv[1] ?? ''), '/');
$casesFile = (string) ($argv[2] ?? '');
if ($adianti === '' || !is_file($adianti . '/TCriteria.php') || !is_file($casesFile)) {
    fwrite(STDERR, "usage: real_adianti_048_probe.php <path to lib/adianti/database> <cases.json>\n");
    exit(2);
}

foreach (['TExpression', 'TFilter', 'TCriteria'] as $class) {
    require_once $adianti . '/' . $class . '.php';
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Tests\Support\Module36Cases;

$cases = json_decode((string) file_get_contents($casesFile), true, 512, JSON_THROW_ON_ERROR);
$out = [];
foreach ($cases as $label => $case) {
    try {
        if ($case['type'] === 'constant') {
            // 2.5.0: the constant conditions; `raw` is the TFilter('1', '=', 1) of up to 2.4.0 (the control)
            $spec = Module36Cases::constantSpec($case['id']);
            if ($spec === null) {
                $criteria = new Adianti\Database\TCriteria();
                $criteria->add(new Adianti\Database\TFilter('1', '=', 1));
            } else {
                $criteria = Spec::toCriteria($spec);
            }
        } elseif ($case['type'] === 'virttus') {
            // RN-07 (d): the raw subselect as the Virttus writes it, and the same through inSubquery()
            [$column, $raw, $spec] = Module36Cases::virttusSubquery($case);
            $criteria = $case['form'] === 'raw' ? new Adianti\Database\TCriteria() : Spec::toCriteria($spec);
            if ($case['form'] === 'raw') {
                $criteria->add(new Adianti\Database\TFilter($column, 'IN', $raw));
            }
        } else {
            $criteria = $case['type'] === 'accent'
                ? Spec::toCriteria(Module36Cases::accentSpec($case), accentFolding: Module36Cases::dialect($case['dialect']))
                : Spec::toCriteria(Module36Cases::subquerySpec($case));
        }
        $prepared = (string) $criteria->dump(true);
        $vars = (array) $criteria->getPreparedVars();
        $plain = (string) $criteria->dump(false);
        $plainVars = (array) $criteria->getPreparedVars();
        $out[$label] = ['prepared' => $prepared, 'vars' => $vars, 'plain' => $plain, 'plainVars' => $plainVars];
    } catch (Throwable $e) {
        $out[$label] = ['error' => $e::class . ': ' . $e->getMessage()];
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
