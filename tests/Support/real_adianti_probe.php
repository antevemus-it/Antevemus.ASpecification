<?php

declare(strict_types=1);

/**
 * Child-process probe: builds TCriteria with the REAL Adianti classes, not the stubs.
 *
 * Usage: php real_adianti_probe.php <path to lib/adianti/database>
 * Prints a JSON map label => ['dump' => ..., 'prepared' => ...] on stdout.
 *
 * The real classes are required BEFORE the test bootstrap, so the autoloader never
 * reaches tests/Stubs/Adianti/ for them. Run by Module13 when the Adianti sources are
 * available next to this repository (BUG-20261007-M646: the stub had lied twice).
 */

$adianti = rtrim((string) ($argv[1] ?? ''), '/');
if ($adianti === '' || !is_file($adianti . '/TCriteria.php')) {
    fwrite(STDERR, "usage: real_adianti_probe.php <path to lib/adianti/database>\n");
    exit(2);
}

foreach (['TExpression', 'TFilter', 'TCriteria'] as $class) {
    require_once $adianti . '/' . $class . '.php';
}

require_once dirname(__DIR__) . '/bootstrap.php';

use Antevemus\ASpecification\Spec;

$cases = [
    'equalIgnoreCase'      => Spec::property('sigla', Spec::equalIgnoreCase('sp')),
    'wildcardIgnoreCase'   => Spec::property('cidade', Spec::wildcardExpressionMatcherIgnoreCase('são*')),
    'notEqualIgnoreCase'   => Spec::not(Spec::property('sigla', Spec::equalIgnoreCase('sp'))),
    'mixedAnd'             => Spec::property('nome', Spec::wildcard('Jo*'))
                                  ->and(Spec::property('sigla', Spec::equalIgnoreCase('sp'))),
    'nestedOr'             => Spec::property('uf', Spec::equalTo('RJ'))
                                  ->or(Spec::property('nome', Spec::wildcard('A*'))
                                  ->and(Spec::property('sigla', Spec::equalIgnoreCase('sp')))),
];

$out = [];
foreach ($cases as $label => $spec) {
    $criteria = Spec::toCriteria($spec);
    $out[$label] = [
        'dump'     => $criteria->dump(),
        'prepared' => $criteria->dump(true),
    ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
