<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Factory\SpecificationFactory;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Tests\TestCase;
use DateTimeImmutable;
use InvalidArgumentException;

use function Antevemus\ASpecification\DSL\{
    specify, prop, property, is, not, isBefore, before, after, isAfter, afterOrAtTheSameTimeAs,
    allOf, anyOf, equal, equalTo, notEqual, greaterThan, lessThan, in,
    alwaysTrue, alwaysFalse, isNull, isNotNull, contains, startsWith
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
}
