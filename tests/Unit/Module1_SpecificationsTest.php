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

class Module1_SpecificationsTest extends TestCase
{
    public function run(): void
    {
        $this->testIncompatibleTypesAreRejectedLoudly();

        // Forward 017 (v1.5.0): RN-03 (caixa Unicode) e RN-07 (InSpecification), ambos breaking declarados.
        $this->testRn03IgnoreCaseLeavesAreUnicodeAware();
        $this->testRn07InIsOneSetLeaf();
        $this->testRn07InSetAlgebra();
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
}
