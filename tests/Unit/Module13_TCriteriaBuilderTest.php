<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Adianti\Database\TCriteria;
use Adianti\Database\TExpression;
use Adianti\Database\TFilter;
use Antevemus\ASpecification\Criteria\Exceptions\NonTranslatableCriteriaException;
use Antevemus\ASpecification\Criteria\TCriteriaBuilder;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Tests\TestCase;

/**
 * Module13_TCriteriaBuilderTest - Suíte de Testes para o TCriteria Builder (Módulo 13)
 *
 * Valida a compilação completa da AST de especificações de domínio para objetos TCriteria
 * do Adianti Framework, incluindo precedência de parênteses, inversão de De Morgan e paginação.
 *
 * Funcionalidades:
 * - Validação de compilação AST para TCriteria/TFilter
 * - Verificação de De Morgan e inversão lógica relacional
 * - Teste de precedência e aninhamento de parênteses
 * - Suporte a propriedades de paginação, ordenação e mapeamento de colunas
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class Module13_TCriteriaBuilderTest extends TestCase
{
    public function run(): void
    {
        $this->testEqualAndNotEqualFilter();
        $this->testComparisonFilters();
        $this->testWildcardAndRegexFilters();
        $this->testConjunctionAndDisjunction();
        $this->testNestedPrecedence();
        $this->testNotDeMorganInversion();
        $this->testFieldMapper();
        $this->testPropertiesPaginationAndSorting();
        $this->testFluentBuilder();
        $this->testSpecFacadeAndInstanceMethods();
        $this->testTautologyAndContradiction();
        $this->testNonTranslatableExceptions();
    }

    private function testEqualAndNotEqualFilter(): void
    {
        // 1. Igualdade escalar
        $spec1 = Spec::property('status', Spec::equal('ativo'));
        $criteria1 = TCriteriaBuilder::fromSpecification($spec1);
        $this->assertInstanceOf(TCriteria::class, $criteria1);
        $this->assertEquals("(status = 'ativo')", $criteria1->dump());

        // 2. Igualdade nula (IS NULL)
        $spec2 = Spec::property('deleted_at', Spec::equal(null));
        $criteria2 = TCriteriaBuilder::fromSpecification($spec2);
        $this->assertEquals("(deleted_at IS NULL)", $criteria2->dump());

        // 3. Desigualdade escalar
        $spec3 = Spec::property('status', Spec::notEqual('inativo'));
        $criteria3 = TCriteriaBuilder::fromSpecification($spec3);
        $this->assertEquals("(status <> 'inativo')", $criteria3->dump());

        // 4. Desigualdade nula (IS NOT NULL)
        $spec4 = Spec::property('deleted_at', Spec::notEqual(null));
        $criteria4 = TCriteriaBuilder::fromSpecification($spec4);
        $this->assertEquals("(deleted_at IS NOT NULL)", $criteria4->dump());
    }

    private function testComparisonFilters(): void
    {
        // GreaterThan
        $spec1 = Spec::property('idade', Spec::greaterThan(18));
        $c1 = TCriteriaBuilder::fromSpecification($spec1);
        $this->assertEquals("(idade > 18)", $c1->dump());

        // LessThan
        $spec2 = Spec::property('preco', Spec::lessThan(100));
        $c2 = TCriteriaBuilder::fromSpecification($spec2);
        $this->assertEquals("(preco < 100)", $c2->dump());

        // NotNull
        $spec3 = Spec::property('email', Spec::isNotNull());
        $c3 = TCriteriaBuilder::fromSpecification($spec3);
        $this->assertEquals("(email IS NOT NULL)", $c3->dump());
    }

    private function testWildcardAndRegexFilters(): void
    {
        // Wildcard: 'São*' => 'São%'
        $spec1 = Spec::property('cidade', Spec::wildcard('São*'));
        $c1 = TCriteriaBuilder::fromSpecification($spec1);
        $this->assertEquals("(cidade LIKE 'São%')", $c1->dump());

        // Wildcard Case Insensitive
        $spec2 = Spec::property('cidade', Spec::wildcardExpressionMatcherIgnoreCase('são*'));
        $c2 = TCriteriaBuilder::fromSpecification($spec2);
        $this->assertEquals("(UPPER(cidade) LIKE UPPER('são%'))", $c2->dump());

        // Equal Case Insensitive
        $spec3 = Spec::property('sigla', Spec::equalIgnoreCase('sp'));
        $c3 = TCriteriaBuilder::fromSpecification($spec3);
        $this->assertEquals("(UPPER(sigla) LIKE UPPER('sp'))", $c3->dump());

        // Regex
        $spec4 = Spec::property('cpf', Spec::regex('/^[0-9]+$/'));
        $c4 = TCriteriaBuilder::fromSpecification($spec4);
        $this->assertEquals("(cpf REGEXP '^[0-9]+$')", $c4->dump());
    }

    private function testConjunctionAndDisjunction(): void
    {
        // AND
        $specAnd = Spec::property('status', Spec::equal('ativo'))
            ->and(Spec::property('idade', Spec::greaterThan(18)));
        $cAnd = TCriteriaBuilder::fromSpecification($specAnd);
        $this->assertEquals("(status = 'ativo' AND idade > 18)", $cAnd->dump());

        // OR
        $specOr = Spec::property('status', Spec::equal('ativo'))
            ->or(Spec::property('status', Spec::equal('pendente')));
        $cOr = TCriteriaBuilder::fromSpecification($specOr);
        $this->assertEquals("(status = 'ativo' OR status = 'pendente')", $cOr->dump());
    }

    private function testNestedPrecedence(): void
    {
        // Regra complexa: status = 'ativo' AND (salario > 5000 OR score > 700)
        $spec = Spec::property('status', Spec::equal('ativo'))
            ->and(
                Spec::property('salario', Spec::greaterThan(5000))
                    ->or(Spec::property('score', Spec::greaterThan(700)))
            );

        $criteria = TCriteriaBuilder::fromSpecification($spec);
        $this->assertEquals("(status = 'ativo' AND (salario > 5000 OR score > 700))", $criteria->dump());
    }

    private function testNotDeMorganInversion(): void
    {
        // NOT(=) => <>
        $spec1 = Spec::property('status', Spec::not(Spec::equal('CANCELADO')));
        $this->assertEquals("(status <> 'CANCELADO')", TCriteriaBuilder::fromSpecification($spec1)->dump());

        // NOT(>) => <=
        $spec2 = Spec::property('idade', Spec::not(Spec::greaterThan(18)));
        $this->assertEquals("(idade <= 18)", TCriteriaBuilder::fromSpecification($spec2)->dump());

        // NOT(<) => >=
        $spec3 = Spec::property('preco', Spec::not(Spec::lessThan(50)));
        $this->assertEquals("(preco >= 50)", TCriteriaBuilder::fromSpecification($spec3)->dump());

        // NOT(IS NOT NULL) => IS NULL
        $spec4 = Spec::property('deleted_at', Spec::not(Spec::isNotNull()));
        $this->assertEquals("(deleted_at IS NULL)", TCriteriaBuilder::fromSpecification($spec4)->dump());

        // NOT(IS NULL) => IS NOT NULL
        $spec5 = Spec::property('deleted_at', Spec::not(Spec::equal(null)));
        $this->assertEquals("(deleted_at IS NOT NULL)", TCriteriaBuilder::fromSpecification($spec5)->dump());

        // NOT(LIKE) => NOT LIKE
        $spec6 = Spec::property('nome', Spec::not(Spec::wildcard('Admin*')));
        $this->assertEquals("(nome NOT LIKE 'Admin%')", TCriteriaBuilder::fromSpecification($spec6)->dump());

        // De Morgan: NOT(A AND B) => NOT A OR NOT B
        $specAnd = Spec::property('ativo', Spec::equal(true))
            ->and(Spec::property('bloqueado', Spec::equal(false)));
        $notAnd = Spec::not($specAnd);
        $this->assertEquals("(ativo <> TRUE OR bloqueado <> FALSE)", TCriteriaBuilder::fromSpecification($notAnd)->dump());

        // De Morgan: NOT(A OR B) => NOT A AND NOT B
        $specOr = Spec::property('status', Spec::equal('A'))
            ->or(Spec::property('status', Spec::equal('B')));
        $notOr = Spec::not($specOr);
        $this->assertEquals("(status <> 'A' AND status <> 'B')", TCriteriaBuilder::fromSpecification($notOr)->dump());
    }

    private function testFieldMapper(): void
    {
        $spec = Spec::property('nomeCompleto', Spec::equal('Heliton'))
            ->and(Spec::property('dataNascimento', Spec::greaterThan('2000-01-01')));

        $criteria = TCriteriaBuilder::fromSpecification($spec, [
            'nomeCompleto'   => 'nm_pessoa',
            'dataNascimento' => 'dt_nascimento'
        ]);

        $this->assertEquals("(nm_pessoa = 'Heliton' AND dt_nascimento > '2000-01-01')", $criteria->dump());
    }

    private function testPropertiesPaginationAndSorting(): void
    {
        $spec = Spec::property('status', Spec::equal('ativo'));

        $criteria = TCriteriaBuilder::fromSpecification($spec, null, [
            'order'     => 'id',
            'direction' => 'desc',
            'limit'     => 50,
            'offset'    => 100,
            'group'     => 'categoria_id'
        ]);

        $this->assertEquals('id', $criteria->getProperty('order'));
        $this->assertEquals('desc', $criteria->getProperty('direction'));
        $this->assertEquals(50, $criteria->getProperty('limit'));
        $this->assertEquals(100, $criteria->getProperty('offset'));
        $this->assertEquals('categoria_id', $criteria->getProperty('group'));
    }

    private function testFluentBuilder(): void
    {
        $spec = Spec::property('valor', Spec::greaterThan(1000));

        $builder = Spec::criteriaBuilder($spec)
            ->orderBy('dt_cadastro', 'desc')
            ->limit(20, 40)
            ->groupBy('departamento_id');

        $criteria = $builder->build();
        $this->assertEquals("(valor > 1000)", $criteria->dump());
        $this->assertEquals('dt_cadastro', $criteria->getProperty('order'));
        $this->assertEquals('desc', $criteria->getProperty('direction'));
        $this->assertEquals(20, $criteria->getProperty('limit'));
        $this->assertEquals(40, $criteria->getProperty('offset'));
        $this->assertEquals('departamento_id', $criteria->getProperty('group'));
    }

    private function testSpecFacadeAndInstanceMethods(): void
    {
        $spec = Spec::property('ativo', Spec::equalTo(true));

        // Facade estática
        $c1 = Spec::toCriteria($spec);
        $this->assertInstanceOf(TCriteria::class, $c1);
        $this->assertEquals("(ativo = TRUE)", $c1->dump());

        // Método na instância de ISpecification
        $c2 = $spec->toCriteria(['ativo' => 'fl_ativo']);
        $this->assertInstanceOf(TCriteria::class, $c2);
        $this->assertEquals("(fl_ativo = TRUE)", $c2->dump());
    }

    private function testTautologyAndContradiction(): void
    {
        $cTrue = TCriteriaBuilder::fromSpecification(Spec::alwaysTrue());
        $this->assertEquals("(1 = 1)", $cTrue->dump());

        $cFalse = TCriteriaBuilder::fromSpecification(Spec::alwaysFalse());
        $this->assertEquals("(1 = 0)", $cFalse->dump());
    }

    private function testNonTranslatableExceptions(): void
    {
        // Leaf isolada sem PropertySpecification deve lançar exceção explicativa
        $orphanLeaf = Spec::equal('invalido');
        $threw = false;
        try {
            TCriteriaBuilder::fromSpecification($orphanLeaf);
        } catch (NonTranslatableCriteriaException $e) {
            $threw = true;
            $this->assertTrue(str_contains($e->getMessage(), "PropertySpecification"));
        }
        $this->assertTrue($threw);
    }
}
