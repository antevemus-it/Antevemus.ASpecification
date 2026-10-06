<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Antevemus\ASpecification\Tests\Unit\Module1_SpecificationsTest;
use Antevemus\ASpecification\Tests\Unit\Module2_EntitiesTest;
use Antevemus\ASpecification\Tests\Unit\Module3_InMemoryRepositoriesTest;
use Antevemus\ASpecification\Tests\Unit\Module4_PartitionRepositoriesTest;
use Antevemus\ASpecification\Tests\Unit\Module5_FileRepositoriesTest;
use Antevemus\ASpecification\Tests\Unit\Module6_ConcurrencyTest;
use Antevemus\ASpecification\Tests\Unit\Module7_PredicatesAndFactoriesTest;
use Antevemus\ASpecification\Tests\Unit\Module8_SpecificationResultTest;
use Antevemus\ASpecification\Tests\Unit\Module9_FluentDslTest;
use Antevemus\ASpecification\Tests\Unit\Module10_JavaParityAndTelemetryTest;
use Antevemus\ASpecification\Tests\Unit\Module11_DynamicRuleEngineTest;
use Antevemus\ASpecification\Tests\Unit\Module12_SqlVisitorAndDialectsTest;
use Antevemus\ASpecification\Tests\Unit\Module13_TCriteriaBuilderTest;
use Antevemus\ASpecification\Tests\Unit\Module14_ALinqSynergyTest;
use Antevemus\ASpecification\Tests\Unit\Module15_AttributesTest;

echo "====================================================================\n";
echo " ANTEVEMUS ASPECIFICATION - MASTER TEST RUNNER & REGRESSION WATCH\n";
echo "====================================================================\n\n";

$suites = [
    'Módulo 1: Especificações e Álgebra Booleana' => new Module1_SpecificationsTest(),
    'Módulo 2: Entidades e Identificadores' => new Module2_EntitiesTest(),
    'Módulo 3: Repositórios em Memória e Base' => new Module3_InMemoryRepositoriesTest(),
    'Módulo 4: Arquitetura de Particionamento DAG' => new Module4_PartitionRepositoriesTest(),
    'Módulo 5: Persistência em Arquivo e Decorator Híbrido' => new Module5_FileRepositoriesTest(),
    'Módulo 6: Utilitários de Concorrência e RW-Lock' => new Module6_ConcurrencyTest(),
    'Módulo 7: Predicados, Fábricas, Helpers e Visitor' => new Module7_PredicatesAndFactoriesTest(),
    'Módulo 8: Notification Pattern & SpecificationResult' => new Module8_SpecificationResultTest(),
    'Módulo 9: Facade Spec, Chaining Fluente & DSL' => new Module9_FluentDslTest(),
    'Módulo 10: Paridade Java, Telemetria & Remainder' => new Module10_JavaParityAndTelemetryTest(),
    'Módulo 11: Dynamic Rule Engine & Requisitos Documentais' => new Module11_DynamicRuleEngineTest(),
    'Módulo 12: SQL Query Visitor & Multi-SGBD Dialects' => new Module12_SqlVisitorAndDialectsTest(),
    'Módulo 13: TCriteria Builder & Adianti Database Bridge' => new Module13_TCriteriaBuilderTest(),
    'Módulo 14: ALinq Synergy & Coleções Fluentes LINQ' => new Module14_ALinqSynergyTest(),
    'Módulo 15: Attributes Declarativos PHP 8.4 (#[AssertSpec])' => new Module15_AttributesTest(),
];

$startTime = microtime(true);
$totalSuites = count($suites);
$passedSuites = 0;
$totalAssertions = 0;

foreach ($suites as $title => $suite) {
    echo "• [SUITE] {$title}... ";
    try {
        $suite->run();
        $assertions = $suite->getAssertionCount();
        $totalAssertions += $assertions;
        $passedSuites++;
        echo "✅ PASS ({$assertions} asserções)\n";
    } catch (Throwable $e) {
        echo "❌ FAIL: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo "\n[REGRESSÃO DETECTADA] Abortando execução.\n";
        exit(1);
    }
}

$elapsed = round((microtime(true) - $startTime) * 1000, 2);

echo "\n====================================================================\n";
echo " RESULTADO FINAL: {$passedSuites}/{$totalSuites} SUÍTES APROVADAS (100% PASS)\n";
echo " TOTAL DE ASSERÇÕES: {$totalAssertions} | TEMPO: {$elapsed}ms | REGRESSÕES: 0\n";
echo "====================================================================\n";
exit(0);
