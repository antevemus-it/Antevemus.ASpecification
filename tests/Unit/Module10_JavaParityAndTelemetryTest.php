<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Contracts\Helpers\IStopWatch;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Helpers\InstrumentationUtils;
use Antevemus\ASpecification\Helpers\StopWatch;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Repositories\PartitionRepository;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\Comparison\RelationalOperator;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Tests\TestCase;
use InvalidArgumentException;
use stdClass;

/**
 * Module10_JavaParityAndTelemetryTest - Testes de paridade total Java e telemetria
 */
class Module10_JavaParityAndTelemetryTest extends TestCase
{
    public function run(): void
    {
        $this->testRelationalOperatorEnum();
        $this->testStopWatchLifecycleAndLaps();
        $this->testInstrumentationUtilsTelemetry();
        $this->testRemainderUnsatisfiedByOnCompositeSpecification();
    }

    private function testRelationalOperatorEnum(): void
    {
        // 1. Validacao de simbolos SQL
        $this->assertEquals('=', RelationalOperator::EQUAL->getSqlSymbol());
        $this->assertEquals('<>', RelationalOperator::NOT_EQUAL->getSqlSymbol());
        $this->assertEquals('<', RelationalOperator::LESS_THAN->getSqlSymbol());
        $this->assertEquals('<=', RelationalOperator::LESS_THAN_OR_EQUAL->getSqlSymbol());
        $this->assertEquals('>', RelationalOperator::GREATER_THAN->getSqlSymbol());
        $this->assertEquals('>=', RelationalOperator::GREATER_THAN_OR_EQUAL->getSqlSymbol());
        $this->assertEquals('<<', RelationalOperator::MUCH_LESS_THAN->getSqlSymbol());
        $this->assertEquals('>>', RelationalOperator::MUCH_GREATER_THAN->getSqlSymbol());

        // 2. Inversoes logicas binarias
        $this->assertEquals(RelationalOperator::NOT_EQUAL, RelationalOperator::EQUAL->getInvertedBinaryRelation());
        $this->assertEquals(RelationalOperator::EQUAL, RelationalOperator::NOT_EQUAL->getInvertedBinaryRelation());
        $this->assertEquals(RelationalOperator::GREATER_THAN_OR_EQUAL, RelationalOperator::LESS_THAN->getInvertedBinaryRelation());
        $this->assertEquals(RelationalOperator::GREATER_THAN, RelationalOperator::LESS_THAN_OR_EQUAL->getInvertedBinaryRelation());
        $this->assertEquals(RelationalOperator::LESS_THAN_OR_EQUAL, RelationalOperator::GREATER_THAN->getInvertedBinaryRelation());
        $this->assertEquals(RelationalOperator::LESS_THAN, RelationalOperator::GREATER_THAN_OR_EQUAL->getInvertedBinaryRelation());
        $this->assertEquals(RelationalOperator::MUCH_GREATER_THAN, RelationalOperator::MUCH_LESS_THAN->getInvertedBinaryRelation());
        $this->assertEquals(RelationalOperator::MUCH_LESS_THAN, RelationalOperator::MUCH_GREATER_THAN->getInvertedBinaryRelation());
    }

    private function testStopWatchLifecycleAndLaps(): void
    {
        $watch = new StopWatch();
        $this->assertEquals(IStopWatch::STATE_READY, $watch->getState());
        $this->assertEquals(0, $watch->getElapsedNanoseconds());

        $watch->start();
        $this->assertEquals(IStopWatch::STATE_STARTED, $watch->getState());

        usleep(1500); // ~1.5ms
        $lap1 = $watch->lap();
        $this->assertTrue($lap1 > 0);

        usleep(1000); // ~1ms
        $watch->stop();
        $this->assertEquals(IStopWatch::STATE_STOPPED, $watch->getState());

        $nanos = $watch->getElapsedNanoseconds();
        $this->assertTrue($nanos > 0);
        $this->assertTrue($watch->getElapsedMilliseconds() > 0.0);
        $this->assertTrue($watch->getElapsedSeconds() > 0.0);
        $this->assertCount(1, $watch->getLaps());

        $formatted = $watch->formatElapsed();
        $this->assertTrue(str_contains($formatted, 'µs') || str_contains($formatted, 'ms') || str_contains($formatted, 's'));

        $watch->reset();
        $this->assertEquals(IStopWatch::STATE_READY, $watch->getState());
        $this->assertEquals(0, $watch->getElapsedNanoseconds());
        $this->assertCount(0, $watch->getLaps());

        $startedWatch = StopWatch::createStarted();
        $this->assertEquals(IStopWatch::STATE_STARTED, $startedWatch->getState());
    }

    private function testInstrumentationUtilsTelemetry(): void
    {
        // 1. Memoria
        $mem = InstrumentationUtils::formatMemoryUsage();
        $this->assertTrue(str_contains($mem, 'Memory:'));
        $this->assertTrue(str_contains($mem, 'Peak:'));

        // 2. Repositorio e Particoes
        $repo = new InMemoryRepository();
        $hierarchy = InstrumentationUtils::inspectRepositoryHierarchy($repo);
        $this->assertTrue(str_contains($hierarchy, '[Repository] InMemoryRepository'));

        $specActive = new PropertySpecification(Spec::specify(stdClass::class), 'status', Spec::equalTo('ACTIVE'));
        $partition = new PartitionRepository($repo, $specActive);

        $partHierarchy = InstrumentationUtils::inspectRepositoryHierarchy($partition);
        $this->assertTrue(str_contains($partHierarchy, '[Partition] PartitionRepository'));

        $nodeCount = InstrumentationUtils::countPartitionNodes($partition);
        $this->assertEquals(1, $nodeCount);

        // 3. Dump de arvore de especificacoes
        $complexSpec = Spec::allOf(
            Spec::greaterThan(18),
            Spec::equalTo('ADMIN')
        );
        $dump = InstrumentationUtils::dumpSpecificationTree($complexSpec);
        $this->assertTrue(str_contains($dump, 'AndSpecification'));
    }

    private function testRemainderUnsatisfiedByOnCompositeSpecification(): void
    {
        $candidatePass = new class {
            public int $age = 25;
            public string $status = 'ACTIVE';
            public string $country = 'BR';
        };

        $candidatePartial = new class {
            public int $age = 25;
            public string $status = 'ACTIVE';
            public string $country = 'US'; // Ira falhar
        };

        $spec = Spec::specify(get_class($candidatePass))
            ->where('age', Spec::greaterThan(18))
            ->and('status', Spec::equalTo('ACTIVE'))
            ->and('country', Spec::equalTo('BR'));

        // 1. Satisfacao completa deve retornar null
        $remainderPass = $spec->remainderUnsatisfiedBy($candidatePass);
        $this->assertTrue($remainderPass === null);

        // 2. Satisfacao parcial retorna o residuo nao satisfeito
        $remainderFail = $spec->remainderUnsatisfiedBy($candidatePartial);
        $this->assertTrue($remainderFail !== null);
        $this->assertTrue($remainderFail instanceof ICompositeSpecification);

        // 3. Disjuncao (OR) lanca InvalidArgumentException
        $orSpec = Spec::specify(get_class($candidatePass))
            ->where('age', Spec::greaterThan(18))
            ->or('status', Spec::equalTo('INACTIVE'));

        $threw = false;
        try {
            $orSpec->remainderUnsatisfiedBy($candidatePartial);
        } catch (InvalidArgumentException $e) {
            $threw = true;
            $this->assertTrue(str_contains($e->getMessage(), 'disjuntivas não é suportada'));
        }
        $this->assertTrue($threw);
    }
}
