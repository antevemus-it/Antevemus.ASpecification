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
use Antevemus\ASpecification\Tests\Unit\Module16_PdoRuleCatalogTest;
use Antevemus\ASpecification\Tests\Unit\Module17_StateMachineTest;
use Antevemus\ASpecification\Tests\Unit\Module18_StateMachineRunnerTest;
use Antevemus\ASpecification\Tests\Unit\Module19_UseCaseTest;
use Antevemus\ASpecification\Tests\Unit\Module20_SimplifyTest;
use Antevemus\ASpecification\Tests\Unit\Module21_ConcurrentDataResolutionTest;
use Antevemus\ASpecification\Tests\Unit\Module22_PdoRepositoryTest;
use Antevemus\ASpecification\Tests\Unit\Module23_DomainEventTriggersTest;
use Antevemus\ASpecification\Tests\Unit\Module24_TimeBasedDomainEventsTest;
use Antevemus\ASpecification\Tests\Unit\Module25_PdoRepositoryComplementsTest;
use Antevemus\ASpecification\Tests\Unit\Module26_PdoRelationshipsTest;

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
    'Módulo 16: Catálogo Relacional de Regras (PdoRuleCatalog)' => new Module16_PdoRuleCatalogTest(),
    'Módulo 17: Máquina de Estados Dirigida por Especificações' => new Module17_StateMachineTest(),
    'Módulo 18: Runner e Persistência da Máquina de Estados' => new Module18_StateMachineRunnerTest(),
    'Módulo 19: Caso de Uso e Resultado Comum (UseCaseResult)' => new Module19_UseCaseTest(),
    'Módulo 20: Simplificação de Especificações (simplify)' => new Module20_SimplifyTest(),
    'Módulo 21: Resolução Concorrente de Dados (DataResolver)' => new Module21_ConcurrentDataResolutionTest(),
    'Módulo 22: Repositório sobre PDO (PdoRepository)' => new Module22_PdoRepositoryTest(),
    'Módulo 23: Eventos de Domínio por Especificação' => new Module23_DomainEventTriggersTest(),
    'Módulo 24: Eventos de Domínio pela Passagem do Tempo' => new Module24_TimeBasedDomainEventsTest(),
    'Módulo 25: Complementos do PdoRepository (log, tipos, ciclo de vida, lote, consultas em conjunto)' => new Module25_PdoRepositoryComplementsTest(),
    'Módulo 26: Relacionamentos do PdoRepository (ManyToOne, OneToMany, ManyToMany, carga ansiosa, composição)' => new Module26_PdoRelationshipsTest(),
    'Módulo 27: Feature Flags Declaradas, com Alvo e Histórico' => new \Antevemus\ASpecification\Tests\Unit\Module27_FeatureFlagsTest(),
    'Módulo 28: Comparação de Texto sem Acento (TextComparison::IgnoreCaseAndAccents)' => new \Antevemus\ASpecification\Tests\Unit\Module28_AccentInsensitiveTextTest(),
    'Módulo 29: Wildcard e Regex sem Acento (wildcard()/regex() com TextComparison)' => new \Antevemus\ASpecification\Tests\Unit\Module29_WildcardRegexAccentInsensitiveTest(),
    'Módulo 30: Feature Flags II (janela de validade pelo serviço e rollout percentual)' => new \Antevemus\ASpecification\Tests\Unit\Module30_FeatureFlagsIITest(),
    'Módulo 31: Serialização de Especificações e Alvo de Flag por Regra do Catálogo' => new \Antevemus\ASpecification\Tests\Unit\Module31_SpecificationSerializationTest(),
    'Módulo 36: Motor de Regras, TCriteria e Dados Assíncronos para um Consumidor Real (tipos só consulta, precedência, rótulos, sem acento, subquery, pendente)' => new \Antevemus\ASpecification\Tests\Unit\Module36_RuleEngineCriteriaAndAsyncDataTest(),
];

$startTime = microtime(true);
$totalSuites = count($suites);
$passedSuites = 0;
$totalAssertions = 0;
$failures = [];

// Every suite runs, even after a failure: a regression in one module must not hide the
// state of the others. The exit code reports the aggregate at the end.
foreach ($suites as $title => $suite) {
    echo "• [SUITE] {$title}... ";
    try {
        $suite->run();
        $assertions = $suite->getAssertionCount();
        $totalAssertions += $assertions;
        $passedSuites++;
        echo "✅ PASS ({$assertions} asserções)\n";
    } catch (Throwable $e) {
        $totalAssertions += $suite->getAssertionCount();
        $failures[$title] = $e;
        echo "❌ FAIL: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}

$elapsed = round((microtime(true) - $startTime) * 1000, 2);
$regressions = count($failures);
$passRate = $totalSuites > 0 ? (int) floor($passedSuites * 100 / $totalSuites) : 0;

if ($regressions > 0) {
    echo "\n[REGRESSÃO DETECTADA] {$regressions} suíte(s) com falha:\n";
    foreach ($failures as $title => $e) {
        echo "  - {$title}: " . get_class($e) . ": " . $e->getMessage() . "\n";
    }
}

echo "\n====================================================================\n";
echo " RESULTADO FINAL: {$passedSuites}/{$totalSuites} SUÍTES APROVADAS ({$passRate}% PASS)\n";
echo " TOTAL DE ASSERÇÕES: {$totalAssertions} | TEMPO: {$elapsed}ms | REGRESSÕES: {$regressions}\n";
echo "====================================================================\n";
exit($regressions > 0 ? 1 : 0);
