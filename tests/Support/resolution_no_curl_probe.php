<?php

declare(strict_types=1);

/**
 * Probe of forward 026 D12, run by Module 21 in a child process started with `php -n` (no php.ini,
 * so no shared extension: ext-curl is not loaded). Prints a JSON report: whether curl is loaded,
 * what registering an HttpDataSource does, and whether a CallableDataSource still works.
 *
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Support
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */

require __DIR__ . '/../bootstrap.php';

use Antevemus\ASpecification\Resolution\CallableDataSource;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\HttpDataSource;
use Antevemus\ASpecification\Spec;

$report = ['curl' => extension_loaded('curl')];

try {
    (new DataSourceRegistry())->register('bureau.score', new HttpDataSource('https://bureau.example.com', '/score/{document}'));
    $report['http'] = 'registered';
} catch (Throwable $e) {
    $report['http'] = $e::class;
    $report['message'] = $e->getMessage();
}

$registry = new DataSourceRegistry();
$registry->register('static.value', new CallableDataSource(static fn(array $parameters): int => 42));
DataSourceRegistry::setDefault($registry);
$report['callable'] = Spec::external('static.value', [], Spec::equalTo(42))->evaluate(new stdClass())->isSatisfied;

echo json_encode($report);
