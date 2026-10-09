<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Specifications\Comparison\EqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LessThanSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\JointDenialSpecification;
use Antevemus\ASpecification\Specifications\String\DateStringSpecification;
use Antevemus\ASpecification\Specifications\String\EqualIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\RegexSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LooseEqualSpecification;
use Antevemus\ASpecification\Specifications\Exceptions\IncompatibleTypeException;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\Comparison\InSpecification;
use Antevemus\ASpecification\Specifications\Comparison\IsNullSpecification;
use Antevemus\ASpecification\Specifications\NotSpecification;
use Antevemus\ASpecification\Specifications\OrSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardExpressionMatcherIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Factory\SpecificationFactory;
use Antevemus\ASpecification\Linq\ALinqSpecificationVisitor;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\AndSpecification;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Engine\InMemoryRuleCatalog;
use Antevemus\ASpecification\Engine\RuleDefinition;

interface F019Payable
{
}

interface F019Shippable
{
}

class F019Order implements F019Payable, F019Shippable
{
    public string $status = 'open';
}

class F019Invoice implements F019Payable
{
}

final class F019Receipt
{
}

class Module1_SpecificationsTest extends TestCase
{
    public function run(): void
    {
        $this->testIncompatibleTypesAreRejectedLoudly();

        // Forward 017 (v1.5.0): RN-03 (caixa Unicode) e RN-07 (InSpecification), ambos breaking declarados.
        $this->testRn03IgnoreCaseLeavesAreUnicodeAware();
        $this->testRn07InIsOneSetLeaf();
        $this->testRn07InSetAlgebra();

        // Forward 019 (v1.6.0): detecção estrutural de tautologia e contradição.
        $this->testF019IdentitiesNegationAndDefaults();
        $this->testF019GherkinScenarios();
        $this->testF019LeavesAndRestrictions();
        $this->testF019TruthTableSoundness();
        $this->testReadmeF019BlockRunsAsWritten();
        $this->testF019TypeDisjointnessOnlyWhenSingleInheritanceProvesIt();
        $eq10 = new EqualSpecification(10);
        $gt5 = new GreaterThanSpecification(5);
        $lt20 = new LessThanSpecification(20);
        $notEq = new NotEqualSpecification(99);

        $this->assertTrue($eq10->isSatisfiedBy(10));
        $this->assertFalse($eq10->isSatisfiedBy(5));
        $this->assertTrue($gt5->isSatisfiedBy(8));
        $this->assertFalse($gt5->isSatisfiedBy(3));
        $this->assertTrue($lt20->isSatisfiedBy(15));
        $this->assertFalse($lt20->isSatisfiedBy(25));
        $this->assertTrue($notEq->isSatisfiedBy(10));
        $this->assertFalse($notEq->isSatisfiedBy(99));

        // Composição fluente via AbstractSpecification
        $andSpec = $gt5->and($lt20);
        $this->assertTrue($andSpec->isSatisfiedBy(10));
        $this->assertFalse($andSpec->isSatisfiedBy(30));

        $orSpec = $eq10->or(new EqualSpecification(20));
        $this->assertTrue($orSpec->isSatisfiedBy(10));
        $this->assertTrue($orSpec->isSatisfiedBy(20));
        $this->assertFalse($orSpec->isSatisfiedBy(30));

        $notSpec = $eq10->not();
        $this->assertFalse($notSpec->isSatisfiedBy(10));
        $this->assertTrue($notSpec->isSatisfiedBy(15));

        // Subsunção e disjunção
        $allTrue = new AlwaysTrueSpecification();
        $allFalse = new AlwaysFalseSpecification();
        $this->assertTrue($allTrue->isGeneralizationOf($eq10));
        $this->assertTrue($allTrue->isDisjointWith($allFalse));

        // Strings
        $dateSpec = new DateStringSpecification("Y-m-d");
        $this->assertTrue($dateSpec->isSatisfiedBy("2026-09-26"));
        $this->assertFalse($dateSpec->isSatisfiedBy("invalido"));

        $ignoreCase = new EqualIgnoreCaseStringSpecification("antevemus");
        $this->assertTrue($ignoreCase->isSatisfiedBy("ANTEVEMUS"));
        $this->assertFalse($ignoreCase->isSatisfiedBy("outro"));

        $regex = new RegexSpecification('/^[a-z]+$/');
        $this->assertTrue($regex->isSatisfiedBy("abc"));
        $this->assertFalse($regex->isSatisfiedBy("abc123"));

        $wildcard = new WildcardSpecification("user_*_test");
        $this->assertTrue($wildcard->isSatisfiedBy("user_admin_test"));
        $this->assertFalse($wildcard->isSatisfiedBy("other"));
    }

    /**
     * Comparações e igualdade estrita: tipo incompatível do candidato LANÇA IncompatibleTypeException
     * (nunca true/false em silêncio); igualdade permissiva é opt-in via looselyEqualTo().
     * Reprodução da revisão 2026-10-07 §2.2 e §2.10.
     */
    private function testIncompatibleTypesAreRejectedLoudly(): void
    {
        $gt9 = new GreaterThanSpecification(9);

        // 1. Reprodução: ordenação com tipos mistos devolvia true
        $this->assertThrows(IncompatibleTypeException::class, fn() => $gt9->isSatisfiedBy("abc"), 'gt(9) vs "abc"');
        $this->assertThrows(IncompatibleTypeException::class, fn() => $gt9->isSatisfiedBy([]), 'gt(9) vs []');
        $this->assertThrows(IncompatibleTypeException::class, fn() => $gt9->isSatisfiedBy("10"), 'string numérica é incompatível');
        $this->assertThrows(IncompatibleTypeException::class, fn() => $gt9->isSatisfiedBy(true), 'bool vs int');
        $this->assertThrows(IncompatibleTypeException::class, fn() => (new LessThanSpecification(9))->isSatisfiedBy(new \DateTime()), 'objeto vs int');
        $this->assertThrows(IncompatibleTypeException::class, fn() => Spec::after('2024-01-01')->isSatisfiedBy(new \DateTime('2000-01-01')), 'string vs DateTime');
        $this->assertThrows(IncompatibleTypeException::class, fn() => Spec::after(new \DateTime('2024-01-01'))->isSatisfiedBy('2025-01-01'), 'DateTime vs string');
        $this->assertThrows(IncompatibleTypeException::class, fn() => Spec::between(new \DateTime('2024-01-01'), new \DateTime('2025-01-01'))->isSatisfiedBy(42), 'between de datas vs int');

        // 2. Reprodução: igualdade estrita reprovava em silêncio
        $this->assertThrows(IncompatibleTypeException::class, fn() => (new EqualSpecification(5))->isSatisfiedBy("5"), 'int vs string');
        $this->assertThrows(IncompatibleTypeException::class, fn() => (new EqualSpecification(true))->isSatisfiedBy(1), 'isTrue() vs 1');
        $this->assertThrows(IncompatibleTypeException::class, fn() => Spec::isTrue()->isSatisfiedBy("t"), 'isTrue() vs "t"');
        $this->assertThrows(IncompatibleTypeException::class, fn() => (new NotEqualSpecification(5))->isSatisfiedBy("5"), 'notEqual int vs string');
        $this->assertThrows(IncompatibleTypeException::class, fn() => (new EqualSpecification(5))->isSatisfiedBy(5.0), 'int vs float na igualdade estrita');

        // 3. Sob evaluate() vira erro de avaliação, não falha de regra
        $res = (new EqualSpecification(5))->evaluate("5");
        $this->assertTrue($res->isError);
        $this->assertFalse($res->isSatisfied);
        $this->assertInstanceOf(IncompatibleTypeException::class, $res->exception);

        // 4. Regressão: tipos compatíveis continuam iguais
        $this->assertTrue($gt9->isSatisfiedBy(10));
        $this->assertTrue($gt9->isSatisfiedBy(9.5));
        $this->assertFalse($gt9->isSatisfiedBy(3));
        $this->assertFalse($gt9->isSatisfiedBy(null));
        $this->assertTrue((new GreaterThanSpecification('b'))->isSatisfiedBy('c'));
        $this->assertTrue((new GreaterThanSpecification(1.5))->isSatisfiedBy(2));
        $this->assertTrue((new EqualSpecification(5))->isSatisfiedBy(5));
        $this->assertFalse((new EqualSpecification(5))->isSatisfiedBy(6));
        $this->assertTrue((new EqualSpecification(null))->isSatisfiedBy(null));
        $this->assertFalse((new EqualSpecification(5))->isSatisfiedBy(null));
        $this->assertTrue(Spec::after(new \DateTime('2024-01-01'))->isSatisfiedBy(new \DateTime('2025-01-01')));
        $this->assertFalse(Spec::after(new \DateTime('2024-01-01'))->isSatisfiedBy(null));
        $dt = new \DateTime('2024-01-01');
        $this->assertTrue((new EqualSpecification($dt))->isSatisfiedBy($dt), 'objetos: identidade, sem lançar');
        $this->assertFalse((new EqualSpecification($dt))->isSatisfiedBy(new \DateTime('2024-01-01')));

        // 5. Subsunção com tipos mistos: false, sem lançar (álgebra estática)
        $this->assertFalse($gt9->isGeneralizationOf(new EqualSpecification("abc")));
        $this->assertFalse($gt9->isDisjointWith(new EqualSpecification("abc")));
        $this->assertFalse((new LessThanSpecification(9))->isGeneralizationOf(new EqualSpecification("abc")));
        $this->assertFalse((new EqualSpecification("abc"))->isDisjointWith($gt9));
        $this->assertTrue((new GreaterThanSpecification(5))->isGeneralizationOf(new EqualSpecification(7)));
        $this->assertTrue((new GreaterThanSpecification(5))->isDisjointWith(new EqualSpecification(3)));

        // 6. Opt-in permissiva
        $loose = Spec::looselyEqualTo(5);
        $this->assertTrue($loose->isSatisfiedBy("5"));
        $this->assertTrue($loose->isSatisfiedBy(5.0));
        $this->assertFalse($loose->isSatisfiedBy("6"));
        $this->assertFalse($loose->isSatisfiedBy(null));
        $this->assertTrue(Spec::looselyEqualTo(true)->isSatisfiedBy(1));
        $this->assertTrue(Spec::looselyEqualTo(true)->isSatisfiedBy("t"));
        $this->assertTrue($loose->not()->isSatisfiedBy("6"));
        $this->assertTrue($loose->evaluate("5")->isSatisfied);
        $this->assertInstanceOf(EqualSpecification::class, new LooseEqualSpecification(5), 'visitors SQL/TCriteria/ALinq traduzem como igualdade');
        $this->assertEquals(5, (new LooseEqualSpecification(5))->getValue());
    }

    /**
     * RN-03 (forward 017, v1.5.0, breaking): as duas folhas ignore-case comparam com
     * mb_strtolower(..., 'UTF-8'). Antes (strcasecmp/strtolower) só letras ASCII eram dobradas.
     *
     * Nota: o exemplo do forward ("'ÀGUA' × 'água'") mistura À (grave, U+00C0) com á (agudo,
     * U+00E1): são letras diferentes, não só caixa diferente. O par correto é 'ÁGUA' × 'água'.
     */
    private function testRn03IgnoreCaseLeavesAreUnicodeAware(): void
    {
        // Cenário Gherkin (com o acento corrigido): antes não satisfazia, agora satisfaz
        $this->assertTrue(Spec::equalIgnoreCase('ÁGUA')->isSatisfiedBy('água'));
        $this->assertFalse(strcasecmp('ÁGUA', 'água') === 0, 'controle: strcasecmp (o comportamento antigo) não casava');
        $this->assertTrue(Spec::equalIgnoreCase('ÀGUA')->isSatisfiedBy('àgua'));
        $this->assertTrue(Spec::equalIgnoreCase('AÇÃO')->isSatisfiedBy('ação'));
        $this->assertTrue(Spec::equalIgnoreCase('ẞ')->isSatisfiedBy('ß'), 'ẞ maiúsculo (U+1E9E) baixa para ß');
        $this->assertTrue(Spec::equalIgnoreCase('ΣΟΦΙΑ')->isSatisfiedBy('σοφια'));
        // Acento não é caixa: À e á continuam letras diferentes; sem dobra completa (STRASSE ≠ straße)
        $this->assertFalse(Spec::equalIgnoreCase('ÀGUA')->isSatisfiedBy('água'));
        $this->assertFalse(Spec::equalIgnoreCase('STRASSE')->isSatisfiedBy('straße'));
        $this->assertFalse((new EqualIgnoreCaseStringSpecification('a'))->isSatisfiedBy(null));
        $this->assertFalse((new EqualIgnoreCaseStringSpecification('1'))->isSatisfiedBy(1));

        // Wildcard ignore-case: padrão e candidato baixados com mbstring antes do fnmatch
        $this->assertTrue(Spec::wildcardIgnoreCase('ÁGUA*')->isSatisfiedBy('água mineral'));
        $this->assertTrue((new WildcardExpressionMatcherIgnoreCaseStringSpecification('*ÇÃO'))->isSatisfiedBy('Inscrição'));
        $this->assertFalse((new WildcardExpressionMatcherIgnoreCaseStringSpecification('*ÇÃO'))->isSatisfiedBy('Inscricao'));
        $this->assertFalse(fnmatch(strtolower('*ÇÃO'), strtolower('Inscrição')), 'controle: strtolower (o comportamento antigo) não casava');

        // O predicado compilado do ALinq segue a folha (contrato de paridade)
        $predicate = ALinqSpecificationVisitor::createPredicate(Spec::equalIgnoreCase('ÁGUA'));
        $this->assertTrue($predicate('água'));
        $this->assertFalse($predicate('agua'));
        $this->assertFalse($predicate(42));
    }

    /**
     * RN-07 (forward 017, v1.5.0, breaking): in() é uma folha InSpecification, não mais uma cadeia
     * de OR de equalTo(); igualdade estrita tipada como equalTo(); equals() compara conjuntos.
     */
    private function testRn07InIsOneSetLeaf(): void
    {
        // Antes → depois: o tipo concreto de in(1, 2) era OrSpecification, agora é InSpecification
        $in = Spec::in(1, 2);
        $this->assertInstanceOf(InSpecification::class, $in);
        $this->assertFalse($in instanceof OrSpecification);
        $this->assertEquals([1, 2], $in->getValues());
        $this->assertInstanceOf(InSpecification::class, Spec::in([1, 2]));
        $this->assertInstanceOf(InSpecification::class, \Antevemus\ASpecification\DSL\in('x', 'y'));
        $factory = new SpecificationFactory();
        $this->assertInstanceOf(InSpecification::class, $factory->in('a', 'b'));
        $this->assertInstanceOf(InSpecification::class, $factory->isOneOfValues('a', 'b'));
        $this->assertInstanceOf(InSpecification::class, $factory->isEitherOfValues('a', 'b'));
        $this->assertInstanceOf(InSpecification::class, Spec::in(), 'o vazio também é a folha (contradição), não AlwaysFalse');

        // Cenário Gherkin: igualdade estrita
        $this->assertFalse(Spec::in('A', 'B')->isSatisfiedBy('a'));
        $this->assertTrue(Spec::in('A', 'B')->isSatisfiedBy('B'));
        $this->assertThrows(IncompatibleTypeException::class, fn() => Spec::in(1, 2)->isSatisfiedBy('1'), "'1' não é 1, e nenhum membro é string: erro de tipo como equalTo()");
        $this->assertFalse(Spec::in(1, 2)->isSatisfiedBy(3));
        // Conjunto misto: o candidato só precisa ser comparável com algum membro (antes a ordem do OR decidia se lançava)
        $this->assertTrue(Spec::in(1, 'a')->isSatisfiedBy('a'));
        $this->assertFalse(Spec::in(1, 'a')->isSatisfiedBy('b'));
        // null: só satisfaz se null for membro
        $this->assertTrue(Spec::in('A', null)->isSatisfiedBy(null));
        $this->assertFalse(Spec::in('A')->isSatisfiedBy(null));
        // Objetos: identidade, como equalTo()
        $obj = new \stdClass();
        $this->assertTrue(Spec::in($obj)->isSatisfiedBy($obj));
        $this->assertFalse(Spec::in($obj)->isSatisfiedBy(new \stdClass()));
        // evaluate(): falha nomeada pela folha
        $result = Spec::in(1, 2)->evaluate(3);
        $this->assertFalse($result->isSatisfied);
        $this->assertEquals('InSpecification', $result->failures[0]->ruleName);

        // equals(): conjuntos como conjuntos (ordem e repetição irrelevantes)
        $this->assertTrue(Spec::in(1, 2)->equals(Spec::in(2, 1, 1)));
        $this->assertFalse(Spec::in(1, 2)->equals(Spec::in(1, 3)));
        $this->assertFalse(Spec::in(1, 2)->equals(Spec::in(1, 2, 3)));
        $this->assertFalse(Spec::in(1)->equals(Spec::equalTo(1)));
        $this->assertFalse(Spec::in(1, 2)->equals(Spec::in(1, 2)->because('outra razão')));

        // in([]) é contradição: nunca satisfeito, nunca lança
        $this->assertFalse(Spec::in([])->isSatisfiedBy(1));
        $this->assertFalse(Spec::in()->isSatisfiedBy('x'));

        // notIn() = not(in())
        $notIn = Spec::notIn('A', 'B');
        $this->assertInstanceOf(NotSpecification::class, $notIn);
        $this->assertTrue($notIn->equals(Spec::not(Spec::in('A', 'B'))));
        $this->assertTrue($notIn->isSatisfiedBy('C'));
        $this->assertFalse($notIn->isSatisfiedBy('A'));
        $this->assertTrue(\Antevemus\ASpecification\DSL\notIn([1, 2])->isSatisfiedBy(3));
        $this->assertTrue($factory->notIn(1, 2)->isSatisfiedBy(3));
        $this->assertTrue(Spec::notIn()->isSatisfiedBy('qualquer'), 'notIn do vazio é a tautologia');

        // Dentro de propriedade
        $status = Spec::property('status', Spec::in('A', 'B'));
        $this->assertTrue($status->isSatisfiedBy((object) ['status' => 'A']));
        $this->assertFalse($status->isSatisfiedBy((object) ['status' => 'a']));
    }

    /**
     * RN-07: álgebra da InSpecification na álgebra de subsunção da 1.4.4.
     * in(V) ⊇ equalTo(x) ⇔ x ∈ V; in(V) ⟂ equalTo(x) ⇔ x ∉ V; in × in por subconjunto/interseção;
     * in([]) é contradição (⟂ tudo, generalizado por tudo).
     */
    private function testRn07InSetAlgebra(): void
    {
        $in12 = Spec::in(1, 2);

        // in × equalTo
        $this->assertTrue($in12->isGeneralizationOf(Spec::equalTo(1)));
        $this->assertFalse($in12->isGeneralizationOf(Spec::equalTo(3)));
        $this->assertTrue(Spec::equalTo(2)->isSpecialCaseOf($in12));
        $this->assertTrue($in12->isDisjointWith(Spec::equalTo(3)));
        $this->assertFalse($in12->isDisjointWith(Spec::equalTo(2)));
        $this->assertTrue(Spec::equalTo(3)->isDisjointWith($in12), 'simétrico pelo lado da folha relacional');
        $this->assertFalse(Spec::equalTo(1)->isDisjointWith($in12));
        $this->assertTrue($in12->isDisjointWith(Spec::equalTo('1')), 'tipos diferentes nunca se igualam');
        $this->assertTrue(Spec::equalTo(1)->isGeneralizationOf(Spec::in(1)));
        $this->assertFalse(Spec::equalTo(1)->isGeneralizationOf($in12));

        // in × in: subconjunto e interseção
        $this->assertTrue(Spec::in(1, 2, 3)->isGeneralizationOf(Spec::in(3, 1)));
        $this->assertFalse(Spec::in(1, 3)->isGeneralizationOf(Spec::in(1, 2, 3)));
        $this->assertTrue($in12->isDisjointWith(Spec::in(3, 4)));
        $this->assertFalse($in12->isDisjointWith(Spec::in(2, 3)));
        $this->assertTrue($in12->isGeneralizationOf(Spec::in(2, 1)), 'reflexividade por igualdade de conjuntos');

        // in × faixas e diferenças
        $this->assertTrue(Spec::greaterThan(3)->isGeneralizationOf(Spec::in(4, 5)));
        $this->assertFalse(Spec::greaterThan(3)->isGeneralizationOf(Spec::in(3, 4)));
        $this->assertTrue($in12->isDisjointWith(Spec::greaterThan(5)));
        $this->assertFalse(Spec::in(4, 6)->isDisjointWith(Spec::greaterThan(5)));
        $this->assertTrue(Spec::greaterThan(5)->isDisjointWith($in12));
        $this->assertTrue(Spec::notEqualTo(3)->isGeneralizationOf($in12));
        $this->assertFalse(Spec::notEqualTo(2)->isGeneralizationOf($in12));
        $this->assertTrue(Spec::in(5)->isDisjointWith(Spec::notEqualTo(5)));
        $this->assertFalse($in12->isDisjointWith(Spec::notEqualTo(1)));

        // in × OR de igualdades (a forma antiga): mesmo conjunto, generalização nos dois sentidos
        $orChain = Spec::equalTo(1)->or(Spec::equalTo(2));
        $this->assertTrue($in12->isGeneralizationOf($orChain));
        $this->assertTrue($orChain->isGeneralizationOf($in12));
        $this->assertFalse($in12->equals($orChain), 'equals é estrutural: formas diferentes');

        // notIn
        $notIn12 = Spec::notIn(1, 2);
        $this->assertTrue($notIn12->isGeneralizationOf(Spec::equalTo(3)));
        $this->assertFalse($notIn12->isGeneralizationOf(Spec::equalTo(1)));
        $this->assertTrue($notIn12->isDisjointWith(Spec::equalTo(1)));
        $this->assertTrue($notIn12->isDisjointWith($in12));
        $this->assertTrue($in12->isDisjointWith($notIn12));
        $this->assertTrue($in12->isDisjointWith(Spec::notIn(1, 2, 3)));
        $this->assertFalse($in12->isDisjointWith(Spec::notIn(2, 3)));
        $this->assertTrue(Spec::notIn(1)->isGeneralizationOf(Spec::notIn(1, 2)));

        // null
        $this->assertTrue(Spec::in(null, 1)->isGeneralizationOf(new IsNullSpecification()));
        $this->assertTrue($in12->isDisjointWith(new IsNullSpecification()));
        $this->assertTrue((new IsNullSpecification())->isDisjointWith($in12));
        $this->assertFalse((new IsNullSpecification())->isDisjointWith(Spec::in(null, 1)));

        // Datas: a álgebra compara instantes, como as folhas relacionais
        $d = new \DateTimeImmutable('2026-10-09 10:00:00');
        $this->assertTrue(Spec::in($d)->isGeneralizationOf(Spec::equalTo(new \DateTimeImmutable('2026-10-09 10:00:00'))));
        $this->assertEquals(\DateTimeInterface::class, Spec::in($d)->getType());
        $this->assertEquals('mixed', $in12->getType());

        // in([]) é contradição: disjunta de tudo (inclusive de si e da tautologia), generalizada por tudo
        $empty = Spec::in();
        $this->assertTrue($empty->isDisjointWith(Spec::alwaysTrue()));
        $this->assertTrue($empty->isDisjointWith($empty));
        $this->assertTrue($empty->isDisjointWith(Spec::equalTo(1)));
        $this->assertTrue(Spec::equalTo(1)->isDisjointWith($empty));
        $this->assertTrue(Spec::equalTo(5)->isGeneralizationOf($empty));
        $this->assertTrue($in12->isGeneralizationOf($empty));
        $this->assertTrue(Spec::alwaysFalse()->isGeneralizationOf($empty));
        $this->assertTrue($empty->isGeneralizationOf(Spec::alwaysFalse()));
        $this->assertFalse($empty->isGeneralizationOf(Spec::equalTo(1)));
        $this->assertTrue($empty->isSpecialCaseOf(Spec::equalTo(1)));
        $this->assertTrue(Spec::not($empty)->isGeneralizationOf(Spec::equalTo(1)), '¬∅ é a tautologia');
    }

    /**
     * Forward 019 RN-01, RN-03, RN-04: default "não provado" na base; identidades; negação.
     */
    private function testF019IdentitiesNegationAndDefaults(): void
    {
        // RN-01: default da base é false/false (folhas comuns, predicado opaco)
        foreach ([Spec::equalTo(1), Spec::greaterThan(5), Spec::isNull(), Spec::isNotNull(), Spec::matches('/x/'),
                  new PredicateSpecification(fn($c) => true), Spec::in(1, 2)] as $leaf) {
            $this->assertFalse($leaf->isTautology(), (string) $leaf . ' não é tautologia por si');
            $this->assertFalse($leaf->isContradiction(), (string) $leaf . ' não é contradição por si');
        }

        // RN-03: identidades
        $this->assertTrue(Spec::alwaysTrue()->isTautology());
        $this->assertFalse(Spec::alwaysTrue()->isContradiction());
        $this->assertTrue(Spec::alwaysFalse()->isContradiction());
        $this->assertFalse(Spec::alwaysFalse()->isTautology());

        // RN-04: negação troca os papéis, inclusive em dupla negação
        $this->assertTrue(Spec::not(Spec::alwaysFalse())->isTautology());
        $this->assertTrue(Spec::not(Spec::alwaysTrue())->isContradiction());
        $this->assertTrue(Spec::not(Spec::not(Spec::alwaysTrue()))->isTautology());
        $this->assertFalse(Spec::not(Spec::equalTo(1))->isTautology());
        $this->assertFalse(Spec::not(Spec::equalTo(1))->isContradiction());
        $this->assertTrue(Spec::not(Spec::in())->isTautology(), '¬in([]) é tautologia');

        // RN-06/RN-05 com identidades absorventes
        $x = Spec::property('x', Spec::equalTo(1));
        $this->assertTrue($x->or(Spec::alwaysTrue())->isTautology());
        $this->assertTrue($x->and(Spec::alwaysFalse())->isContradiction());
        $this->assertFalse($x->and(Spec::alwaysTrue())->isTautology(), 'conjunção exige todos tautologias');
        $this->assertTrue(Spec::alwaysTrue()->and(Spec::not(Spec::alwaysFalse()))->isTautology());
        $this->assertFalse($x->or(Spec::alwaysFalse())->isContradiction(), 'disjunção exige todos contradições');
        $this->assertTrue(Spec::alwaysFalse()->or(Spec::in())->isContradiction());
    }

    /**
     * Forward 019 §3: os cenários Gherkin, um a um.
     */
    private function testF019GherkinScenarios(): void
    {
        // Cenário: A ∧ ¬A
        $a = Spec::property('status', Spec::equalTo('x'));
        $this->assertTrue($a->and($a->not())->isContradiction(), 'A ∧ ¬A');
        $this->assertFalse($a->and($a->not())->isTautology());
        $this->assertTrue($a->or($a->not())->isTautology(), 'A ∨ ¬A');
        $this->assertFalse($a->or($a->not())->isContradiction());
        $this->assertTrue($a->not()->and($a)->isContradiction(), 'ordem irrelevante');

        // Cenário: folhas disjuntas (isDisjointWith)
        $isA = Spec::property('status', Spec::equalTo('A'));
        $isB = Spec::property('status', Spec::equalTo('B'));
        $this->assertTrue($isA->and($isB)->isContradiction());
        $this->assertFalse($isA->or($isB)->isTautology());
        $this->assertFalse($isA->or($isB)->isContradiction());
        $this->assertFalse($isA->and(Spec::property('other', Spec::equalTo('B')))->isContradiction(), 'propriedades diferentes');

        // Cenário: conservador (tautologia semântica que a forma não prova)
        $this->assertFalse(Spec::greaterThan(5)->or(Spec::lessThan(10))->isTautology());
        $this->assertFalse(Spec::property('n', Spec::greaterThan(5))->or(Spec::property('n', Spec::lessThan(10)))->isTautology());
        $this->assertFalse(Spec::property('n', Spec::lessThan(5))->or(Spec::property('n', Spec::greaterThanOrEqualTo(5)))->isTautology(),
            'complemento só por equals(not()): x<5 ∨ x>=5 falha para valor nulo');

        // Cenário: achatamento ((a ∧ b) ∧ ¬a)
        $b = Spec::property('kind', Spec::equalTo('k'));
        $this->assertTrue($a->and($b)->and($a->not())->isContradiction());
        $this->assertTrue(new AndSpecification(new AndSpecification($a, $b), new NotSpecification($a)) instanceof ISpecification);
        $this->assertTrue((new AndSpecification(new AndSpecification($a, $b), new NotSpecification($a)))->isContradiction());
        $this->assertTrue((new AndSpecification($b, new AndSpecification($a->not(), $b)))->and($a)->isContradiction(), 'qualquer associação');
        $this->assertTrue($a->or($b)->or($a->not())->isTautology(), 'disjunção achatada');
        $this->assertTrue(Spec::allOf($a, $b, Spec::property('kind', Spec::equalTo('z')))->isContradiction(), 'par disjunto no meio da cadeia');
        $this->assertTrue(Spec::isContradiction($a->and($a->not())));
        $this->assertTrue(Spec::isTautology($a->or($a->not())));

        // RN-07: NOR ≡ ¬(a ∨ b)
        $this->assertTrue(Spec::nor($a, $a->not())->isContradiction(), 'NOR(A, ¬A) ≡ ¬(A ∨ ¬A)');
        $this->assertTrue(Spec::nor(Spec::alwaysFalse(), Spec::in())->isTautology());
        $this->assertTrue(Spec::nor($a, Spec::alwaysTrue())->isContradiction());
        $this->assertFalse(Spec::nor($a, $b)->isContradiction());
        $this->assertFalse(Spec::nor($a, $b)->isTautology());
        $this->assertTrue(Spec::nor($a->or($b), $a->not())->isContradiction(), 'operandos do NOR achatados');
    }

    /**
     * Forward 019 RN-08, RN-09: where() só herda contradição; in([]) e between invertido.
     */
    private function testF019LeavesAndRestrictions(): void
    {
        // RN-09
        $this->assertTrue(Spec::in()->isContradiction());
        $this->assertTrue(Spec::in([])->isContradiction());
        $this->assertFalse(Spec::in(1)->isContradiction());
        $inverted = Spec::between(new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-01-01'));
        $this->assertTrue($inverted->isContradiction(), 'between(a, b) com a > b');
        $this->assertFalse(Spec::between(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-01'))->isContradiction());
        $this->assertTrue(Spec::between(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-01'))->isContradiction() === false, 'intervalo fechado de um ponto');
        $this->assertTrue(Spec::greaterThan(10)->and(Spec::lessThan(5))->isContradiction(), 'faixas disjuntas');
        $this->assertTrue(Spec::equalTo(5)->and(Spec::notEqual(5))->isContradiction());
        $this->assertFalse(Spec::greaterThan(5)->and(Spec::lessThan(10))->isContradiction());

        // RN-08: where(prop, X) herda só a contradição de X
        $this->assertTrue(Spec::property('a', Spec::alwaysFalse())->isContradiction());
        $this->assertTrue(Spec::property('a', Spec::in())->isContradiction());
        $this->assertTrue(Spec::property('a', Spec::greaterThan(10)->and(Spec::lessThan(5)))->isContradiction());
        $this->assertFalse(Spec::property('a', Spec::alwaysTrue())->isTautology(), 'propriedade ausente ou nula não satisfaz');
        $this->assertFalse(Spec::property('a', Spec::alwaysTrue())->isSatisfiedBy((object) ['a' => null]));
        $this->assertTrue(Spec::property('a', Spec::alwaysFalse())->not()->isTautology(), '¬where(contradição)');
        $this->assertTrue(Spec::property('a', Spec::alwaysTrue(), Spec::alwaysFalse())->isContradiction(), 'base contraditória');
    }

    /**
     * Forward 019 RN-02: guarda de soundness por tabela-verdade. Árvores aleatórias (semente fixa)
     * sobre três folhas, avaliadas em todos os candidatos possíveis: isTautology() ⇒ todas
     * verdadeiras; isContradiction() ⇒ todas falsas. Dois conjuntos de folhas: igualdades (o do
     * requisito) e relacionais (guarda da álgebra de faixas e da inversão de operador com valor nulo).
     */
    private function testF019TruthTableSoundness(): void
    {
        $pools = [
            'igualdade' => [
                'leaves' => [
                    Spec::property('a', Spec::equalTo(1)),
                    Spec::property('a', Spec::equalTo(2)),
                    Spec::property('b', Spec::equalTo(1)),
                ],
                'domain' => [1, 2, null],
            ],
            'relacional' => [
                'leaves' => [
                    Spec::property('a', Spec::lessThan(2)),
                    Spec::property('a', Spec::greaterThanOrEqualTo(2)),
                    Spec::property('b', Spec::greaterThan(1)),
                ],
                'domain' => [1, 2, 3, null],
            ],
        ];

        foreach ($pools as $poolName => $pool) {
            $candidates = [];
            foreach ($pool['domain'] as $va) {
                foreach ($pool['domain'] as $vb) {
                    $candidates[] = (object) ['a' => $va, 'b' => $vb];
                }
            }

            mt_srand(20261009);
            $tautologies = 0;
            $contradictions = 0;
            $violations = [];
            for ($i = 0; $i < 1500; $i++) {
                $tree = $this->randomTree($pool['leaves'], 4);
                $values = array_map(static fn(object $c): bool => $tree->isSatisfiedBy($c), $candidates);

                $isTautology = $tree->isTautology();
                $isContradiction = $tree->isContradiction();
                $label = sprintf('[%s #%d] %s', $poolName, $i, (string) $tree);

                if ($isTautology && $isContradiction) {
                    $violations[] = "tautologia e contradição ao mesmo tempo: {$label}";
                }
                if ($isTautology) {
                    $tautologies++;
                    if (in_array(false, $values, true)) {
                        $violations[] = "tautologia refutada por um candidato: {$label}";
                    }
                }
                if ($isContradiction) {
                    $contradictions++;
                    if (in_array(true, $values, true)) {
                        $violations[] = "contradição satisfeita por um candidato: {$label}";
                    }
                }
            }

            // Nenhum true indevido em 1500 árvores × todos os candidatos (uma asserção por conjunto).
            $this->assertEquals([], $violations, "[{$poolName}] " . implode(PHP_EOL, array_slice($violations, 0, 5)));
            // A guarda não é vacuamente verdadeira: a detecção disparou nos dois sentidos.
            $this->assertTrue($tautologies > 0, "[{$poolName}] nenhuma tautologia detectada");
            $this->assertTrue($contradictions > 0, "[{$poolName}] nenhuma contradição detectada");
        }
    }

    /**
     * Árvore aleatória sobre as folhas dadas: NOT, AND, OR, NOR e os padrões X ∧ ¬X / X ∨ ¬X
     * (para que a detecção positiva seja exercitada, não só a negativa).
     *
     * @param list<ISpecification> $leaves
     */
    private function randomTree(array $leaves, int $depth): ISpecification
    {
        if ($depth === 0 || mt_rand(0, 9) < 3) {
            return $leaves[mt_rand(0, count($leaves) - 1)];
        }

        $d = $depth - 1;
        return match (mt_rand(0, 6)) {
            0 => new NotSpecification($this->randomTree($leaves, $d)),
            1, 2 => new AndSpecification($this->randomTree($leaves, $d), $this->randomTree($leaves, $d)),
            3, 4 => new OrSpecification($this->randomTree($leaves, $d), $this->randomTree($leaves, $d)),
            5 => new JointDenialSpecification($this->randomTree($leaves, $d), $this->randomTree($leaves, $d)),
            default => (function () use ($leaves, $d): ISpecification {
                $x = $this->randomTree($leaves, $d);
                $other = $this->randomTree($leaves, $d);
                return mt_rand(0, 1) === 0
                    ? new AndSpecification(new AndSpecification($x, $other), $x->not())
                    : new OrSpecification($x->not(), new OrSpecification($other, $x));
            })(),
        };
    }

    /**
     * Forward 019 RN-12: o bloco de exemplo do README (EN/pt-BR) transcrito; cada comentário
     * "// true"/"// false" do bloco é uma asserção aqui.
     */
    private function testReadmeF019BlockRunsAsWritten(): void
    {
        $active   = Spec::property('status', Spec::equalTo('ACTIVE'));
        $inactive = Spec::property('status', Spec::equalTo('INACTIVE'));

        $this->assertTrue(Spec::isContradiction($active->and($active->not())));      // true  (A ∧ ¬A)
        $this->assertTrue(Spec::isTautology($active->or($active->not())));           // true  (A ∨ ¬A)
        $this->assertTrue($active->and($inactive)->isContradiction());               // true  (disjoint leaves, same property)
        $this->assertTrue(Spec::in()->isContradiction());                            // true  (the empty set)
        $this->assertFalse(Spec::greaterThan(5)->or(Spec::lessThan(10))->isTautology()); // false: not proven (no SAT solving)

        // Rule engine: report the rules that can never pass (or never fail), without blocking
        $catalog = new InMemoryRuleCatalog();
        $catalog->addRule(new RuleDefinition(codigo: 'R-12', nome: 'Active and inactive', tipoRegra: 'status_both', escopo: 'rental_contract'));
        $registry = Spec::ruleRegistry()->registerClosure('status_both', fn() => $active->and($inactive));

        $engine = Spec::engine($catalog, $registry)->withCatalogValidation();
        $engine->compileSpecification('rental_contract');
        $lines = [];
        foreach ($engine->getCompilationWarnings() as $warning) {
            $lines[] = $warning->ruleCode . ': ' . $warning->kind;                // "R-12: contradiction"
        }
        $this->assertEquals(['R-12: contradiction'], $lines);
    }

    /**
     * Forward 019 RN-02 (achado da guarda): tipos sem relação só são disjuntos quando a herança
     * simples prova. Antes, specify(IFoo) ⟂ specify(IBar) e a conjunção virava contradição falsa
     * embora uma classe possa implementar as duas.
     */
    private function testF019TypeDisjointnessOnlyWhenSingleInheritanceProvesIt(): void
    {
        $payable = Spec::specify(F019Payable::class);
        $shippable = Spec::specify(F019Shippable::class);
        $order = new F019Order();

        // Duas interfaces: não disjuntas, a conjunção não é contradição e é satisfeita
        $this->assertFalse($payable->isDisjointWith($shippable));
        // (o and() fluente de specify(T) mantém a guarda de paridade Domian por tipos atribuíveis;
        // a conjunção é montada diretamente)
        $both = new AndSpecification($payable, $shippable);
        $this->assertFalse($both->isContradiction());
        $this->assertTrue($both->isSatisfiedBy($order));
        $this->assertFalse(Spec::property('status', Spec::equalTo('open'), $payable)
            ->isDisjointWith(Spec::property('status', Spec::equalTo('open'), $shippable)));

        // Interface × classe não final: uma subclasse pode implementar a interface
        $this->assertFalse(Spec::specify(F019Shippable::class)->isDisjointWith(Spec::specify(F019Invoice::class)));
        // Interface × classe final que não a implementa: disjuntas
        $this->assertTrue(Spec::specify(F019Shippable::class)->isDisjointWith(Spec::specify(F019Receipt::class)));
        // Duas classes sem relação: disjuntas (herança simples), como antes
        $this->assertTrue(Spec::specify(F019Invoice::class)->isDisjointWith(Spec::specify(F019Receipt::class)));
        $this->assertTrue((new AndSpecification(Spec::specify(F019Invoice::class), Spec::specify(F019Order::class)))->isContradiction());
        // Hierarquia: nunca disjuntas
        $this->assertFalse(Spec::specify(F019Payable::class)->isDisjointWith(Spec::specify(F019Order::class)));

        // Folhas de valor × spec tipada: disjunção só quando identidade estrita ou a comparação
        // (que lançaria para um candidato do tipo) prova. Antes, notEqual(null) e greaterThan(DateTime)
        // eram declaradas disjuntas de specify(DateTimeImmutable) só pela classe do valor.
        $immutable = Spec::specify(\DateTimeImmutable::class);
        $afterMutable = Spec::greaterThan(new \DateTime('2020-01-01'));
        $this->assertFalse($immutable->isDisjointWith($afterMutable));
        $this->assertFalse((new AndSpecification($immutable, $afterMutable))->isContradiction());
        $this->assertTrue((new AndSpecification($immutable, $afterMutable))->isSatisfiedBy(new \DateTimeImmutable('2025-01-01')));
        $invoice = Spec::specify(F019Invoice::class);
        $this->assertFalse($invoice->isDisjointWith(Spec::notEqual(null)), 'qualquer Invoice é !== null');
        $this->assertTrue($invoice->isDisjointWith(Spec::notEqual(5)), 'objeto × escalar lança: nenhuma Invoice satisfaz');
        $this->assertTrue($invoice->isDisjointWith(Spec::greaterThan(5)));
        $this->assertTrue($invoice->isDisjointWith(Spec::after(new \DateTimeImmutable('2020-01-01'))), 'classe que não é data');
        $this->assertFalse(Spec::specify(F019Payable::class)->isDisjointWith(Spec::after(new \DateTimeImmutable('2020-01-01'))),
            'interface: uma subclasse de DateTime pode implementá-la');
        $this->assertTrue($invoice->isDisjointWith(Spec::equalTo(new F019Order())), 'identidade estrita, como antes');
        $this->assertFalse(Spec::specify(F019Order::class)->isDisjointWith(Spec::equalTo(new F019Order())));

        // Mesmo instante não limita a classe do candidato: DateTime e DateTimeImmutable no mesmo instante
        $instant = Spec::atTheSameTimeAs(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $this->assertFalse($immutable->isGeneralizationOf($instant));
        $outsideImmutable = new AndSpecification($immutable->not(), $instant);
        $this->assertFalse($outsideImmutable->isContradiction());
        $this->assertTrue($outsideImmutable->isSatisfiedBy(new \DateTime('2026-01-01 00:00:00')));
    }
}
