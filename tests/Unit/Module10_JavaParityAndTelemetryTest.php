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
        $this->testRemainderContainsOnlyUnsatisfiedClauses();
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
            $this->assertTrue(str_contains($e->getMessage(), 'disjunctive') || str_contains($e->getMessage(), 'disjuntivas'));
        }
        $this->assertTrue($threw);
    }

    /**
     * README example 3 ("Partial Satisfaction"): the remainder must contain ONLY the
     * clauses the candidate does not satisfy, be evaluable on its own and print
     * without anonymous class names or file paths.
     */
    private function testRemainderContainsOnlyUnsatisfiedClauses(): void
    {
        $spec = Spec::specify(Module10OnboardingUser::class)
            ->where('emailVerified', Spec::isTrue())
            ->and('termsAccepted', Spec::isTrue())
            ->and('profileComplete', Spec::isTrue());

        // 1. Reproducao: email verificado, resto pendente -> so termsAccepted e profileComplete
        $user = new Module10OnboardingUser(true, false, false);
        $remainder = $spec->remainderUnsatisfiedBy($user);
        $this->assertTrue($remainder !== null, 'remainder must not be null for a partially satisfied candidate');
        $this->assertTrue($remainder instanceof ICompositeSpecification);

        $text = (string) $remainder;
        $this->assertFalse(str_contains($text, 'emailVerified'), "satisfied clause leaked into remainder: {$text}");
        $this->assertTrue(str_contains($text, 'termsAccepted'), "missing unsatisfied clause termsAccepted: {$text}");
        $this->assertTrue(str_contains($text, 'profileComplete'), "missing unsatisfied clause profileComplete: {$text}");
        $this->assertTrue($text !== (string) $spec, 'remainder is the whole specification');

        // 2. Reproducao: legibilidade (sem classe anonima nem caminho de arquivo)
        $this->assertFalse(str_contains($text, '@anonymous'), "anonymous class name in remainder: {$text}");
        $this->assertFalse(str_contains($text, DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR), "file path in remainder: {$text}");
        $this->assertTrue(str_contains($text, 'Spec<Module10OnboardingUser>'), "type spec not printed as Spec<Type>: {$text}");
        $this->assertEquals(
            '((Spec<Module10OnboardingUser> WHERE termsAccepted: EqualSpecification) AND (Spec<Module10OnboardingUser> WHERE profileComplete: EqualSpecification))',
            $text
        );

        // 3. Regressao: o remainder e avaliavel por si so
        $this->assertFalse($remainder->isSatisfiedBy($user));
        $this->assertTrue($remainder->isSatisfiedBy(new Module10OnboardingUser(false, true, true)), 'remainder must ignore the clause already satisfied');
        $this->assertTrue($remainder->isSatisfiedBy(new Module10OnboardingUser(true, true, true)));
        $this->assertTrue($remainder->remainderUnsatisfiedBy(new Module10OnboardingUser(true, true, true)) === null);

        // 4. Regressao: uma unica clausula pendente -> a propria PropertySpecification
        $single = $spec->remainderUnsatisfiedBy(new Module10OnboardingUser(true, true, false));
        $this->assertTrue($single instanceof PropertySpecification);
        $this->assertEquals('profileComplete', $single->getPropertyName());
        $this->assertEquals('(Spec<Module10OnboardingUser> WHERE profileComplete: EqualSpecification)', (string) $single);

        // 5. Regressao: tudo satisfeito -> null; nada pendente no remainder do remainder
        $this->assertTrue($spec->remainderUnsatisfiedBy(new Module10OnboardingUser(true, true, true)) === null);

        // 6. Regressao: a spec original nao muda (imutabilidade) e continua legivel
        $this->assertFalse($spec->isSatisfiedBy($user));
        $this->assertEquals(
            '(((Spec<Module10OnboardingUser> AND (Spec<Module10OnboardingUser> WHERE emailVerified: EqualSpecification)) AND (Spec<Module10OnboardingUser> WHERE termsAccepted: EqualSpecification)) AND (Spec<Module10OnboardingUser> WHERE profileComplete: EqualSpecification))',
            (string) $spec
        );

        // 7. Regressao: disjuncao aninhada e tratada como clausula atomica, nao decomposta
        $eitherOne = Spec::specify(Module10OnboardingUser::class)
            ->where('termsAccepted', Spec::isTrue())
            ->or('profileComplete', Spec::isTrue());
        $withOr = Spec::specify(Module10OnboardingUser::class)
            ->where('emailVerified', Spec::isTrue())
            ->and($eitherOne);
        $orRemainder = $withOr->remainderUnsatisfiedBy(new Module10OnboardingUser(true, false, false));
        $this->assertTrue($orRemainder !== null);
        $this->assertTrue(str_contains((string) $orRemainder, ' OR '), 'nested disjunction must be kept whole as the pending clause');
        $this->assertFalse(str_contains((string) $orRemainder, 'emailVerified'));
        $this->assertTrue($withOr->remainderUnsatisfiedBy(new Module10OnboardingUser(true, true, false)) === null);
    }
}

/**
 * Fixture do exemplo 3 do README (onboarding): classe nomeada para que o tipo apareca como Spec<...>.
 */
final class Module10OnboardingUser
{
    public function __construct(
        public bool $emailVerified,
        public bool $termsAccepted,
        public bool $profileComplete
    ) {
    }
}
