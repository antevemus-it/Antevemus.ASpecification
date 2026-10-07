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

class Module1_SpecificationsTest extends TestCase
{
    public function run(): void
    {
        $this->testIncompatibleTypesAreRejectedLoudly();
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
}
