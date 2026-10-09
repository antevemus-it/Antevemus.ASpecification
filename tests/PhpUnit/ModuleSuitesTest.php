<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Tests\TestCase as ModuleSuite;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * ModuleSuitesTest - PHPUnit bridge over the module suites.
 *
 * The canonical runner of this library is `tests/run_all.php` (custom `Tests\TestCase`,
 * one suite per architectural module, assertion counting and regression watch). This
 * class exists so that the very same suites can be executed by PHPUnit (`vendor/bin/phpunit`)
 * from CI pipelines, IDEs and coverage tools, without rewriting the suites: each data set
 * instantiates one module suite, runs it, and forwards its assertion count to PHPUnit.
 *
 * A failing module suite surfaces as a failing PHPUnit test carrying the suite's original
 * message, file and line.
 *
 * @version    1.3.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class ModuleSuitesTest extends TestCase
{
    /**
     * The module suites, in the same order and with the same titles as `tests/run_all.php`.
     *
     * @return iterable<string, array{class-string<ModuleSuite>}>
     */
    public static function moduleSuites(): iterable
    {
        yield 'Módulo 1: Especificações e Álgebra Booleana' => [Module1_SpecificationsTest::class];
        yield 'Módulo 2: Entidades e Identificadores' => [Module2_EntitiesTest::class];
        yield 'Módulo 3: Repositórios em Memória e Base' => [Module3_InMemoryRepositoriesTest::class];
        yield 'Módulo 4: Arquitetura de Particionamento DAG' => [Module4_PartitionRepositoriesTest::class];
        yield 'Módulo 5: Persistência em Arquivo e Decorator Híbrido' => [Module5_FileRepositoriesTest::class];
        yield 'Módulo 6: Utilitários de Concorrência e RW-Lock' => [Module6_ConcurrencyTest::class];
        yield 'Módulo 7: Predicados, Fábricas, Helpers e Visitor' => [Module7_PredicatesAndFactoriesTest::class];
        yield 'Módulo 8: Notification Pattern & SpecificationResult' => [Module8_SpecificationResultTest::class];
        yield 'Módulo 9: Facade Spec, Chaining Fluente & DSL' => [Module9_FluentDslTest::class];
        yield 'Módulo 10: Paridade Java, Telemetria & Remainder' => [Module10_JavaParityAndTelemetryTest::class];
        yield 'Módulo 11: Dynamic Rule Engine & Requisitos Documentais' => [Module11_DynamicRuleEngineTest::class];
        yield 'Módulo 12: SQL Query Visitor & Multi-SGBD Dialects' => [Module12_SqlVisitorAndDialectsTest::class];
        yield 'Módulo 13: TCriteria Builder & Adianti Database Bridge' => [Module13_TCriteriaBuilderTest::class];
        yield 'Módulo 14: ALinq Synergy & Coleções Fluentes LINQ' => [Module14_ALinqSynergyTest::class];
        yield 'Módulo 15: Attributes Declarativos PHP 8.4 (#[AssertSpec])' => [Module15_AttributesTest::class];
        yield 'Módulo 16: Catálogo Relacional de Regras (PdoRuleCatalog)' => [Module16_PdoRuleCatalogTest::class];
    }

    /**
     * Runs one module suite and forwards its assertions to PHPUnit.
     *
     * @param class-string<ModuleSuite> $suiteClass
     */
    #[DataProvider('moduleSuites')]
    public function testModuleSuitePasses(string $suiteClass): void
    {
        $suite = new $suiteClass();

        try {
            $suite->run();
        } catch (Throwable $e) {
            $this->addToAssertionCount($suite->getAssertionCount());
            self::fail(sprintf(
                '%s failed after %d assertions: %s at %s:%d',
                $suiteClass,
                $suite->getAssertionCount(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }

        $this->addToAssertionCount($suite->getAssertionCount());
        self::assertGreaterThan(0, $suite->getAssertionCount(), "{$suiteClass} ran without asserting anything");
    }
}
