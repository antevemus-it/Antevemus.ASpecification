<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Specifications\Comparison\EqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LessThanSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;

class Module8_SpecificationResultTest extends TestCase
{
    public function run(): void
    {
        $this->testSpecificationFailureObject();
        $this->testSpecificationResultObject();
        $this->testBasicEvaluateAndDefaultMessage();
        $this->testBecauseAndWithCodeImmutability();
        $this->testAndNotAndOrNotSugar();
        $this->testAndSpecificationAggregation();
        $this->testOrSpecificationDiagnostics();
        $this->testNotSpecificationDiagnostics();
        $this->testPropertySpecificationDiagnostics();
        $this->testRealWorldBusinessRulesScenario();
    }

    private function testSpecificationFailureObject(): void
    {
        $failure = new SpecificationFailure(
            message: "Multa incorreta",
            code: "INQ_004",
            ruleName: "MultaSpecification",
            property: "multa",
            metadata: ["esperado" => 500, "informado" => 1000]
        );

        $this->assertEquals("Multa incorreta", $failure->message);
        $this->assertEquals("INQ_004", $failure->code);
        $this->assertEquals("MultaSpecification", $failure->ruleName);
        $this->assertEquals("multa", $failure->property);
        $this->assertEquals(500, $failure->metadata['esperado']);

        $withProp = $failure->withProperty('valorRescisorio');
        $this->assertEquals('valorRescisorio', $withProp->property);
        $this->assertEquals("Multa incorreta", $withProp->message);

        $this->assertTrue(str_contains((string)$failure, "[INQ_004]"));
    }

    private function testSpecificationResultObject(): void
    {
        // Satisfeito
        $ok = SpecificationResult::satisfied();
        $this->assertTrue($ok->isSatisfied);
        $this->assertEquals(0, count($ok));
        $this->assertEquals("Satisfied", (string)$ok);
        $this->assertFalse($ok->hasError());

        // Falha
        $fail = SpecificationResult::failure("Idade mínima não atingida", "AGE_01", "AgeSpec", "idade");
        $this->assertFalse($fail->isSatisfied);
        $this->assertEquals(1, count($fail));
        $this->assertTrue($fail->hasError());
        $this->assertTrue($fail->hasError("AGE_01"));
        $this->assertFalse($fail->hasError("OTHER_CODE"));
        $this->assertEquals(["Idade mínima não atingida"], $fail->getReasons());
        $this->assertEquals(["AGE_01"], $fail->getCodes());

        // Combinação
        $combined = SpecificationResult::combine(
            SpecificationResult::satisfied(),
            SpecificationResult::failure("Erro 1", "E1"),
            SpecificationResult::failure("Erro 2", "E2")
        );
        $this->assertFalse($combined->isSatisfied);
        $this->assertEquals(2, count($combined));
        $this->assertEquals(["Erro 1", "Erro 2"], $combined->getReasons());
        $this->assertEquals(["E1", "E2"], $combined->getCodes());
    }

    private function testBasicEvaluateAndDefaultMessage(): void
    {
        $spec = new EqualSpecification(10);

        $res1 = $spec->evaluate(10);
        $this->assertTrue($res1->isSatisfied);

        $res2 = $spec->evaluate(5);
        $this->assertFalse($res2->isSatisfied);
        $this->assertEquals(1, count($res2));
        $this->assertTrue(str_contains($res2->getReasons()[0], "EqualSpecification"));
    }

    private function testBecauseAndWithCodeImmutability(): void
    {
        $base = new GreaterThanSpecification(18);

        $custom = $base
            ->because("O titular deve ser maior de 18 anos.")
            ->withCode("MAIORIDADE_LEGAL");

        // Imutabilidade estrita via clone
        $this->assertFalse($base === $custom);

        $resBase = $base->evaluate(15);
        $this->assertFalse(str_contains($resBase->getReasons()[0], "O titular deve ser maior de 18 anos."));

        $resCustom = $custom->evaluate(15);
        $this->assertEquals(["O titular deve ser maior de 18 anos."], $resCustom->getReasons());
        $this->assertEquals(["MAIORIDADE_LEGAL"], $resCustom->getCodes());
    }

    private function testAndNotAndOrNotSugar(): void
    {
        $gt5 = new GreaterThanSpecification(5);
        $gt20 = new GreaterThanSpecification(20);

        // andNot: > 5 E NÃO > 20 (ou seja, 5 < x <= 20)
        $between5and20 = $gt5->andNot($gt20);

        $this->assertTrue($between5and20->isSatisfiedBy(10));
        $this->assertTrue($between5and20->isSatisfiedBy(20));
        $this->assertFalse($between5and20->isSatisfiedBy(25));
        $this->assertFalse($between5and20->isSatisfiedBy(3));

        // orNot: > 20 OU NÃO > 5
        $orNotSpec = $gt20->orNot($gt5);
        $this->assertTrue($orNotSpec->isSatisfiedBy(25)); // > 20
        $this->assertTrue($orNotSpec->isSatisfiedBy(3));  // NOT > 5
        $this->assertFalse($orNotSpec->isSatisfiedBy(10)); // falso para ambos
    }

    private function testAndSpecificationAggregation(): void
    {
        $specA = (new GreaterThanSpecification(10))
            ->because("Deve ser maior que 10.")
            ->withCode("ERR_GT_10");

        $specB = (new LessThanSpecification(50))
            ->because("Deve ser menor que 50.")
            ->withCode("ERR_LT_50");

        $and = $specA->and($specB);

        // Caso onde apenas o segundo falha
        $resSingleFail = $and->evaluate(60);
        $this->assertFalse($resSingleFail->isSatisfied);
        $this->assertEquals(["Deve ser menor que 50."], $resSingleFail->getReasons());
        $this->assertEquals(["ERR_LT_50"], $resSingleFail->getCodes());

        // Caso com custom reason no próprio AND
        $andCustom = $and->because("Intervalo [10, 50] violado.")->withCode("INTERVALO_INVALIDO");
        $resCustom = $andCustom->evaluate(60);
        $this->assertFalse($resCustom->isSatisfied);
        $this->assertTrue(in_array("Intervalo [10, 50] violado.", $resCustom->getReasons(), true));
        $this->assertTrue(in_array("Deve ser menor que 50.", $resCustom->getReasons(), true));
    }

    private function testOrSpecificationDiagnostics(): void
    {
        $specA = (new EqualSpecification('ADMIN'))->because("Não é ADMIN.")->withCode("AUTH_01");
        $specB = (new EqualSpecification('MANAGER'))->because("Não é MANAGER.")->withCode("AUTH_02");

        $or = $specA->or($specB);

        // Um passa
        $this->assertTrue($or->evaluate('ADMIN')->isSatisfied);
        $this->assertTrue($or->evaluate('MANAGER')->isSatisfied);

        // Ambos falham: consolida falhas
        $res = $or->evaluate('GUEST');
        $this->assertFalse($res->isSatisfied);
        $this->assertEquals(["Não é ADMIN.", "Não é MANAGER."], $res->getReasons());
        $this->assertEquals(["AUTH_01", "AUTH_02"], $res->getCodes());
    }

    private function testNotSpecificationDiagnostics(): void
    {
        $isBlocked = (new EqualSpecification(true))->because("O usuário está bloqueado.")->withCode("USR_BLOCKED");
        $isNotBlocked = $isBlocked->not()->withCode("NOT_BLOCKED_RULE");

        // Se bloqueado (true), a negação falha e relata motivo
        $resFail = $isNotBlocked->evaluate(true);
        $this->assertFalse($resFail->isSatisfied);
        $this->assertEquals("NOT_BLOCKED_RULE", $resFail->getCodes()[0]);

        // Se não bloqueado (false), a negação é satisfeita
        $resOk = $isNotBlocked->evaluate(false);
        $this->assertTrue($resOk->isSatisfied);
    }

    private function testPropertySpecificationDiagnostics(): void
    {
        $baseSpec = new AlwaysTrueSpecification();
        $ageSpec = (new GreaterThanSpecification(18))->because("Idade insuficiente.")->withCode("MIN_AGE");

        $propSpec = new PropertySpecification($baseSpec, 'age', $ageSpec);

        $validUser = (object)['age' => 25];
        $this->assertTrue($propSpec->evaluate($validUser)->isSatisfied);

        $underageUser = (object)['age' => 15];
        $res = $propSpec->evaluate($underageUser);
        $this->assertFalse($res->isSatisfied);
        $this->assertEquals('age', $res->failures[0]->property);
        $this->assertEquals("MIN_AGE", $res->getCodes()[0]);
    }

    private function testRealWorldBusinessRulesScenario(): void
    {
        // Cenário inspirado na Lei do Inquilinato & Regulação de Sinistros
        $multaProporcional = (new EqualSpecification(1000))
            ->because("A multa rescisória deve ser proporcional ao período restante (Art. 4º da Lei 8.245/91).")
            ->withCode("INQ_ART_4");

        $houveAgravamentoRisco = (new EqualSpecification(1000))
            ->because("Houve agravamento intencional do risco pelo segurado.")
            ->withCode("AGRAVAMENTO_RISCO");

        // Regra: Multa deve ser 1000 E NÃO deve haver agravamento (com parâmetro diferente)
        $regraSinistro = $multaProporcional->andNot(
            (new EqualSpecification(9999))->because("Detectado agravamento de risco.")->withCode("AGRAVAMENTO")
        );

        // Caso aprovado: 1000 satisfaz $multaProporcional e não é 9999
        $this->assertTrue($regraSinistro->isSatisfiedBy(1000));
        $resAprovado = $regraSinistro->evaluate(1000);
        $this->assertTrue($resAprovado->isSatisfied);

        // Caso reprovado: 500 falha na multa proporcional
        $resReprovado = $regraSinistro->evaluate(500);
        $this->assertFalse($resReprovado->isSatisfied);
        $this->assertTrue($resReprovado->hasError("INQ_ART_4"));
        $this->assertEquals(["A multa rescisória deve ser proporcional ao período restante (Art. 4º da Lei 8.245/91)."], $resReprovado->getReasons());
    }
}
