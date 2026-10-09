<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Factory\SpecificationFactory;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\Reflection\MethodParameterizedSpecification;
use Antevemus\ASpecification\Tests\TestCase;
use BadMethodCallException;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

use function Antevemus\ASpecification\DSL\{
    specify, prop, property, is, not, isBefore, before, after, isAfter, afterOrAtTheSameTimeAs,
    allOf, anyOf, equal, equalTo, notEqual, greaterThan, lessThan, in,
    alwaysTrue, alwaysFalse, isNull, isNotNull, contains, startsWith, calling, isTrue
};

class CustomerDummy
{
    public function __construct(
        public string $gender,
        public DateTimeImmutable $membershipDate,
        public DateTimeImmutable $birthDate,
        public ?string $status = "ACTIVE"
    ) {
    }
}

enum MethodCallPlanDummy: string
{
    case GOLD = 'gold';
    case SILVER = 'silver';
}

enum MethodCallPureEnumDummy
{
    case ONE;
}

/**
 * Candidato do forward 018: métodos públicos com e sem argumentos, um protegido e um privado.
 */
class MethodCallContractDummy
{
    public int $calls = 0;

    public function __construct(
        private DateTimeImmutable $endsAt,
        private int $occurrences = 3,
        private ?MethodCallPlanDummy $plan = MethodCallPlanDummy::GOLD
    ) {
    }

    public function isEligibleFor(DateTimeInterface $date): bool
    {
        $this->calls++;
        return $date <= $this->endsAt;
    }

    public function total(int $factor): int
    {
        return $this->occurrences * $factor;
    }

    public function hasPlan(MethodCallPlanDummy $plan): bool
    {
        return $this->plan === $plan;
    }

    public function score(array $weights): int
    {
        return array_sum($weights) * $this->occurrences;
    }

    public function nothing(): ?int
    {
        return null;
    }

    public function shift(DateTime $date): bool
    {
        $date->modify('+10 years');
        return true;
    }

    protected function hiddenTotal(): int
    {
        return 1000;
    }

    private function secret(): bool
    {
        return true;
    }
}

/**
 * Classe `Contract` do bloco de exemplo do README (forward 018), renomeada para não colidir.
 */
final class ReadmeMethodCallContract
{
    public function __construct(private DateTimeImmutable $endsAt, private int $installments)
    {
    }

    public function isEligibleFor(DateTimeInterface $date): bool
    {
        return $date <= $this->endsAt;
    }

    public function installmentsLeft(int $paid): int
    {
        return $this->installments - $paid;
    }
}

class Module9_FluentDslTest extends TestCase
{
    public function run(): void
    {
        $this->testStaticFacadeBasicMethods();
        $this->testStaticFacadeDynamicCallStatic();
        $this->testCustomFactoryInjection();
        $this->testParameterizedPropertyChainingJavaDomian();
        $this->testDslGlobalFunctionsViaUseFunction();
        $this->testChainedAndNotAndOrNotWithProperty();
        $this->testEdgeCasesAndValidationExceptions();
        $this->testInWithSingleArrayIsSetMembership();

        // Forward 018 (v1.6.0): MethodParameterizedSpecification, Spec::calling(), whereMethod(), DSL calling().
        $this->testF018DeclarativeCallWithArgument();
        $this->testF018StructuralIdentityAndAlgebra();
        $this->testF018DeclarativeArgumentsOnly();
        $this->testF018FacadeDslAndWhereMethod();
        $this->testReadmeF018BlockRunsAsWritten();
        // Forward 019 (v1.6.0): facade Spec::isTautology()/isContradiction() e RN-08 para whereMethod.
        $this->testF019FacadeAndMethodRestriction();
    }

    private function testStaticFacadeBasicMethods(): void
    {
        // Composição lógica
        $all = Spec::allOf(Spec::greaterThan(10), Spec::lessThan(20));
        $this->assertInstanceOf(ISpecification::class, $all);
        $this->assertTrue($all->isSatisfiedBy(15));
        $this->assertFalse($all->isSatisfiedBy(25));

        $any = Spec::anyOf(Spec::equalTo("A"), Spec::equalTo("B"));
        $this->assertTrue($any->isSatisfiedBy("A"));
        $this->assertTrue($any->isSatisfiedBy("B"));
        $this->assertFalse($any->isSatisfiedBy("C"));

        $neg = Spec::not(Spec::is(100));
        $this->assertTrue($neg->isSatisfiedBy(50));
        $this->assertFalse($neg->isSatisfiedBy(100));

        // Comparações de valor
        $this->assertTrue(Spec::equal(10)->isSatisfiedBy(10));
        $this->assertTrue(Spec::notEqual(10)->isSatisfiedBy(20));
        $this->assertTrue(Spec::in(1, 2, 3)->isSatisfiedBy(2));
        $this->assertFalse(Spec::in(1, 2, 3)->isSatisfiedBy(5));

        // Comparações temporais
        $past = new DateTimeImmutable("-1 day");
        $now = new DateTimeImmutable("now");
        $future = new DateTimeImmutable("+1 day");

        $this->assertTrue(Spec::before($future)->isSatisfiedBy($now));
        $this->assertTrue(Spec::isBefore($future)->isSatisfiedBy($now));
        $this->assertTrue(Spec::after($past)->isSatisfiedBy($now));
        $this->assertTrue(Spec::isAfter($past)->isSatisfiedBy($now));
        $this->assertTrue(Spec::between($past, $future)->isSatisfiedBy($now));

        // Predicados especiais
        $this->assertTrue(Spec::alwaysTrue()->isSatisfiedBy(null));
        $this->assertFalse(Spec::alwaysFalse()->isSatisfiedBy(123));
        $this->assertTrue(Spec::isNull()->isSatisfiedBy(null));
        $this->assertTrue(Spec::isNotNull()->isSatisfiedBy("abc"));
        $this->assertTrue(Spec::contains("PHP")->isSatisfiedBy("I love PHP!"));
        $this->assertTrue(Spec::startsWith("Hello")->isSatisfiedBy("Hello World"));
        $this->assertTrue(Spec::endsWith("World")->isSatisfiedBy("Hello World"));
        $this->assertTrue(Spec::isEmpty()->isSatisfiedBy([]));
    }

    private function testStaticFacadeDynamicCallStatic(): void
    {
        // Testa o fallback dinâmico via __callStatic para métodos da SpecificationFactory
        $blankSpec = Spec::isBlank();
        $this->assertTrue($blankSpec->isSatisfiedBy("   "));
        $this->assertFalse($blankSpec->isSatisfiedBy("texto"));

        $regexSpec = Spec::matchesRegex('/^[0-9]+$/');
        $this->assertTrue($regexSpec->isSatisfiedBy("12345"));
        $this->assertFalse($regexSpec->isSatisfiedBy("12a45"));

        $caseSpec = Spec::equalIgnoringCase("brasil");
        $this->assertTrue($caseSpec->isSatisfiedBy("BRASIL"));
        $this->assertTrue($caseSpec->isSatisfiedBy("Brasil"));
        $this->assertFalse($caseSpec->isSatisfiedBy("Argentina"));
    }

    private function testCustomFactoryInjection(): void
    {
        $original = Spec::getFactory();
        $this->assertInstanceOf(SpecificationFactory::class, $original);

        $custom = new SpecificationFactory();
        Spec::setFactory($custom);
        $this->assertEquals($custom, Spec::getFactory());

        // Restaura fábrica original
        Spec::setFactory($original);
        $this->assertEquals($original, Spec::getFactory());
    }

    private function testParameterizedPropertyChainingJavaDomian(): void
    {
        $oneYearAgo = new DateTimeImmutable("-1 year");
        $tenYearsAgo = new DateTimeImmutable("-10 years");

        // Sintaxe oficial espelhando o Java Domian via Spec Facade
        $spec = Spec::specify(CustomerDummy::class)
            ->where("gender", Spec::is("FEMALE"))
            ->and("membershipDate", Spec::isBefore($oneYearAgo))
            ->or("gender", Spec::is("MALE"))
            ->and("birthDate", Spec::not(Spec::afterOrAtTheSameTimeAs($tenYearsAgo)));

        $this->assertInstanceOf(ICompositeSpecification::class, $spec);

        // Candidato 1: Mulher membro há 2 anos, nascida há 25 anos -> Válida
        $c1 = new CustomerDummy("FEMALE", new DateTimeImmutable("-2 years"), new DateTimeImmutable("-25 years"));
        $this->assertTrue($spec->isSatisfiedBy($c1));
        $res1 = $spec->evaluate($c1);
        $this->assertTrue($res1->isSatisfied);
        $this->assertCount(0, $res1->failures);

        // Candidato 2: Mulher membro há 6 meses -> Falha em membershipDate
        $c2 = new CustomerDummy("FEMALE", new DateTimeImmutable("-6 months"), new DateTimeImmutable("-25 years"));
        $this->assertFalse($spec->isSatisfiedBy($c2));
        $res2 = $spec->evaluate($c2);
        $this->assertFalse($res2->isSatisfied);
        $this->assertTrue(in_array("membershipDate", array_map(fn($f) => $f->property, $res2->failures)));

        // Candidato 3: Homem com 15 anos de idade -> Válido
        $c3 = new CustomerDummy("MALE", new DateTimeImmutable("-1 month"), new DateTimeImmutable("-15 years"));
        $this->assertTrue($spec->isSatisfiedBy($c3));
        $res3 = $spec->evaluate($c3);
        $this->assertTrue($res3->isSatisfied);

        // Candidato 4: Homem com 5 anos de idade -> Falha em birthDate
        $c4 = new CustomerDummy("MALE", new DateTimeImmutable("-1 month"), new DateTimeImmutable("-5 years"));
        $this->assertFalse($spec->isSatisfiedBy($c4));
        $res4 = $spec->evaluate($c4);
        $this->assertFalse($res4->isSatisfied);
    }

    private function testDslGlobalFunctionsViaUseFunction(): void
    {
        $oneYearAgo = new DateTimeImmutable("-1 year");
        $tenYearsAgo = new DateTimeImmutable("-10 years");

        // Código PHP 100% idêntico ao Java Domian via functions
        $spec = specify(CustomerDummy::class)
            ->where("gender", is("FEMALE"))
            ->and("membershipDate", isBefore($oneYearAgo))
            ->or("gender", is("MALE"))
            ->and("birthDate", not(afterOrAtTheSameTimeAs($tenYearsAgo)));

        $c1 = new CustomerDummy("FEMALE", new DateTimeImmutable("-3 years"), new DateTimeImmutable("-22 years"));
        $this->assertTrue($spec->isSatisfiedBy($c1));

        $c2 = new CustomerDummy("FEMALE", new DateTimeImmutable("-3 months"), new DateTimeImmutable("-22 years"));
        $this->assertFalse($spec->isSatisfiedBy($c2));

        $c3 = new CustomerDummy("MALE", new DateTimeImmutable("-1 month"), new DateTimeImmutable("-18 years"));
        $this->assertTrue($spec->isSatisfiedBy($c3));

        // Testes de funções isoladas
        $this->assertTrue(is("TEST")->isSatisfiedBy("TEST"));
        $this->assertTrue(equal(42)->isSatisfiedBy(42));
        $this->assertTrue(greaterThan(10)->isSatisfiedBy(11));
        $this->assertTrue(lessThan(10)->isSatisfiedBy(9));
        $this->assertTrue(in("x", "y")->isSatisfiedBy("x"));

        // Testes de prop() e property() isolados sem specify()
        $genderPropSpec = prop("gender", equal("FEMALE"));
        $this->assertTrue($genderPropSpec->isSatisfiedBy($c1));
        $this->assertFalse($genderPropSpec->isSatisfiedBy($c3));

        $composedProps = prop("gender", equal("MALE"))
            ->and(property("status", equal("ACTIVE")));
        $validMale = new CustomerDummy("MALE", new DateTimeImmutable("-1 year"), new DateTimeImmutable("-20 years"), "ACTIVE");
        $inactiveMale = new CustomerDummy("MALE", new DateTimeImmutable("-1 year"), new DateTimeImmutable("-20 years"), "INACTIVE");
        $this->assertTrue($composedProps->isSatisfiedBy($validMale));
        $this->assertFalse($composedProps->isSatisfiedBy($inactiveMale));
    }

    private function testChainedAndNotAndOrNotWithProperty(): void
    {
        // Regra: Deve ser ADMIN E NÃO pode ter status BANNED
        $spec = specify(CustomerDummy::class)
            ->where("gender", is("MALE"))
            ->andNot("status", is("BANNED"));

        $valid = new CustomerDummy("MALE", new DateTimeImmutable("-1 year"), new DateTimeImmutable("-20 years"), "ACTIVE");
        $banned = new CustomerDummy("MALE", new DateTimeImmutable("-1 year"), new DateTimeImmutable("-20 years"), "BANNED");

        $this->assertTrue($spec->isSatisfiedBy($valid));
        $this->assertFalse($spec->isSatisfiedBy($banned));

        // Regra com orNot de propriedade
        $specOrNot = specify(CustomerDummy::class)
            ->where("gender", is("FEMALE"))
            ->orNot("status", is("SUSPENDED"));

        $femaleSuspended = new CustomerDummy("FEMALE", new DateTimeImmutable("-1 year"), new DateTimeImmutable("-20 years"), "SUSPENDED");
        $maleActive = new CustomerDummy("MALE", new DateTimeImmutable("-1 year"), new DateTimeImmutable("-20 years"), "ACTIVE");
        $maleSuspended = new CustomerDummy("MALE", new DateTimeImmutable("-1 year"), new DateTimeImmutable("-20 years"), "SUSPENDED");

        $this->assertTrue($specOrNot->isSatisfiedBy($femaleSuspended)); // aprovado por ser FEMALE
        $this->assertTrue($specOrNot->isSatisfiedBy($maleActive));       // aprovado por NÃO ser SUSPENDED
        $this->assertFalse($specOrNot->isSatisfiedBy($maleSuspended));   // reprovado por ser MALE E SUSPENDED
    }

    private function testEdgeCasesAndValidationExceptions(): void
    {
        $spec = specify(CustomerDummy::class)->where("gender", is("FEMALE"));

        // and("prop", null) deve disparar exceção
        $this->assertThrows(
            InvalidArgumentException::class,
            fn() => $spec->and("membershipDate", null)
        );

        // or("prop", null) deve disparar exceção
        $this->assertThrows(
            InvalidArgumentException::class,
            fn() => $spec->or("membershipDate", null)
        );

        // andNot("prop", null) deve disparar exceção
        $this->assertThrows(
            InvalidArgumentException::class,
            fn() => $spec->andNot("membershipDate", null)
        );

        // orNot("prop", null) deve disparar exceção
        $this->assertThrows(
            InvalidArgumentException::class,
            fn() => $spec->orNot("membershipDate", null)
        );

        // Propriedade inexistente no evaluate()
        $specInexistente = specify(CustomerDummy::class)->where("propriedadeInexistente", is("qualquer"));
        $candidato = new CustomerDummy("FEMALE", new DateTimeImmutable(), new DateTimeImmutable());

        $this->assertThrows(InvalidArgumentException::class, fn() => $specInexistente->isSatisfiedBy($candidato));
        $res = $specInexistente->evaluate($candidato);
        $this->assertFalse($res->isSatisfied);
        $this->assertTrue(str_contains($res->failures[0]->message, "propriedadeInexistente"));
    }

    /**
     * in() com um único argumento array trata o array como o conjunto de valores
     * (idioma PHP: in([0, 2, 4]) ≡ in(0, 2, 4)). Antes, o array virava equalTo([0,2,4]).
     */
    private function testInWithSingleArrayIsSetMembership(): void
    {
        // --- Reprodução: um único array é o conjunto, via facade, DSL, fábrica e alias ---
        $viaFacade = Spec::in([0, 2, 4]);
        $this->assertTrue($viaFacade->isSatisfiedBy(0), 'Spec::in([0,2,4]) deve aceitar 0');
        $this->assertTrue($viaFacade->isSatisfiedBy(2), 'Spec::in([0,2,4]) deve aceitar 2');
        $this->assertTrue($viaFacade->isSatisfiedBy(4), 'Spec::in([0,2,4]) deve aceitar 4');
        $this->assertFalse($viaFacade->isSatisfiedBy(1), 'Spec::in([0,2,4]) deve recusar 1');

        $this->assertTrue(in(['x', 'y'])->isSatisfiedBy('y'), "DSL in(['x','y']) deve aceitar 'y'");
        $this->assertFalse(in(['x', 'y'])->isSatisfiedBy('z'), "DSL in(['x','y']) deve recusar 'z'");

        $factory = new SpecificationFactory();
        $this->assertTrue($factory->in(['a', 'b'])->isSatisfiedBy('a'), "factory->in(['a','b']) deve aceitar 'a'");
        $this->assertTrue($factory->isOneOfValues(['a', 'b'])->isSatisfiedBy('b'), "isOneOfValues(['a','b']) deve aceitar 'b'");

        // Sob evaluate(): veredito, nunca erro de tipo (array × escalar)
        $res = Spec::in([0, 2, 4])->evaluate(2);
        $this->assertTrue($res->isSatisfied, 'in([0,2,4])->evaluate(2) deve satisfazer');
        $this->assertFalse($res->isError, 'in([0,2,4])->evaluate(2) não pode ser erro');
        $resFail = Spec::in([0, 2, 4])->evaluate(1);
        $this->assertFalse($resFail->isSatisfied);
        $this->assertFalse($resFail->isError, 'in([0,2,4])->evaluate(1) é falha, não erro');

        // Array associativo: só os valores contam
        $this->assertTrue(Spec::in(['first' => 10, 'second' => 20])->isSatisfiedBy(20));
        $this->assertFalse(Spec::in(['first' => 10, 'second' => 20])->isSatisfiedBy(30));

        // --- Regressão: forma variádica, vazio, array vazio, lista de arrays, escalar único ---
        $this->assertTrue(Spec::in(1, 2, 3)->isSatisfiedBy(3));
        $this->assertFalse(Spec::in(1, 2, 3)->isSatisfiedBy(4));
        $this->assertFalse(Spec::in()->isSatisfiedBy(1), 'in() vazio é alwaysFalse');
        $this->assertFalse(Spec::in([])->isSatisfiedBy(1), 'in([]) é alwaysFalse');
        $this->assertFalse(Spec::in([])->isSatisfiedBy(null), 'in([]) é alwaysFalse até para null');

        // Um único array de arrays é a lista de valores-array: cada elemento é comparado por ===
        $this->assertTrue(Spec::in([[1, 2], [3]])->isSatisfiedBy([3]));
        $this->assertTrue(Spec::in([[1, 2], [3]])->isSatisfiedBy([1, 2]));
        $this->assertFalse(Spec::in([[1, 2], [3]])->isSatisfiedBy([2, 1]));

        // Dois ou mais argumentos array continuam sendo valores-array (forma variádica intacta);
        // escalar contra valor-array é tipo incompatível (RN-04 do adendo Q4VE), nunca false silencioso
        $this->assertTrue(Spec::in([1, 2], [3])->isSatisfiedBy([1, 2]));
        $this->assertThrows(
            \Antevemus\ASpecification\Specifications\Exceptions\IncompatibleTypeException::class,
            fn() => Spec::in([1, 2], [3])->isSatisfiedBy(1)
        );

        // Um único valor escalar continua igualdade simples
        $this->assertTrue(Spec::in(7)->isSatisfiedBy(7));
        $this->assertFalse(Spec::in(7)->isSatisfiedBy(8));
    }

    /**
     * Forward 018 §3 cenário 1 + RN-01/RN-03: chamada declarativa com argumento; estado de erro.
     */
    private function testF018DeclarativeCallWithArgument(): void
    {
        $spec = Spec::calling('isEligibleFor', [new DateTimeImmutable('2026-12-01')], Spec::isTrue());
        $this->assertInstanceOf(MethodParameterizedSpecification::class, $spec);
        $this->assertEquals('isEligibleFor', $spec->getMethodName());
        $this->assertCount(1, $spec->getArguments());
        $this->assertTrue($spec->getResultSpecification()->equals(Spec::isTrue()));

        $eligible = new MethodCallContractDummy(new DateTimeImmutable('2027-01-01'));
        $expired = new MethodCallContractDummy(new DateTimeImmutable('2026-06-30'));
        $this->assertTrue($spec->isSatisfiedBy($eligible));
        $this->assertFalse($spec->isSatisfiedBy($expired));
        $this->assertTrue($spec->evaluate($eligible)->isSatisfied);

        // Falha: UMA falha da regra "isEligibleFor(...)", causas da spec de resultado nos metadados
        $failed = $spec->evaluate($expired);
        $this->assertFalse($failed->isSatisfied);
        $this->assertFalse($failed->isError);
        $this->assertCount(1, $failed->failures);
        $this->assertEquals('isEligibleFor(...)', $failed->failures[0]->ruleName);
        $this->assertEquals('isEligibleFor', $failed->failures[0]->metadata['method']);
        $annotated = $spec->because('Contrato fora da vigência')->withCode('VIG-01')->evaluate($expired);
        $this->assertEquals('Contrato fora da vigência', $annotated->failures[0]->message);
        $this->assertEquals('VIG-01', $annotated->failures[0]->code);

        // Objeto sem o método: estado de erro em evaluate(), exceção em isSatisfiedBy()
        $noMethod = $spec->evaluate(new \stdClass());
        $this->assertFalse($noMethod->isSatisfied);
        $this->assertTrue($noMethod->isError, 'método ausente é erro de avaliação, não false');
        $this->assertInstanceOf(BadMethodCallException::class, $noMethod->exception);
        $this->assertEquals('isEligibleFor(...)', $noMethod->failures[0]->ruleName);
        $this->assertThrows(BadMethodCallException::class, fn() => $spec->isSatisfiedBy(new \stdClass()));
        $this->assertTrue($spec->not()->evaluate(new \stdClass())->isError, 'NOT nunca inverte erro');

        // Só métodos públicos (forward 018 §5)
        $this->assertThrows(BadMethodCallException::class, fn() => Spec::calling('secret', [], Spec::isTrue())->isSatisfiedBy($eligible));
        $this->assertThrows(BadMethodCallException::class, fn() => Spec::calling('hiddenTotal', [], Spec::greaterThan(1))->isSatisfiedBy($eligible));
        $this->assertTrue(Spec::calling('secret', [], Spec::isTrue())->evaluate($eligible)->isError);

        // Exceção do próprio método (argumento de tipo errado) também é erro
        $this->assertTrue(Spec::calling('total', ['x'], Spec::greaterThan(1))->evaluate($eligible)->isError);

        // Candidato nulo/não objeto reprova; retorno nulo reprova (mesma regra de where() com valor nulo)
        $this->assertFalse($spec->isSatisfiedBy(null));
        $this->assertFalse($spec->isSatisfiedBy('isEligibleFor'));
        $this->assertFalse($spec->evaluate(42)->isSatisfied);
        $this->assertFalse($spec->evaluate(42)->isError);
        $this->assertFalse(Spec::calling('nothing', [], Spec::alwaysTrue())->isSatisfiedBy($eligible));
        $this->assertFalse(Spec::calling('nothing', [], Spec::isNull())->isSatisfiedBy($eligible));
        $this->assertFalse(Spec::calling('nothing', [], Spec::alwaysTrue())->evaluate($eligible)->isSatisfied);

        // Escalares no resultado, enum e array como argumentos
        $this->assertTrue(Spec::calling('total', [4], Spec::greaterThan(10))->isSatisfiedBy($eligible));
        $this->assertFalse(Spec::calling('total', [3], Spec::greaterThan(10))->isSatisfiedBy($eligible));
        $this->assertTrue(Spec::calling('hasPlan', [MethodCallPlanDummy::GOLD], Spec::isTrue())->isSatisfiedBy($eligible));
        $this->assertFalse(Spec::calling('hasPlan', [MethodCallPlanDummy::SILVER], Spec::isTrue())->isSatisfiedBy($eligible));
        $this->assertTrue(Spec::calling('score', [[1, 2, 3]], Spec::equalTo(18))->isSatisfiedBy($eligible));

        // Sem cache: cada avaliação chama o método de novo
        $counter = new MethodCallContractDummy(new DateTimeImmutable('2027-01-01'));
        $spec->isSatisfiedBy($counter);
        $spec->isSatisfiedBy($counter);
        $this->assertEquals(2, $counter->calls);

        // Imutável: DateTime mutável copiado na construção e a cada chamada
        $mutable = new DateTime('2026-12-01');
        $shift = Spec::calling('shift', [$mutable], Spec::isTrue());
        $mutable->modify('+1 day');
        $shift->isSatisfiedBy($eligible);
        $shift->isSatisfiedBy($eligible);
        $this->assertEquals('2026-12-01', $shift->getArguments()[0]->format('Y-m-d'), 'nem o chamador nem o método alteram a spec');
    }

    /**
     * Forward 018 §3 cenário 2 + RN-04/RN-05: identidade estrutural e álgebra conservadora.
     */
    private function testF018StructuralIdentityAndAlgebra(): void
    {
        $a = Spec::calling('total', [3], Spec::greaterThan(10));
        $b = Spec::calling('total', [3], Spec::greaterThan(10));
        $this->assertTrue($a !== $b && $a->equals($b), 'construídas separadamente');
        $this->assertFalse($a->equals(Spec::calling('total', ['3'], Spec::greaterThan(10))), "'3' (string) ≠ 3");
        $this->assertFalse($a->equals(Spec::calling('other', [3], Spec::greaterThan(10))), 'outro método');
        $this->assertFalse($a->equals(Spec::calling('total', [3], Spec::greaterThan(11))), 'outra spec de resultado');
        $this->assertFalse($a->equals(Spec::calling('total', [3, 4], Spec::greaterThan(10))), 'aridade');
        $this->assertFalse($a->equals($a->because('x')), 'anotação conta, como nas demais specs');
        $this->assertTrue(
            Spec::calling('isEligibleFor', [new DateTimeImmutable('2026-12-01 00:00:00', new \DateTimeZone('UTC'))], Spec::isTrue())
                ->equals(Spec::calling('isEligibleFor', [new DateTime('2026-11-30 21:00:00', new \DateTimeZone('America/Sao_Paulo'))], Spec::isTrue())),
            'datas por instante'
        );
        $this->assertTrue(Spec::calling('hasPlan', [MethodCallPlanDummy::GOLD], Spec::isTrue())->equals(Spec::calling('hasPlan', [MethodCallPlanDummy::GOLD], Spec::isTrue())));
        $this->assertFalse(Spec::calling('hasPlan', [MethodCallPlanDummy::GOLD], Spec::isTrue())->equals(Spec::calling('hasPlan', [MethodCallPlanDummy::SILVER], Spec::isTrue())));
        $this->assertTrue(Spec::calling('score', [[1, [2]]], Spec::isTrue())->equals(Spec::calling('score', [[1, [2]]], Spec::isTrue())));
        $this->assertFalse(Spec::calling('score', [[1, [2]]], Spec::isTrue())->equals(Spec::calling('score', [[1, ['2']]], Spec::isTrue())));

        // Tipo, regra, texto
        $this->assertEquals('mixed', $a->getType());
        $this->assertEquals('total(...)', $a->getRuleName());
        $this->assertTrue(str_contains((string) $a, 'total(...)'));

        // RN-05: mesmo método e argumentos → a spec de resultado decide
        $this->assertTrue(Spec::calling('total', [3], Spec::greaterThan(5))->isGeneralizationOf($a));
        $this->assertTrue($a->isSpecialCaseOf(Spec::calling('total', [3], Spec::greaterThan(5))));
        $this->assertFalse($a->isGeneralizationOf(Spec::calling('total', [3], Spec::greaterThan(5))));
        $this->assertTrue($a->isDisjointWith(Spec::calling('total', [3], Spec::lessThan(5))));
        $this->assertFalse($a->isDisjointWith(Spec::calling('total', [3], Spec::lessThan(50))));
        // qualquer outro par: conservador
        $this->assertFalse(Spec::calling('total', [4], Spec::greaterThan(5))->isGeneralizationOf($a), 'argumento diferente');
        $this->assertFalse($a->isDisjointWith(Spec::calling('total', [4], Spec::lessThan(5))), 'argumento diferente');
        $this->assertFalse($a->isDisjointWith(Spec::calling('other', [3], Spec::lessThan(5))), 'método diferente');
        $this->assertFalse($a->isGeneralizationOf(Spec::greaterThan(20)));
        $this->assertFalse($a->isDisjointWith(Spec::property('total', Spec::lessThan(5))));
        // axiomas compartilhados continuam valendo
        $this->assertTrue($a->isGeneralizationOf(Spec::alwaysFalse()));
        $this->assertTrue($a->isDisjointWith(Spec::alwaysFalse()));
        $this->assertTrue($a->isDisjointWith($a->not()));
        $this->assertTrue($a->isGeneralizationOf($a->and(Spec::property('x', Spec::equalTo(1)))));
    }

    /**
     * Forward 018 §3 cenário 3 + RN-02: só argumentos representáveis sem código.
     */
    private function testF018DeclarativeArgumentsOnly(): void
    {
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::calling('x', [fn() => 1], Spec::isTrue()), 'closure');
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::calling('x', [new \stdClass()], Spec::isTrue()), 'objeto arbitrário');
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::calling('x', [[1, new \ArrayObject()]], Spec::isTrue()), 'objeto aninhado em array');
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::calling('x', [MethodCallPureEnumDummy::ONE], Spec::isTrue()), 'enum puro (sem backing)');
        $resource = fopen('php://memory', 'r');
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::calling('x', [$resource], Spec::isTrue()), 'resource');
        fclose($resource);
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::calling('x', ['a' => 1], Spec::isTrue()), 'lista posicional');
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::calling('', [], Spec::isTrue()), 'nome vazio');
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::calling('do-it', [], Spec::isTrue()), 'nome não identificador');
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::calling('a b', [], Spec::isTrue()));

        // Aceitos: escalares, null, arrays (inclusive associativos aninhados), BackedEnum, datas
        $ok = Spec::calling('x', [1, 1.5, 'a', true, null, ['k' => [1, 'v']], MethodCallPlanDummy::GOLD, new DateTimeImmutable(), new DateTime()], Spec::isTrue());
        $this->assertCount(9, $ok->getArguments());
    }

    /**
     * Forward 018 RN-06: Spec::calling(), whereMethod() encadeável como where(), DSL calling().
     */
    private function testF018FacadeDslAndWhereMethod(): void
    {
        $eligible = new MethodCallContractDummy(new DateTimeImmutable('2027-01-01'), 5);
        $expired = new MethodCallContractDummy(new DateTimeImmutable('2026-06-30'), 5);

        // DSL
        $dsl = calling('isEligibleFor', [new DateTimeImmutable('2026-12-01')], isTrue());
        $this->assertTrue($dsl->equals(Spec::calling('isEligibleFor', [new DateTimeImmutable('2026-12-01')], Spec::isTrue())));
        $this->assertTrue($dsl->isSatisfiedBy($eligible));

        // whereMethod em spec tipada: a folha declara o tipo da raiz, a conjunção é aceita
        $typed = Spec::specify(MethodCallContractDummy::class)
            ->whereMethod('isEligibleFor', [new DateTimeImmutable('2026-12-01')], Spec::isTrue())
            ->and('occurrences', Spec::greaterThan(1));
        $this->assertTrue($typed->isSatisfiedBy($eligible));
        $this->assertFalse($typed->isSatisfiedBy($expired));
        $this->assertFalse($typed->isSatisfiedBy(new \stdClass()), 'tipo errado reprova pela raiz tipada');

        // whereMethod em folha/composta comum: conjunção base ∧ chamada
        $chained = Spec::property('occurrences', Spec::equalTo(5))->whereMethod('total', [2], Spec::equalTo(10));
        $this->assertInstanceOf(AndSpecification::class, $chained);
        $this->assertTrue($chained->isSatisfiedBy($eligible));
        $this->assertFalse(Spec::property('occurrences', Spec::equalTo(5))->whereMethod('total', [2], Spec::equalTo(11))->isSatisfiedBy($eligible));
        $mixed = Spec::calling('total', [1], Spec::greaterThan(0))->whereMethod('hasPlan', [MethodCallPlanDummy::GOLD], Spec::isTrue());
        $this->assertTrue($mixed->isSatisfiedBy($eligible));
        $this->assertTrue($typed->evaluate(new MethodCallContractDummy(new DateTimeImmutable('2027-01-01'), 0))->failures !== []);
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::alwaysTrue()->whereMethod('x', [fn() => 1], Spec::isTrue()));
    }

    /**
     * Forward 019 RN-11 (facade) e RN-08 (whereMethod herda só contradição).
     */
    private function testF019FacadeAndMethodRestriction(): void
    {
        $a = Spec::property('status', Spec::equalTo('x'));
        $this->assertTrue(Spec::isContradiction($a->and($a->not())));
        $this->assertFalse(Spec::isTautology($a->and($a->not())));
        $this->assertTrue(Spec::isTautology($a->or($a->not())));
        $this->assertFalse(Spec::isContradiction($a));
        $this->assertFalse(Spec::isTautology($a));

        $this->assertTrue(Spec::calling('total', [1], Spec::alwaysFalse())->isContradiction());
        $this->assertTrue(Spec::calling('total', [1], Spec::in())->isContradiction());
        $this->assertTrue(Spec::calling('total', [1], Spec::greaterThan(10)->and(Spec::lessThan(5)))->isContradiction());
        $this->assertFalse(Spec::calling('total', [1], Spec::alwaysTrue())->isTautology(), 'método ausente ou retorno nulo não satisfaz');
        $this->assertFalse(Spec::calling('total', [1], Spec::greaterThan(10))->isContradiction());
        $this->assertTrue(Spec::specify(MethodCallContractDummy::class)->whereMethod('total', [1], Spec::alwaysFalse())->isContradiction());
        $m = Spec::calling('total', [3], Spec::greaterThan(10));
        $this->assertTrue($m->and($m->not())->isContradiction());
        $this->assertTrue($m->or($m->not())->isTautology());
        $this->assertTrue($m->and(Spec::calling('total', [3], Spec::lessThan(5)))->isContradiction(), 'disjunção pela spec de resultado');
        $this->assertFalse($m->and(Spec::calling('total', [4], Spec::lessThan(5)))->isContradiction(), 'argumentos diferentes: não provado');
    }

    /**
     * Forward 018 RN-09: o bloco de exemplo do README (EN/pt-BR) transcrito (`Contract` →
     * ReadmeMethodCallContract); cada comentário "// true"/"// false" do bloco é uma asserção aqui.
     */
    private function testReadmeF018BlockRunsAsWritten(): void
    {
        $eligible = Spec::specify(ReadmeMethodCallContract::class)
            ->whereMethod('isEligibleFor', [new DateTimeImmutable('2026-12-01')], Spec::isTrue())
            ->and(Spec::calling('installmentsLeft', [3], Spec::greaterThan(0)));

        $this->assertTrue($eligible->isSatisfiedBy(new ReadmeMethodCallContract(new DateTimeImmutable('2027-06-30'), 12)));  // true
        $this->assertFalse($eligible->isSatisfiedBy(new ReadmeMethodCallContract(new DateTimeImmutable('2026-06-30'), 12))); // false (not eligible)
        $this->assertFalse($eligible->isSatisfiedBy(new ReadmeMethodCallContract(new DateTimeImmutable('2027-06-30'), 3)));  // false (nothing left)

        // Declarative: method name and arguments are data, so equality is structural
        $this->assertTrue(Spec::calling('installmentsLeft', [3], Spec::greaterThan(0))
            ->equals(Spec::calling('installmentsLeft', [3], Spec::greaterThan(0))));   // true

        // A missing method is an evaluation error, never a silent false
        $this->assertTrue(Spec::calling('isEligibleFor', [new DateTimeImmutable('2026-12-01')], Spec::isTrue())
            ->evaluate(new \stdClass())->isError);                                      // true
    }
}
