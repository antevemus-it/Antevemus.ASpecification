<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\Comparison\EqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\GreaterThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\LessThanSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotEqualSpecification;
use Antevemus\ASpecification\Specifications\Comparison\NotNullSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Specifications\String\EqualIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\RegexSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardSpecification;
use Antevemus\ASpecification\Sql\Dialects\SqlDialectFactory;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;
use Antevemus\ASpecification\Sql\Exceptions\UnsupportedSqlOperationException;
use Antevemus\ASpecification\Sql\Exceptions\UnsafeIdentifierException;
use Antevemus\ASpecification\Sql\FieldMapper;
use Antevemus\ASpecification\Sql\SqlDialect;
use Antevemus\ASpecification\Sql\SqlQueryVisitor;
use Antevemus\ASpecification\Sql\SqlWhereClause;
use Antevemus\ASpecification\Tests\TestCase;
use stdClass;

/**
 * Module12_SqlVisitorAndDialectsTest - Suíte de Testes para o Tradutor Multi-SGBD SQL
 *
 * Valida a conversão de árvores de especificações em cláusulas WHERE parametrizadas para
 * todos os dialetos do ecossistema: sqlsrv, oracle, oci, mysql, mssql, ibase, firebird, fbird, dblib, pgsql e sqlite.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class Module12_SqlVisitorAndDialectsTest extends TestCase
{
    public function run(): void
    {
        $this->testDialectResolutionForAllSgbds();
        $this->testIdentifierEscapingAcrossDialects();
        $this->testBooleanFormattingAcrossDialects();
        $this->testBasicComparisonOperations();
        $this->testNullHandling();
        $this->testLogicalCompositionAndOrNot();
        $this->testTextAndWildcardOperations();
        $this->testRegexSupportAndExceptions();
        $this->testFieldMapper();
        $this->testFluentFacadeAndMethodToSql();
        $this->testNonTranslatableException();
        $this->testSqlWhereClauseComposition();
        $this->testIdentifierInjectionIsRejected();
        $this->testReadmeExample8RunsAsWritten();
        $this->testFieldMapperAliasResolution();
    }

    /**
     * Forward 014 (README Promises I), R2 + R3: transcrição fiel do exemplo 8 do README
     * (Spec::toSql(fieldMapper:), getSql(), getBindings()), com as saídas que o README imprime.
     * Antes: "Unknown named parameter $fieldMapper"; corrigido isso, getSql()/getBindings() inexistentes.
     */
    private function testReadmeExample8RunsAsWritten(): void
    {
        // 1. Build pure domain specification
        $spec = Spec::property('active', Spec::equalTo(true))
            ->and(
                Spec::property('salary', Spec::greaterThan(5000))
                    ->or(Spec::property('city', Spec::wildcard('New*')))
            );

        // 2. Compile to PostgreSQL with domain-to-column mapping
        $whereClause = Spec::toSql(
            specification: $spec,
            dialect: SqlDialect::POSTGRESQL,
            fieldMapper: [
                'active' => 'is_active',
                'salary' => 'val_salary',
                'city'   => 'txt_city'
            ]
        );

        $this->assertInstanceOf(ISqlWhereClause::class, $whereClause);
        $this->assertEquals('("is_active" = TRUE AND ("val_salary" > :p1 OR "txt_city" LIKE :p2))', $whereClause->getSql());
        $this->assertEquals([':p1' => 5000, ':p2' => 'New%'], $whereClause->getBindings());

        // Os aliases nunca divergem dos acessores canônicos
        $this->assertEquals($whereClause->toSql(), $whereClause->getSql());
        $this->assertEquals($whereClause->getParameters(), $whereClause->getBindings());

        // 3. First-class support for 12 database drivers across 7 SQL dialects
        $whereSqlServer = Spec::toSql($spec, SqlDialect::SQLSRV);
        $this->assertEquals('([active] = 1 AND ([salary] > :p1 OR [city] LIKE :p2))', $whereSqlServer->getSql());

        $whereMySql = Spec::toSql($spec, SqlDialect::MYSQL);
        $this->assertEquals('(`active` = 1 AND (`salary` > :p1 OR BINARY `city` LIKE :p2))', $whereMySql->getSql());
        $this->assertEquals([':p1' => 5000, ':p2' => 'New%'], $whereMySql->getBindings());

        // Cláusula vazia também responde aos aliases
        $empty = SqlWhereClause::empty();
        $this->assertEquals('', $empty->getSql());
        $this->assertEquals([], $empty->getBindings());
    }

    /**
     * Forward 014, R3 (RN-04): `fieldMapper:` é alias de `fieldMap:` na facade e nos métodos de
     * instância; os dois juntos e iguais passam, os dois juntos e diferentes lançam.
     */
    private function testFieldMapperAliasResolution(): void
    {
        $spec = Spec::property('status', Spec::equalTo('ACTIVE'));
        $map  = ['status' => 'st_status'];
        $other = ['status' => 'tp_status'];

        // Facade: só fieldMap, só fieldMapper, os dois iguais
        $this->assertEquals('"st_status" = :p1', Spec::toSql($spec, 'pgsql', fieldMap: $map)->getSql());
        $this->assertEquals('"st_status" = :p1', Spec::toSql($spec, 'pgsql', fieldMapper: $map)->getSql());
        $this->assertEquals('"st_status" = :p1', Spec::toSql($spec, 'pgsql', fieldMap: $map, fieldMapper: $map)->getSql());

        // Instância: mesma regra
        $this->assertEquals('`st_status` = :p1', $spec->toSql('mysql', fieldMapper: $map)->getSql());
        $this->assertEquals('`st_status` = :p1', $spec->toSql('mysql', $map, $map)->getSql());

        // Mapper objeto: identidade
        $mapper = new FieldMapper($map, 'c');
        $this->assertEquals('"c"."st_status" = :p1', Spec::toSql($spec, 'pgsql', fieldMap: $mapper, fieldMapper: $mapper)->getSql());

        // Os dois diferentes: ambíguo
        $this->assertThrows(\InvalidArgumentException::class, function () use ($spec, $map, $other) {
            Spec::toSql($spec, 'pgsql', fieldMap: $map, fieldMapper: $other);
        });
        $this->assertThrows(\InvalidArgumentException::class, function () use ($spec, $map, $other) {
            $spec->toSql('pgsql', $map, $other);
        });
        $this->assertThrows(\InvalidArgumentException::class, function () use ($spec, $map) {
            Spec::toSql($spec, 'pgsql', fieldMap: $map, fieldMapper: new FieldMapper($map));
        });

        // Resolver exposto: null + alias devolve o alias
        $this->assertEquals($map, Spec::resolveFieldMap(null, $map));
        $this->assertTrue(Spec::resolveFieldMap(null, null) === null);
    }

    /**
     * BUG-20261007-SJVE: identificador de coluna não validado entrava cru no WHERE.
     * Reprodução: nomes com parênteses/colchetes/crases eram preservados sem quoting.
     * Regressão: identificadores simples, qualificados e funções simples continuam aceitos.
     */
    private function testIdentifierInjectionIsRejected(): void
    {
        $unsafe = [
            ['pgsql',  'id) or 1=1 or (id'],
            ['sqlsrv', 'id] or 1=1 or (id'],
            ['mysql',  'id` or 1=1 or (id'],
            ['ansi',   'id"; drop table t; --('],
            ['oracle', "LOWER(name) = 'x') or (1=1"],
        ];
        foreach ($unsafe as [$dialect, $name]) {
            $this->assertThrows(
                UnsafeIdentifierException::class,
                fn() => Spec::toSql(Spec::property($name, Spec::equalTo(1)), $dialect),
                "Identificador inseguro deveria ser recusado em {$dialect}"
            );
        }

        // Closure do FieldMapper devolvendo expressão perigosa também é recusada
        $this->assertThrows(
            UnsafeIdentifierException::class,
            fn() => Spec::toSql(Spec::property('id', Spec::equalTo(1)), 'mysql', fn(string $p) => $p . '` or 1=1 or `x')
        );

        // Regressão: identificadores legítimos continuam quoted como antes
        $this->assertEquals('"c"."status" = :p1', Spec::toSql(Spec::property('c.status', Spec::equalTo(1)), 'pgsql')->toSql());
        $this->assertEquals('[c].[status] = :p1', Spec::toSql(Spec::property('c.status', Spec::equalTo(1)), 'sqlsrv')->toSql());
        $this->assertEquals('`total_value` > :p1', Spec::toSql(Spec::property('totalValue', Spec::greaterThan(10)), 'mysql')->toSql());

        // Regressão: expressão de função simples declarada no mapper continua aceita (sem quoting, como antes)
        $this->assertEquals(
            'LOWER(name) = :p1',
            Spec::toSql(Spec::property('name', Spec::equalTo('x')), 'pgsql', ['name' => 'LOWER(name)'])->toSql()
        );
        $this->assertEquals(
            'COALESCE(c.nick, c.name) = :p1',
            Spec::toSql(Spec::property('name', Spec::equalTo('x')), 'pgsql', ['name' => 'COALESCE(c.nick, c.name)'])->toSql()
        );
    }

    private function testDialectResolutionForAllSgbds(): void
    {
        $dialects = [
            'sqlsrv' => 'sqlsrv',
            'mssql' => 'mssql',
            'dblib' => 'dblib',
            'oracle' => 'oracle',
            'oci' => 'oci',
            'mysql' => 'mysql',
            'mariadb' => 'mysql',
            'pgsql' => 'pgsql',
            'postgres' => 'pgsql',
            'postgresql' => 'pgsql',
            'firebird' => 'firebird',
            'fbird' => 'fbird',
            'ibase' => 'ibase',
            'sqlite' => 'sqlite',
            'ansi' => 'ansi',
        ];

        foreach ($dialects as $driver => $expectedFamily) {
            $dialectObj = SqlDialectFactory::create($driver);
            $this->assertEquals($expectedFamily, $dialectObj->getFamily());
        }
    }

    private function testIdentifierEscapingAcrossDialects(): void
    {
        // MySQL usa backticks
        $mysql = SqlDialectFactory::create('mysql');
        $this->assertEquals('`status`', $mysql->escapeIdentifier('status'));
        $this->assertEquals('`c`.`status`', $mysql->escapeIdentifier('c.status'));

        // SQL Server (sqlsrv, mssql, dblib) usa colchetes
        foreach (['sqlsrv', 'mssql', 'dblib'] as $d) {
            $sqlsrv = SqlDialectFactory::create($d);
            $this->assertEquals('[status]', $sqlsrv->escapeIdentifier('status'));
            $this->assertEquals('[c].[status]', $sqlsrv->escapeIdentifier('c.status'));
        }

        // PostgreSQL, Oracle, Firebird, SQLite e ANSI usam aspas duplas
        foreach (['pgsql', 'oracle', 'oci', 'firebird', 'fbird', 'ibase', 'sqlite', 'ansi'] as $d) {
            $obj = SqlDialectFactory::create($d);
            $this->assertEquals('"status"', $obj->escapeIdentifier('status'));
            $this->assertEquals('"c"."status"', $obj->escapeIdentifier('c.status'));
        }
    }

    private function testBooleanFormattingAcrossDialects(): void
    {
        // PostgreSQL suporta TRUE / FALSE nativos
        $pgsql = SqlDialectFactory::create('pgsql');
        $this->assertEquals('TRUE', $pgsql->formatBoolean(true));
        $this->assertEquals('FALSE', $pgsql->formatBoolean(false));

        // Todos os outros usam 1 / 0
        foreach (['mysql', 'sqlsrv', 'mssql', 'dblib', 'oracle', 'oci', 'firebird', 'fbird', 'ibase', 'sqlite'] as $d) {
            $obj = SqlDialectFactory::create($d);
            $this->assertEquals('1', $obj->formatBoolean(true));
            $this->assertEquals('0', $obj->formatBoolean(false));
        }
    }

    private function testBasicComparisonOperations(): void
    {
        $base = Spec::specify(stdClass::class);

        // 1. Igualdade
        $specEq = new PropertySpecification($base, 'age', new EqualSpecification(25));
        $clausePg = (new SqlQueryVisitor('pgsql'))->translate($specEq);
        $this->assertEquals('"age" = :p1', $clausePg->toSql());
        $this->assertEquals([':p1' => 25], $clausePg->getParameters());

        // 2. Maior Que (MySQL)
        $specGt = new PropertySpecification($base, 'salary', new GreaterThanSpecification(5000.0));
        $clauseMy = (new SqlQueryVisitor('mysql'))->translate($specGt);
        $this->assertEquals('`salary` > :p1', $clauseMy->toSql());
        $this->assertEquals([':p1' => 5000.0], $clauseMy->getParameters());

        // 3. Menor Que (SQL Server)
        $specLt = new PropertySpecification($base, 'score', new LessThanSpecification(100));
        $clauseMs = (new SqlQueryVisitor('sqlsrv'))->translate($specLt);
        $this->assertEquals('[score] < :p1', $clauseMs->toSql());

        // 4. Diferente (Oracle)
        $specNeq = new PropertySpecification($base, 'status', new NotEqualSpecification('CANCELLED'));
        $clauseOra = (new SqlQueryVisitor('oracle'))->translate($specNeq);
        $this->assertEquals('"status" <> :p1', $clauseOra->toSql());
        $this->assertEquals([':p1' => 'CANCELLED'], $clauseOra->getParameters());

        // 5. Booleano no PostgreSQL vs SQL Server
        $specBool = new PropertySpecification($base, 'active', new EqualSpecification(true));
        $clausePgBool = (new SqlQueryVisitor('pgsql'))->translate($specBool);
        $this->assertEquals('"active" = TRUE', $clausePgBool->toSql());

        $clauseMsBool = (new SqlQueryVisitor('sqlsrv'))->translate($specBool);
        $this->assertEquals('[active] = 1', $clauseMsBool->toSql());
    }

    private function testNullHandling(): void
    {
        $base = Spec::specify(stdClass::class);

        // IS NULL
        $specNull = new PropertySpecification($base, 'deletedAt', new EqualSpecification(null));
        $clauseNull = (new SqlQueryVisitor('pgsql'))->translate($specNull);
        $this->assertEquals('"deleted_at" IS NULL', $clauseNull->toSql());
        $this->assertTrue(empty($clauseNull->getParameters()));

        // IS NOT NULL via NotEqualSpecification(null)
        $specNotNull1 = new PropertySpecification($base, 'activatedAt', new NotEqualSpecification(null));
        $clauseNotNull1 = (new SqlQueryVisitor('mysql'))->translate($specNotNull1);
        $this->assertEquals('`activated_at` IS NOT NULL', $clauseNotNull1->toSql());

        // IS NOT NULL via NotNullSpecification
        $specNotNull2 = new PropertySpecification($base, 'cpf', new NotNullSpecification());
        $clauseNotNull2 = (new SqlQueryVisitor('sqlsrv'))->translate($specNotNull2);
        $this->assertEquals('[cpf] IS NOT NULL', $clauseNotNull2->toSql());
    }

    private function testLogicalCompositionAndOrNot(): void
    {
        $base = Spec::specify(stdClass::class);

        $spec = (new PropertySpecification($base, 'age', new GreaterThanSpecification(18)))
            ->and(new PropertySpecification($base, 'status', new EqualSpecification('ACTIVE')))
            ->or((new PropertySpecification($base, 'isVip', new EqualSpecification(true)))->not());

        // PostgreSQL
        $clausePg = (new SqlQueryVisitor('pgsql'))->translate($spec);
        $this->assertEquals('(("age" > :p1 AND "status" = :p2) OR NOT ("is_vip" = TRUE))', $clausePg->toSql());
        $this->assertEquals([':p1' => 18, ':p2' => 'ACTIVE'], $clausePg->getParameters());

        // SQL Server (mssql / dblib)
        $clauseSqlsrv = (new SqlQueryVisitor('sqlsrv'))->translate($spec);
        $this->assertEquals('(([age] > :p1 AND [status] = :p2) OR NOT ([is_vip] = 1))', $clauseSqlsrv->toSql());

        // Oracle (oci)
        $clauseOci = (new SqlQueryVisitor('oci'))->translate($spec);
        $this->assertEquals('(("age" > :p1 AND "status" = :p2) OR NOT ("is_vip" = 1))', $clauseOci->toSql());
    }

    private function testTextAndWildcardOperations(): void
    {
        $base = Spec::specify(stdClass::class);

        // Wildcard: 'J*hn' -> 'J%hn'
        $specWild = new PropertySpecification($base, 'name', new WildcardSpecification('J*hn'));
        $clause = (new SqlQueryVisitor('pgsql'))->translate($specWild);
        $this->assertEquals('"name" LIKE :p1', $clause->toSql());
        $this->assertEquals([':p1' => 'J%hn'], $clause->getParameters());

        // EqualIgnoreCase
        $specCase = new PropertySpecification($base, 'code', new EqualIgnoreCaseStringSpecification('promo'));

        // PostgreSQL usa ILIKE
        $clausePg = (new SqlQueryVisitor('pgsql'))->translate($specCase);
        $this->assertEquals('"code" ILIKE :p1', $clausePg->toSql());

        // Oracle usa LOWER(col) LIKE LOWER(:p)
        $clauseOra = (new SqlQueryVisitor('oracle'))->translate($specCase);
        $this->assertEquals('LOWER("code") LIKE LOWER(:p1)', $clauseOra->toSql());

        // Firebird usa LOWER(col) LIKE LOWER(:p)
        $clauseFb = (new SqlQueryVisitor('firebird'))->translate($specCase);
        $this->assertEquals('LOWER("code") LIKE LOWER(:p1)', $clauseFb->toSql());

        // MySQL usa LIKE
        $clauseMy = (new SqlQueryVisitor('mysql'))->translate($specCase);
        $this->assertEquals('`code` LIKE :p1', $clauseMy->toSql());
    }

    private function testRegexSupportAndExceptions(): void
    {
        $base = Spec::specify(stdClass::class);
        $specRegex = new PropertySpecification($base, 'phone', new RegexSpecification('^[0-9]+$'));

        // PostgreSQL suporta ~
        $clausePg = (new SqlQueryVisitor('pgsql'))->translate($specRegex);
        $this->assertEquals('"phone" ~ :p1', $clausePg->toSql());

        // MySQL suporta REGEXP BINARY
        $clauseMy = (new SqlQueryVisitor('mysql'))->translate($specRegex);
        $this->assertEquals('`phone` REGEXP BINARY :p1', $clauseMy->toSql());

        // Oracle suporta REGEXP_LIKE
        $clauseOra = (new SqlQueryVisitor('oracle'))->translate($specRegex);
        $this->assertEquals('REGEXP_LIKE("phone", :p1, \'c\')', $clauseOra->toSql());

        // SQL Server e Firebird lançam exceção unsupported
        $this->assertThrows(UnsupportedSqlOperationException::class, function () use ($specRegex) {
            (new SqlQueryVisitor('sqlsrv'))->translate($specRegex);
        });

        $this->assertThrows(UnsupportedSqlOperationException::class, function () use ($specRegex) {
            (new SqlQueryVisitor('firebird'))->translate($specRegex);
        });
    }

    private function testFieldMapper(): void
    {
        $base = Spec::specify(stdClass::class);

        // Mapeador explícito de dicionário com alias de tabela
        $mapper = new FieldMapper([
            'valorAluguel' => 'vl_aluguel',
            'nomeLocatario' => 'nm_locatario',
        ], 'c');

        $spec = (new PropertySpecification($base, 'valorAluguel', new GreaterThanSpecification(2500.0)))
            ->and(new PropertySpecification($base, 'nomeLocatario', new EqualSpecification('João')));

        $clause = (new SqlQueryVisitor('pgsql', $mapper))->translate($spec);
        $this->assertEquals('("c"."vl_aluguel" > :p1 AND "c"."nm_locatario" = :p2)', $clause->toSql());
    }

    private function testFluentFacadeAndMethodToSql(): void
    {
        $base = Spec::specify(stdClass::class);
        $spec = new PropertySpecification($base, 'status', new EqualSpecification('ACTIVE'));

        // 1. Chamada direta no objeto $spec->toSql()
        $clause1 = $spec->toSql('mysql');
        $this->assertInstanceOf(ISqlWhereClause::class, $clause1);
        $this->assertEquals('`status` = :p1', $clause1->toSql());

        // 2. Chamada via Facade Spec::toSql()
        $clause2 = Spec::toSql($spec, 'sqlsrv');
        $this->assertInstanceOf(ISqlWhereClause::class, $clause2);
        $this->assertEquals('[status] = :p1', $clause2->toSql());

        // 3. Chamada via accept(ISpecificationVisitor)
        $visitor = Spec::sqlVisitor('oracle');
        $clause3 = $spec->accept($visitor);
        $this->assertInstanceOf(ISqlWhereClause::class, $clause3);
        $this->assertEquals('"status" = :p1', $clause3->toSql());
    }

    private function testNonTranslatableException(): void
    {
        // Especificação anônima pura sem PropertySpecification ou não-traduzível
        $untranslatable = new class extends AbstractSpecification {
            public function getType(): string { return 'mixed'; }
            public function isSatisfiedBy(mixed $c): bool { return true; }
        };

        $visitor = new SqlQueryVisitor('pgsql');
        $this->assertThrows(NonTranslatableSpecificationException::class, function () use ($visitor, $untranslatable) {
            $visitor->translate($untranslatable);
        });
    }

    private function testSqlWhereClauseComposition(): void
    {
        $c1 = new SqlWhereClause('"age" > :p1', [':p1' => 18]);
        $c2 = new SqlWhereClause('"status" = :p2', [':p2' => 'A']);

        $combinedAnd = $c1->and($c2);
        $this->assertEquals('("age" > :p1 AND "status" = :p2)', $combinedAnd->toSql());
        $this->assertEquals([':p1' => 18, ':p2' => 'A'], $combinedAnd->getParameters());

        $combinedOr = $c1->or($c2);
        $this->assertEquals('("age" > :p1 OR "status" = :p2)', $combinedOr->toSql());

        $empty = SqlWhereClause::empty();
        $this->assertTrue($empty->isEmpty());
        $this->assertEquals($c1->toSql(), $c1->and($empty)->toSql());
    }
}
