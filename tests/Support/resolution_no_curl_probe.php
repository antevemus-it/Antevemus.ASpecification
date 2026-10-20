<?php

declare(strict_types=1);

/**
 * Probe of forward 026 D12, run by Module 21 in a child process started with `php -n` (no php.ini,
 * so no shared extension: ext-curl is not loaded). Prints a JSON report: whether curl is loaded,
 * what registering an HttpDataSource does, and whether a CallableDataSource still works; since 2.6.0
 * (forward 045) also whether a deferred source registers and resolves through its stage transport.
 *
 * @version    2.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Support
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */

require __DIR__ . '/../bootstrap.php';

use Antevemus\ASpecification\Resolution\CallableDataSource;
use Antevemus\ASpecification\Resolution\DataResolver;
use Antevemus\ASpecification\Resolution\DataSourceRegistry;
use Antevemus\ASpecification\Resolution\HttpDataSource;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Tests\Support\DeferredProbeSource;
use Antevemus\ASpecification\Tests\Support\RecordingStageTransport;

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

$transport = new RecordingStageTransport();
$deferred = (new DataSourceRegistry())->register('deferred.value', new DeferredProbeSource($transport, static fn(array $parameters): int => 7));
$report['deferred'] = (new DataResolver($deferred))->evaluate(Spec::external('deferred.value', [], Spec::equalTo(7)), new stdClass())->isSatisfied
    && $transport->callCount() === 1;

echo json_encode($report);
