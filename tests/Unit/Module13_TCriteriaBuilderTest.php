<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Adianti\Database\TCriteria;
use Adianti\Database\TExpression;
use Adianti\Database\TFilter;
use Antevemus\ASpecification\Criteria\Exceptions\NonTranslatableCriteriaException;
use Antevemus\ASpecification\Criteria\Exceptions\UnsafeCriteriaValueException;
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
        $this->testMagicAdiantiPrefixesAreRejected();
        $this->testCaseInsensitiveSurvivesCriteriaPropagation();
        $this->testRealAdiantiHonorsCaseInsensitive();
        $this->testReadmePtBrExample9Section3RunsAsWritten();
        $this->testFieldMapperAliasOnCriteriaBridges();

        // Lote de correção #30-#32 (2026-10-07): startsWith/endsWith/contains como LIKE no TCriteria,
        // % e _ literais de like() escapados, prova com o Adianti real.
        $this->testStringAffixesTranslateToLikeInTCriteria();
        $this->testWildcardLiteralsAreEscapedInTCriteria();
        $this->testRealAdiantiEmitsEscapedLike();

        // Lote de correção #33 (2026-10-08): modificadores de RegexSpecification no TCriteria.
        $this->testRegexModifiersSurviveInTCriteria();
        $this->testRealAdiantiEmitsRegexFlags();

        // Forward 017 (v1.5.0), RN-07: in() vira TFilter('col', 'IN', [...]).
        $this->testRn07InTranslatesToTFilterIn();
        $this->testRealAdiantiEmitsInList();
    }

    /**
     * BUG-20261007-K7RM (#33): cleanRegexPattern() descartava os modificadores junto com os
     * delimitadores, então regex('/^abc/i') saía como REGEXP '^abc' (sensível, ou dependente do
     * SGBD: o REGEXP do MySQL é insensível por padrão). O TCriteria não conhece o dialeto, então o
     * modificador vai como flag inline do próprio padrão: (?i) para insensível, (?-i) para sensível;
     * u é aceito, qualquer outro é recusado como no visitor SQL (RN-08 item 3 do adendo 3E3F v001).
     */
    private function testRegexModifiersSurviveInTCriteria(): void
    {
        // 1. Reprodução: o modificador i sobrevive como flag inline
        $ci = Spec::toCriteria(Spec::property('code', Spec::regex('/^abc/i')));
        $this->assertEquals("(code REGEXP '(?i)^abc')", $ci->dump());
        $this->assertEquals("(code REGEXP :p)", preg_replace('/:par_\d+/', ':p', $ci->dump(true)));

        // 2. Sem i: sensibilidade explícita, porque o REGEXP do MySQL é insensível por padrão
        $this->assertEquals("(code REGEXP '(?-i)^Abc')", Spec::toCriteria(Spec::property('code', Spec::regex('/^Abc/')))->dump());

        // 3. u é aceito e não aparece no padrão; delimitador alternativo
        $this->assertEquals("(code REGEXP '(?i)^abc')", Spec::toCriteria(Spec::property('code', Spec::regex('/^abc/iu')))->dump());
        $this->assertEquals("(code REGEXP '(?-i)^Abc')", Spec::toCriteria(Spec::property('code', Spec::regex('/^Abc/u')))->dump());
        $this->assertEquals("(path REGEXP '(?i)^a/b')", Spec::toCriteria(Spec::property('path', Spec::regex('~^a/b~i')))->dump());

        // 4. Modificadores sem equivalente: recusa tipada que nomeia o modificador
        $this->assertThrows(NonTranslatableCriteriaException::class, fn() => Spec::toCriteria(Spec::property('code', Spec::regex('/^abc/m'))));
        try {
            Spec::toCriteria(Spec::property('code', Spec::regex('/^abc/is')));
            $this->assertTrue(false, 'modificador s deveria ser recusado');
        } catch (NonTranslatableCriteriaException $e) {
            $this->assertTrue(str_contains($e->getMessage(), '"s"'), 'a mensagem nomeia o modificador recusado: ' . $e->getMessage());
        }

        // 5. De Morgan: NOT REGEXP com a mesma flag
        $this->assertEquals("(code NOT REGEXP '(?i)^abc')", Spec::toCriteria(Spec::not(Spec::property('code', Spec::regex('/^abc/i'))))->dump());
        $this->assertEquals("(code NOT REGEXP '(?-i)^Abc')", Spec::toCriteria(Spec::not(Spec::property('code', Spec::regex('/^Abc/'))))->dump());

        // 6. Regressão: padrão legado sem delimitadores (só por construção direta; a fábrica o recusa) vai inalterado
        $legacy = new \Antevemus\ASpecification\Specifications\String\RegexSpecification('^[0-9]+$');
        $this->assertEquals("(cpf REGEXP '^[0-9]+$')", Spec::toCriteria(Spec::property('cpf', $legacy))->dump());
        $this->assertEquals("(cpf NOT REGEXP '^[0-9]+$')", Spec::toCriteria(Spec::not(Spec::property('cpf', $legacy)))->dump());

        // 7. Regressão: a guarda de valores continua inspecionando o padrão regex (bug #2); um padrão
        //    delimitado nunca começa por NOESC:/(SELECT, e com a flag inline o valor tampouco
        $this->assertThrows(UnsafeCriteriaValueException::class, fn() => Spec::toCriteria(Spec::property('code', Spec::regex('/{session.user_id}/'))));
        $this->assertThrows(UnsafeCriteriaValueException::class, fn() => Spec::toCriteria(Spec::property('code', new \Antevemus\ASpecification\Specifications\String\RegexSpecification('NOESC:x'))));
    }

    /**
     * BUG-20261007-K7RM: o TFilter real repassa a flag inline dentro do valor, em plain e prepared.
     */
    private function testRealAdiantiEmitsRegexFlags(): void
    {
        $out = $this->runRealAdiantiProbe();
        if ($out === null) {
            return;
        }

        $this->assertEquals("(code REGEXP '(?i)^abc')", $out['regexIgnoreCase']['dump']);
        $this->assertEquals("(code REGEXP :p)", preg_replace('/:par_\d+/', ':p', $out['regexIgnoreCase']['prepared']));
        $this->assertEquals("(code REGEXP '(?-i)^Abc')", $out['regexSensitive']['dump']);
        $this->assertEquals("(code NOT REGEXP '(?i)^abc')", $out['notRegexIgnoreCase']['dump']);
        $this->assertEquals("(cpf REGEXP '^[0-9]+$')", $out['regexLegacy']['dump']);
    }

    /**
     * BUG-20261007-3TVR (#31): startsWith()/endsWith()/contains() chegavam ao TCriteria como REGEXP
     * (inexistente em SQLite, Firebird, SQL Server e ANSI; a flag de caixa era perdida). Agora viram
     * LIKE portável com escape de curingas, como o visitor SQL desde o bug #29.
     */
    private function testStringAffixesTranslateToLikeInTCriteria(): void
    {
        // 1. Reprodução: as três fábricas viram LIKE com a posição certa do curinga
        $this->assertEquals("(name LIKE 'Ab%')", Spec::toCriteria(Spec::property('name', Spec::startsWith('Ab')))->dump());
        $this->assertEquals("(name LIKE '%Ab')", Spec::toCriteria(Spec::property('name', Spec::endsWith('Ab')))->dump());
        $this->assertEquals("(name LIKE '%Ab%')", Spec::toCriteria(Spec::property('name', Spec::contains('Ab')))->dump());

        // 2. Ignore-case sobrevive ao dump() do TCriteria (TCaseInsensitiveFilter, bug #9)
        $ci = Spec::toCriteria(Spec::property('name', Spec::startsWith('ab', false)));
        $this->assertEquals("(UPPER(name) LIKE UPPER('ab%'))", $ci->dump());
        $ci->setCaseInsensitive(false);
        $this->assertEquals("(UPPER(name) LIKE UPPER('ab%'))", $ci->dump(), 'o flag da folha não pode ser desligado pelo critério raiz');

        // 3. Escape de %, _ e ! do literal, com ESCAPE '!' só quando necessário; também em modo prepared
        $escaped = Spec::toCriteria(Spec::property('promo', Spec::contains('50%_off!')));
        $this->assertEquals("(promo LIKE '%50!%!_off!!%' ESCAPE '!')", $escaped->dump());
        $this->assertEquals("(promo LIKE :p ESCAPE '!')", preg_replace('/:par_\d+/', ':p', $escaped->dump(true)));
        $this->assertEquals("(path LIKE 'C:\\%' ESCAPE '!')", Spec::toCriteria(Spec::property('path', Spec::startsWith('C:\\')))->dump(), 'barra invertida pede ESCAPE explícito (MySQL)');
        $this->assertEquals("(UPPER(name) LIKE UPPER('%a!%%') ESCAPE '!')", Spec::toCriteria(Spec::property('name', Spec::contains('a%', false)))->dump());

        // 4. De Morgan: NOT LIKE, com e sem ESCAPE
        $this->assertEquals("(name NOT LIKE 'Ab%')", Spec::toCriteria(Spec::not(Spec::property('name', Spec::startsWith('Ab'))))->dump());
        $this->assertEquals("(promo NOT LIKE '%50!%%' ESCAPE '!')", Spec::toCriteria(Spec::not(Spec::property('promo', Spec::contains('50%'))))->dump());

        // 5. Composição com irmãs: a folha sensível continua sensível ao lado de uma insensível
        $mixed = Spec::property('name', Spec::startsWith('Ab'))->and(Spec::property('sigla', Spec::equalIgnoreCase('sp')));
        $this->assertEquals("(name LIKE 'Ab%' AND UPPER(sigla) LIKE UPPER('sp'))", Spec::toCriteria($mixed)->dump());

        // 6. Fronteira de confiança (bug #2): o literal é inspecionado, porque como LIKE ele chega ao TFilter sem o ^ do regex
        $this->assertThrows(UnsafeCriteriaValueException::class, fn() => Spec::toCriteria(Spec::property('name', Spec::startsWith('NOESC:x'))));
        $this->assertThrows(UnsafeCriteriaValueException::class, fn() => Spec::toCriteria(Spec::property('name', Spec::contains('{session.user_id}'))));

        // 7. Regressão: RegexSpecification genérica continua REGEXP sem delimitadores
        //    (desde o bug #33 a sensibilidade sai como flag inline: (?-i) para um padrão sem i)
        $this->assertEquals("(cpf REGEXP '(?-i)^[0-9]+$')", Spec::toCriteria(Spec::property('cpf', Spec::regex('/^[0-9]+$/')))->dump());
        $this->assertEquals("(cpf NOT REGEXP '(?-i)^[0-9]+$')", Spec::toCriteria(Spec::not(Spec::property('cpf', Spec::regex('/^[0-9]+$/'))))->dump());
    }

    /**
     * BUG-20261007-ZY6E (#32), lado TCriteria: % e _ literais de like()/wildcard() iam ao LIKE sem
     * escape e casavam mais do que em memória.
     */
    private function testWildcardLiteralsAreEscapedInTCriteria(): void
    {
        // Reprodução
        $this->assertEquals("(name LIKE '100!%%' ESCAPE '!')", Spec::toCriteria(Spec::property('name', Spec::wildcard('100%*')))->dump());
        $this->assertEquals("(name LIKE 'a!_b_' ESCAPE '!')", Spec::toCriteria(Spec::property('name', Spec::like('a_b?')))->dump());
        $this->assertEquals("(UPPER(name) LIKE UPPER('a!_b%') ESCAPE '!')", Spec::toCriteria(Spec::property('name', Spec::wildcardExpressionMatcherIgnoreCase('a_b*')))->dump());
        $this->assertEquals("(name NOT LIKE '100!%%' ESCAPE '!')", Spec::toCriteria(Spec::not(Spec::property('name', Spec::wildcard('100%*'))))->dump());
        $this->assertEquals("(name LIKE :p ESCAPE '!')", preg_replace('/:par_\d+/', ':p', Spec::toCriteria(Spec::property('name', Spec::wildcard('100%*')))->dump(true)));

        // Regressão: sem caracteres especiais do LIKE, saída idêntica à anterior (sem ESCAPE)
        $this->assertEquals("(cidade LIKE 'São%')", Spec::toCriteria(Spec::property('cidade', Spec::wildcard('São*')))->dump());
        $this->assertEquals("(nome LIKE 'J%hn_')", Spec::toCriteria(Spec::property('nome', Spec::like('J*hn?')))->dump());
        $this->assertEquals("(UPPER(cidade) LIKE UPPER('são%'))", Spec::toCriteria(Spec::property('cidade', Spec::wildcardExpressionMatcherIgnoreCase('são*')))->dump());
    }

    /**
     * BUG-20261007-3TVR / ZY6E: a cláusula ESCAPE é acrescentada por um TFilter derivado (o TFilter
     * real não tem lugar para ela). Prova com as classes reais do Adianti em processo filho, quando
     * disponíveis ao lado do repositório; sem elas, avisa e segue (RN-07).
     */
    private function testRealAdiantiEmitsEscapedLike(): void
    {
        $out = $this->runRealAdiantiProbe();
        if ($out === null) {
            return;
        }

        $this->assertEquals("(name LIKE 'Ab%')", $out['startsWith']['dump']);
        $this->assertEquals("(UPPER(name) LIKE UPPER('ab%'))", $out['startsWithIgnoreCase']['dump']);
        $this->assertEquals("(promo LIKE '%50!%!_off!!%' ESCAPE '!')", $out['containsEscaped']['dump']);
        $this->assertEquals("(promo LIKE :p ESCAPE '!')", preg_replace('/:par_\d+/', ':p', $out['containsEscaped']['prepared']));
        $this->assertEquals("(name NOT LIKE 'Ab%')", $out['notStartsWith']['dump']);
        $this->assertEquals("(name LIKE '100!%%' ESCAPE '!')", $out['wildcardEscaped']['dump']);
        $this->assertEquals("(UPPER(name) LIKE UPPER('a!_b%') ESCAPE '!')", $out['wildcardEscapedIgnoreCase']['dump']);
        $this->assertEquals("(name LIKE 'Ab%' AND UPPER(sigla) LIKE UPPER('sp'))", $out['affixMixedAnd']['dump']);
    }

    /**
     * Roda tests/Support/real_adianti_probe.php com as classes reais do Adianti e devolve o JSON
     * decodificado, ou null (com aviso) quando o Adianti não está ao lado do repositório.
     *
     * @return array<string, array{dump: string, prepared: string}>|null
     */
    private function runRealAdiantiProbe(): ?array
    {
        $adianti = dirname(__DIR__, 3) . '/Antevemus.AflowEngine/lib/adianti/database';
        if (!is_file($adianti . '/TCriteria.php')) {
            fwrite(STDOUT, "    [AVISO] Adianti real não encontrado em {$adianti}; verificação com classes reais pulada.\n");
            return null;
        }

        $probe = dirname(__DIR__) . '/Support/real_adianti_probe.php';
        $cmd = sprintf(
            '%s -d xdebug.mode=off %s %s 2>/dev/null',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($probe),
            escapeshellarg($adianti)
        );
        $json = (string) shell_exec($cmd);
        $out = json_decode($json, true);
        $this->assertTrue(is_array($out), 'Sonda com o Adianti real não devolveu JSON: ' . $json);

        return $out;
    }

    /**
     * Forward 014 (README Promises I), R12: transcrição fiel da seção 3 do exemplo 9 do README pt-BR
     * (`withFieldMapping()`, `offset()`, `toCriteria()`), mais a seção 4 (`$spec->toCriteria()`).
     * Antes: "Call to undefined method TCriteriaBuilder::withFieldMapping()".
     */
    private function testReadmePtBrExample9Section3RunsAsWritten(): void
    {
        // 1. Especificação de domínio com conjunção, disjunção e negação
        $spec = Spec::property('ativo', Spec::equalTo(true))
            ->and(
                Spec::property('salario', Spec::greaterThan(5000))
                    ->or(Spec::property('cidade', Spec::wildcard('São*')))
            )
            ->and(Spec::property('status', Spec::not(Spec::equalTo('CANCELADO'))));

        // 3. Compilação fluente com paginação e ordenação encadeadas
        $criteriaFluent = Spec::criteriaBuilder($spec)
            ->withFieldMapping(['salario' => 'vl_salario'])
            ->orderBy('vl_salario', 'desc')
            ->limit(20)
            ->offset(40)
            ->groupBy('departamento_id')
            ->toCriteria();

        $this->assertInstanceOf(TCriteria::class, $criteriaFluent);
        $this->assertEquals("((ativo = TRUE AND (vl_salario > 5000 OR cidade LIKE 'São%')) AND status <> 'CANCELADO')", $criteriaFluent->dump());
        $this->assertEquals('vl_salario', $criteriaFluent->getProperty('order'));
        $this->assertEquals('desc', $criteriaFluent->getProperty('direction'));
        $this->assertEquals(20, $criteriaFluent->getProperty('limit'));
        $this->assertEquals(40, $criteriaFluent->getProperty('offset'));
        $this->assertEquals('departamento_id', $criteriaFluent->getProperty('group'));

        // 4. Invocação direta a partir de qualquer instância de ISpecification
        $criteriaFromInstance = $spec->toCriteria();
        $this->assertInstanceOf(TCriteria::class, $criteriaFromInstance);
        $this->assertEquals("((ativo = TRUE AND (salario > 5000 OR cidade LIKE 'São%')) AND status <> 'CANCELADO')", $criteriaFromInstance->dump());

        // withFieldMapping() substitui o mapeamento do construtor e vale no build()
        $replaced = Spec::criteriaBuilder($spec, ['salario' => 'x_errado'])
            ->withFieldMapping(['salario' => 'vl_salario', 'status' => 'tp_status'])
            ->toCriteria();
        $this->assertEquals("((ativo = TRUE AND (vl_salario > 5000 OR cidade LIKE 'São%')) AND tp_status <> 'CANCELADO')", $replaced->dump());
        $cleared = Spec::criteriaBuilder($spec, ['salario' => 'x_errado'])->withFieldMapping(null)->build();
        $this->assertEquals("((ativo = TRUE AND (salario > 5000 OR cidade LIKE 'São%')) AND status <> 'CANCELADO')", $cleared->dump());

        // offset() antes e depois de limit(): nenhum apaga o outro; limit(l, o) continua valendo
        $a = Spec::criteriaBuilder($spec)->offset(40)->limit(20)->build();
        $this->assertEquals(20, $a->getProperty('limit'));
        $this->assertEquals(40, $a->getProperty('offset'));
        $b = Spec::criteriaBuilder($spec)->limit(20)->offset(40)->build();
        $this->assertEquals(20, $b->getProperty('limit'));
        $this->assertEquals(40, $b->getProperty('offset'));
        $c = Spec::criteriaBuilder($spec)->limit(20, 5)->offset(40)->build();
        $this->assertEquals(40, $c->getProperty('offset'));
        $d = Spec::criteriaBuilder($spec)->offset(40)->limit(20, 5)->build();
        $this->assertEquals(5, $d->getProperty('offset'));

        // toCriteria() é build()
        $viaBuild = Spec::criteriaBuilder($spec)->limit(3)->build();
        $viaAlias = Spec::criteriaBuilder($spec)->limit(3)->toCriteria();
        $this->assertEquals($viaBuild->dump(), $viaAlias->dump());
        $this->assertEquals($viaBuild->getProperty('limit'), $viaAlias->getProperty('limit'));
    }

    /**
     * Forward 014, R3 (RN-04) nas pontes TCriteria: `fieldMapper:` em Spec::toCriteria(),
     * Spec::criteriaBuilder() e no método de instância toCriteria().
     */
    private function testFieldMapperAliasOnCriteriaBridges(): void
    {
        $spec = Spec::property('ativo', Spec::equalTo(true));
        $map = ['ativo' => 'fl_ativo'];

        $this->assertEquals("(fl_ativo = TRUE)", Spec::toCriteria($spec, fieldMapper: $map)->dump());
        $this->assertEquals("(fl_ativo = TRUE)", Spec::toCriteria($spec, fieldMap: $map, fieldMapper: $map)->dump());
        $this->assertEquals("(fl_ativo = TRUE)", Spec::criteriaBuilder($spec, fieldMapper: $map)->toCriteria()->dump());
        $this->assertEquals("(fl_ativo = TRUE)", $spec->toCriteria(fieldMapper: $map)->dump());
        $this->assertEquals(7, Spec::toCriteria($spec, properties: ['limit' => 7], fieldMapper: $map)->getProperty('limit'));

        $this->assertThrows(\InvalidArgumentException::class, function () use ($spec, $map) {
            Spec::toCriteria($spec, fieldMap: $map, fieldMapper: ['ativo' => 'outra']);
        });
        $this->assertThrows(\InvalidArgumentException::class, function () use ($spec, $map) {
            Spec::criteriaBuilder($spec, $map, ['ativo' => 'outra']);
        });
        $this->assertThrows(\InvalidArgumentException::class, function () use ($spec, $map) {
            $spec->toCriteria($map, [], ['ativo' => 'outra']);
        });
    }

    /**
     * BUG-20261007-M646: o TCriteria real propaga o próprio flag (false por padrão) a cada
     * filho ao fazer dump(), sobrescrevendo o setCaseInsensitive(true) da folha. O stub agora
     * espelha isso. Reprodução: folhas ignore-case saíam sem UPPER(). Regressão: irmãos
     * sensíveis não são contaminados, em qualquer profundidade, e um critério raiz com o flag
     * explicitamente desligado não reverte a folha.
     */
    private function testCaseInsensitiveSurvivesCriteriaPropagation(): void
    {
        // Folha isolada, com o critério raiz nascido FALSE (como no Adianti real)
        $c1 = TCriteriaBuilder::fromSpecification(Spec::property('sigla', Spec::equalIgnoreCase('sp')));
        $this->assertEquals("(UPPER(sigla) LIKE UPPER('sp'))", $c1->dump());
        $this->assertEquals("(UPPER(sigla) LIKE UPPER(:p))", preg_replace('/:par_\d+/', ':p', $c1->dump(true)));

        // Critério raiz com o flag explicitamente desligado: a folha continua insensível
        $c1->setCaseInsensitive(false);
        $this->assertEquals("(UPPER(sigla) LIKE UPPER('sp'))", $c1->dump());

        // Irmão sensível no mesmo AND não ganha UPPER()
        $mixed = Spec::property('nome', Spec::wildcard('Jo*'))
            ->and(Spec::property('sigla', Spec::equalIgnoreCase('sp')));
        $c2 = TCriteriaBuilder::fromSpecification($mixed);
        $this->assertEquals("(nome LIKE 'Jo%' AND UPPER(sigla) LIKE UPPER('sp'))", $c2->dump());

        // Aninhamento: OR > AND > folha insensível; a folha sensível do ramo continua sensível
        $nested = Spec::property('uf', Spec::equalTo('RJ'))
            ->or(Spec::property('nome', Spec::wildcard('A*'))
            ->and(Spec::property('cidade', Spec::wildcardExpressionMatcherIgnoreCase('são*'))));
        $c3 = TCriteriaBuilder::fromSpecification($nested);
        $this->assertEquals("(uf = 'RJ' OR (nome LIKE 'A%' AND UPPER(cidade) LIKE UPPER('são%')))", $c3->dump());

        // De Morgan: NOT de folha insensível vira NOT LIKE, ainda insensível
        $c4 = TCriteriaBuilder::fromSpecification(Spec::not(Spec::property('sigla', Spec::equalIgnoreCase('sp'))));
        $this->assertEquals("(UPPER(sigla) NOT LIKE UPPER('sp'))", $c4->dump());

        // Um critério raiz insensível continua valendo para folhas sensíveis (comportamento do Adianti, inalterado)
        $c5 = TCriteriaBuilder::fromSpecification(Spec::property('nome', Spec::wildcard('Jo*')));
        $c5->setCaseInsensitive(true);
        $this->assertEquals("(UPPER(nome) LIKE UPPER('Jo%'))", $c5->dump());
    }

    /**
     * BUG-20261007-M646: o stub mentiu duas vezes (KJ36 e M646). Quando o Adianti real estiver
     * disponível ao lado deste repositório, a tradução é verificada com as classes reais num
     * processo filho (as classes reais e os stubs não podem coexistir no mesmo processo).
     * Sem o Adianti real, avisa e segue: a suíte continua autossuficiente (RN-07).
     */
    private function testRealAdiantiHonorsCaseInsensitive(): void
    {
        $out = $this->runRealAdiantiProbe();
        if ($out === null) {
            return;
        }

        $this->assertEquals("(UPPER(sigla) LIKE UPPER('sp'))", $out['equalIgnoreCase']['dump']);
        $this->assertEquals("(UPPER(cidade) LIKE UPPER('são%'))", $out['wildcardIgnoreCase']['dump']);
        $this->assertEquals("(UPPER(sigla) NOT LIKE UPPER('sp'))", $out['notEqualIgnoreCase']['dump']);
        $this->assertEquals("(nome LIKE 'Jo%' AND UPPER(sigla) LIKE UPPER('sp'))", $out['mixedAnd']['dump']);
        $this->assertEquals("(uf = 'RJ' OR (nome LIKE 'A%' AND UPPER(sigla) LIKE UPPER('sp')))", $out['nestedOr']['dump']);

        // Modo prepared: UPPER() envolve o placeholder, o literal segue como parâmetro
        $prepared = preg_replace('/:par_\d+/', ':p', $out['mixedAnd']['prepared']);
        $this->assertEquals("(nome LIKE :p AND UPPER(sigla) LIKE UPPER(:p))", $prepared);
    }

    /**
     * BUG-20261007-KJ36: o TFilter real do Adianti trata valores iniciados por "(SELECT",
     * contendo "{session." ou iniciados por "NOESC:" como SQL cru, mesmo em modo prepared.
     * Reprodução: o visitor repassava o valor sem neutralizar.
     * Regressão: valores legítimos parecidos continuam aceitos.
     */
    private function testMagicAdiantiPrefixesAreRejected(): void
    {
        $unsafe = [
            "NOESC:'' OR 1=1",
            "(SELECT 1) OR 1=1",
            "(select max(id) from t)",
            "  (Select 1)",
            "x {session.user_id} y",
        ];

        foreach ($unsafe as $value) {
            $this->assertThrows(
                UnsafeCriteriaValueException::class,
                fn() => TCriteriaBuilder::fromSpecification(Spec::property('name', Spec::equal($value))),
                "Valor com prefixo mágico deveria ser recusado: {$value}"
            );
        }

        // Todas as folhas com valor passam pela mesma guarda
        $this->assertThrows(UnsafeCriteriaValueException::class, fn() => TCriteriaBuilder::fromSpecification(Spec::property('name', Spec::notEqual('NOESC:1'))));
        $this->assertThrows(UnsafeCriteriaValueException::class, fn() => TCriteriaBuilder::fromSpecification(Spec::property('name', Spec::wildcard('NOESC:*'))));
        $this->assertThrows(UnsafeCriteriaValueException::class, fn() => TCriteriaBuilder::fromSpecification(Spec::property('name', Spec::equalIgnoreCase('(SELECT 1)'))));
        $this->assertThrows(UnsafeCriteriaValueException::class, fn() => TCriteriaBuilder::fromSpecification(Spec::property('name', Spec::greaterThan('NOESC:0'))));
        $this->assertThrows(UnsafeCriteriaValueException::class, fn() => TCriteriaBuilder::fromSpecification(Spec::property('name', Spec::in('ok', 'NOESC:1'))));
        $this->assertThrows(UnsafeCriteriaValueException::class, fn() => TCriteriaBuilder::fromSpecification(Spec::property('name', Spec::not(Spec::equal('NOESC:1')))));

        // Regressão: valores legítimos continuam aceitos e citados
        $this->assertEquals("(name = 'reselect')", TCriteriaBuilder::fromSpecification(Spec::property('name', Spec::equal('reselect')))->dump());
        $this->assertEquals("(name = 'sessionless')", TCriteriaBuilder::fromSpecification(Spec::property('name', Spec::equal('sessionless')))->dump());
        $this->assertEquals("(name = 'noesc')", TCriteriaBuilder::fromSpecification(Spec::property('name', Spec::equal('noesc')))->dump());
        $this->assertEquals("(name = 'x (select) y')", TCriteriaBuilder::fromSpecification(Spec::property('name', Spec::equal('x (select) y')))->dump());

        // O stub reproduz o comportamento real do TFilter: um valor NOESC construído DIRETAMENTE no TFilter sai cru.
        // (Prova de que a suíte agora enxerga o risco que o visitor passou a bloquear.)
        $raw = new TFilter('name', '=', "NOESC:'' OR 1=1");
        $this->assertEquals("name = '' OR 1=1", $raw->dump());
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

        // Regex (sensível a caixa: flag inline (?-i) desde o bug #33, porque o REGEXP do MySQL é insensível por padrão)
        $spec4 = Spec::property('cpf', Spec::regex('/^[0-9]+$/'));
        $c4 = TCriteriaBuilder::fromSpecification($spec4);
        $this->assertEquals("(cpf REGEXP '(?-i)^[0-9]+$')", $c4->dump());
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

    /**
     * RN-07 (forward 017, v1.5.0): Spec::property('status', Spec::in('A', 'B'))->toCriteria() produz
     * TFilter('status', 'IN', ['A', 'B']); antes, um TCriteria com dois TFilter '=' ligados por OR.
     */
    private function testRn07InTranslatesToTFilterIn(): void
    {
        // Cenário Gherkin: o filtro em si
        $criteria = Spec::property('status', Spec::in('A', 'B'))->toCriteria();
        $expressions = $criteria->getExpressions();
        $this->assertCount(1, $expressions);
        $filter = $expressions[0];
        $this->assertInstanceOf(TFilter::class, $filter);
        $this->assertEquals('status', $filter->getVariable());
        $this->assertEquals('IN', $filter->getOperator());
        $this->assertEquals(['A', 'B'], $filter->getValue());
        $this->assertEquals("(status IN ('A','B'))", $criteria->dump());

        // Negação, null no conjunto, vazio
        $this->assertEquals("(status NOT IN ('A','B'))", Spec::toCriteria(Spec::property('status', Spec::notIn('A', 'B')))->dump());
        $this->assertEquals("(status NOT IN ('A','B'))", Spec::toCriteria(Spec::not(Spec::property('status', Spec::in('A', 'B'))))->dump());
        $this->assertEquals("(status IN ('A') OR status IS NULL)", Spec::toCriteria(Spec::property('status', Spec::in('A', null)))->dump());
        $this->assertEquals("(status NOT IN ('A') AND status IS NOT NULL)", Spec::toCriteria(Spec::not(Spec::property('status', Spec::in('A', null))))->dump());
        $this->assertEquals("(1 = 0)", Spec::toCriteria(Spec::property('status', Spec::in()))->dump());
        $this->assertEquals("(1 = 1)", Spec::toCriteria(Spec::property('status', Spec::notIn()))->dump());
        $this->assertEquals("(n IN (1,2,3))", Spec::toCriteria(Spec::property('n', Spec::in(1, 2, 3)))->dump());

        // Valores do conjunto passam pela mesma barreira de passthrough do Adianti (bug KJ36)
        $this->assertThrows(UnsafeCriteriaValueException::class, fn() => Spec::toCriteria(Spec::property('name', Spec::in('ok', '(SELECT 1)'))));
        $this->assertThrows(UnsafeCriteriaValueException::class, fn() => Spec::toCriteria(Spec::property('name', Spec::notIn('ok', '{session.user}'))));
    }

    /**
     * RN-07 com as classes REAIS do Adianti (sonda em processo filho), quando disponíveis.
     */
    private function testRealAdiantiEmitsInList(): void
    {
        $out = $this->runRealAdiantiProbe();
        if ($out === null) {
            return;
        }

        $this->assertEquals("(status IN ('A','B'))", $out['inList']['dump']);
        $this->assertEquals("(status NOT IN ('A','B'))", $out['notInList']['dump']);
        $this->assertEquals("(status IN ('A') OR status IS NULL)", $out['inWithNull']['dump']);
        $this->assertEquals("(n IN (1,2,3))", $out['inIntegers']['dump']);
        $this->assertTrue(str_contains($out['inList']['prepared'], 'status IN ('), 'modo prepared: ' . $out['inList']['prepared']);
    }
}
