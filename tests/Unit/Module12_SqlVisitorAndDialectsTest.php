<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\Sql\ISqlPagination;
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
use Antevemus\ASpecification\Sql\Dialects\InformixDialect;
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
 * todos os dialetos do ecossistema: sqlsrv, oracle, oci, mysql, mssql, ibase, firebird, fbird, dblib, pgsql, sqlite,
 * e desde 1.6.0 db2, informix e duckdb (com paginação em todos os dialetos).
 *
 * @version    1.6.0
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

        // Lote de correção #20-#29 (2026-10-07): quoting em maiúsculas (Oracle/Firebird),
        // startsWith/endsWith/contains como LIKE, regex sem delimitadores PHP.
        $this->testOracleAndFirebirdQuoteIdentifiersInUpperCase();
        $this->testStringAffixesTranslateToLikeInEveryDialect();
        $this->testRegexBindHasNoPhpDelimitersOrModifiers();

        // Lote de correção #30-#32 (2026-10-07): % e _ literais de like() escapados no LIKE.
        $this->testWildcardLiteralsAreEscapedInLike();

        // Forward 017 (v1.5.0), RN-07: in() traduzido como IN (...) em todos os dialetos.
        $this->testRn07InTranslatesToSqlInList();

        // Forward 018 (v1.6.0), RN-07: chamada de método é opaca ao SQL (exceção de folha não traduzível).
        $this->testF018MethodCallIsNotTranslatableToSql();

        // Forward 020 (v1.6.0), RN-07: dialetos Db2, Informix e DuckDB; paginação em todos os dialetos.
        $this->testRn07Db2Dialect();
        $this->testRn07InformixDialect();
        $this->testRn07DuckDbDialect();
        $this->testPaginationAcrossDialects();
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

        // PostgreSQL, SQLite e ANSI usam aspas duplas sem alterar a caixa.
        // Oracle e Firebird (oracle, oci, firebird, fbird, ibase) citam em MAIÚSCULAS: ver
        // testOracleAndFirebirdQuoteIdentifiersInUpperCase (BUG-20261007-MNZN, decisão de 2026-10-07).
        foreach (['pgsql', 'sqlite', 'ansi'] as $d) {
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
        $this->assertEquals('"STATUS" <> :p1', $clauseOra->toSql()); // Oracle cita em maiúsculas (BUG-20261007-MNZN)
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
        $this->assertEquals('(("AGE" > :p1 AND "STATUS" = :p2) OR NOT ("IS_VIP" = 1))', $clauseOci->toSql()); // Oracle cita em maiúsculas (BUG-20261007-MNZN)
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
        $this->assertEquals('LOWER("CODE") LIKE LOWER(:p1)', $clauseOra->toSql()); // Oracle cita em maiúsculas (BUG-20261007-MNZN)

        // Firebird usa LOWER(col) LIKE LOWER(:p)
        $clauseFb = (new SqlQueryVisitor('firebird'))->translate($specCase);
        $this->assertEquals('LOWER("CODE") LIKE LOWER(:p1)', $clauseFb->toSql()); // Firebird cita em maiúsculas (BUG-20261007-MNZN)

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
        $this->assertEquals('REGEXP_LIKE("PHONE", :p1, \'c\')', $clauseOra->toSql()); // Oracle cita em maiúsculas (BUG-20261007-MNZN)

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
        $this->assertEquals('"STATUS" = :p1', $clause3->toSql()); // Oracle cita em maiúsculas (BUG-20261007-MNZN)
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

    /**
     * BUG-20261007-MNZN (#26), spec 012 RN-03: Oracle e Firebird citam identificadores em MAIÚSCULAS,
     * porque nesses SGBDs um identificador criado sem aspas é armazenado em maiúsculas e um identificador
     * citado é case-sensitive. Reprodução: antes da correção os cinco aliases emitiam "status" / "c"."status",
     * que não casa com a coluna STATUS do catálogo.
     */
    private function testOracleAndFirebirdQuoteIdentifiersInUpperCase(): void
    {
        foreach (['oracle', 'oci', 'firebird', 'fbird', 'ibase'] as $d) {
            $obj = SqlDialectFactory::create($d);
            $this->assertEquals('"STATUS"', $obj->escapeIdentifier('status'), "$d: segmento simples em maiúsculas");
            $this->assertEquals('"C"."STATUS"', $obj->escapeIdentifier('c.status'), "$d: cada segmento qualificado em maiúsculas");
            $this->assertEquals('"VAL_SALARY"', $obj->escapeIdentifier('val_salary'), "$d: underscore preservado");
            $this->assertEquals('"ALREADY_UP"', $obj->escapeIdentifier('ALREADY_UP'), "$d: já em maiúsculas não muda");

            // Regressão: função declarada pelo mapper continua verbatim (o banco dobra o nome nu),
            // e a gramática estrita do SJVE continua recusando o que não é identificador.
            $this->assertEquals('LOWER(name)', $obj->escapeIdentifier('LOWER(name)'), "$d: função passa verbatim");
            $this->assertThrows(UnsafeIdentifierException::class, fn() => $obj->escapeIdentifier('name; DROP'));
        }

        // Cláusula completa (exemplo 8 do README) nos dois dialetos
        $spec = Spec::property('val_salary', Spec::greaterThan(5000))
            ->and(Spec::property('txt_city', Spec::equalTo('Rio')));
        foreach (['oracle', 'firebird'] as $d) {
            $clause = Spec::toSql($spec, $d);
            $this->assertEquals('("VAL_SALARY" > :p1 AND "TXT_CITY" = :p2)', $clause->getSql(), "$d: cláusula completa");
            $this->assertEquals([':p1' => 5000, ':p2' => 'Rio'], $clause->getBindings(), "$d: bindings inalterados");
        }

        // Regressão: os demais dialetos não alteram a caixa
        $this->assertEquals('"val_salary"', SqlDialectFactory::create('pgsql')->escapeIdentifier('val_salary'));
        $this->assertEquals('`val_salary`', SqlDialectFactory::create('mysql')->escapeIdentifier('val_salary'));
        $this->assertEquals('[val_salary]', SqlDialectFactory::create('sqlsrv')->escapeIdentifier('val_salary'));
        $this->assertEquals('"val_salary"', SqlDialectFactory::create('sqlite')->escapeIdentifier('val_salary'));
        $this->assertEquals('"val_salary"', SqlDialectFactory::create('ansi')->escapeIdentifier('val_salary'));
    }

    /**
     * BUG-20261007-3E3F (#29): startsWith()/endsWith()/contains() traduzem para LIKE em todos os dialetos,
     * com escape de %, _ e do caractere de escape quando o literal os contém. Antes: REGEX com o padrão PHP
     * inteiro ('/^Ab/') como bind, que nunca casa em pgsql/mysql/oracle e é recusado em sqlite/firebird/ansi.
     */
    private function testStringAffixesTranslateToLikeInEveryDialect(): void
    {
        // 1. Reprodução: prefixo, case-sensitive, nos sete dialetos
        $spec = Spec::property('name', Spec::startsWith('Ab'));
        $expected = [
            'pgsql' => '"name" LIKE :p1',
            'mysql' => 'BINARY `name` LIKE :p1',
            'sqlsrv' => '[name] LIKE :p1',
            'sqlite' => '"name" LIKE :p1',
            'ansi' => '"name" LIKE :p1',
            'oracle' => '"NAME" LIKE :p1',
            'firebird' => '"NAME" LIKE :p1',
        ];
        foreach ($expected as $d => $sql) {
            $clause = Spec::toSql($spec, $d);
            $this->assertEquals($sql, $clause->getSql(), "$d: startsWith vira LIKE");
            $this->assertEquals([':p1' => 'Ab%'], $clause->getBindings(), "$d: bind é o prefixo com %");
        }

        // 2. Sufixo e substring
        $this->assertEquals([':p1' => '%Ab'], Spec::toSql(Spec::property('name', Spec::endsWith('Ab')), 'pgsql')->getBindings());
        $this->assertEquals([':p1' => '%Ab%'], Spec::toSql(Spec::property('name', Spec::contains('Ab')), 'pgsql')->getBindings());

        // 3. Case-insensitive usa o LIKE insensível de cada dialeto
        $ci = Spec::property('name', Spec::startsWith('ab', false));
        $this->assertEquals('"name" ILIKE :p1', Spec::toSql($ci, 'pgsql')->getSql());
        $this->assertEquals('`name` LIKE :p1', Spec::toSql($ci, 'mysql')->getSql());
        $this->assertEquals('LOWER("NAME") LIKE LOWER(:p1)', Spec::toSql($ci, 'oracle')->getSql());
        $this->assertEquals('LOWER([name]) LIKE LOWER(:p1)', Spec::toSql($ci, 'sqlsrv')->getSql());

        // 4. Escape dos curingas do LIKE: só quando o literal os contém, com ESCAPE '!' portável
        $clause = Spec::toSql(Spec::property('promo', Spec::contains('50%_off!')), 'pgsql');
        $this->assertEquals('"promo" LIKE :p1 ESCAPE \'!\'', $clause->getSql());
        $this->assertEquals([':p1' => '%50!%!_off!!%'], $clause->getBindings());

        // Barra invertida é escape padrão do LIKE no MySQL: a cláusula ESCAPE explícita a neutraliza
        $clause = Spec::toSql(Spec::property('path', Spec::startsWith('C:\\')), 'mysql');
        $this->assertEquals('BINARY `path` LIKE :p1 ESCAPE \'!\'', $clause->getSql());
        $this->assertEquals([':p1' => 'C:\\%'], $clause->getBindings());

        // 5. NOT continua sendo tratado pelo visitor
        $this->assertEquals('NOT ("name" LIKE :p1)', Spec::toSql(Spec::not(Spec::property('name', Spec::startsWith('Ab'))), 'pgsql')->getSql());

        // 6. Regressão em memória: semântica idêntica à anterior (case-sensitive por padrão, literal sem regex)
        $this->assertTrue(Spec::startsWith('Ab')->isSatisfiedBy('Abc'));
        $this->assertFalse(Spec::startsWith('Ab')->isSatisfiedBy('abc'));
        $this->assertTrue(Spec::startsWith('ab', false)->isSatisfiedBy('ABC'));
        $this->assertTrue(Spec::endsWith('.txt')->isSatisfiedBy('file.txt'));
        $this->assertFalse(Spec::endsWith('.txt')->isSatisfiedBy('file_txt'));
        $this->assertTrue(Spec::contains('50%_off!')->isSatisfiedBy('today 50%_off! only'));
        $this->assertFalse(Spec::contains('a.c')->isSatisfiedBy('abc'), 'ponto é literal, não curinga de regex');
        $this->assertFalse(Spec::startsWith('Ab')->isSatisfiedBy(42));

        // 7. Compatibilidade: as folhas continuam sendo RegexSpecification para os demais visitors
        $this->assertInstanceOf(RegexSpecification::class, Spec::startsWith('Ab'));
        $this->assertInstanceOf(RegexSpecification::class, Spec::endsWith('Ab'));
        $this->assertInstanceOf(RegexSpecification::class, Spec::contains('Ab'));
        $this->assertTrue(Spec::startsWith('Ab')->equals(Spec::startsWith('Ab')));
        $this->assertFalse(Spec::startsWith('Ab')->equals(Spec::endsWith('Ab')));
    }

    /**
     * BUG-20261007-3E3F (#29), parte 2: RegexSpecification genérica vai ao banco sem os delimitadores e
     * modificadores do PHP; o modificador i mapeia para o REGEX insensível do dialeto; modificadores sem
     * equivalente portável são recusados com UnsupportedSqlOperationException.
     */
    private function testRegexBindHasNoPhpDelimitersOrModifiers(): void
    {
        $base = Spec::specify(stdClass::class);

        $delimited = new PropertySpecification($base, 'phone', new RegexSpecification('/^[0-9]+$/'));
        $clause = (new SqlQueryVisitor('pgsql'))->translate($delimited);
        $this->assertEquals('"phone" ~ :p1', $clause->toSql());
        $this->assertEquals([':p1' => '^[0-9]+$'], $clause->getParameters(), 'bind sem as barras do PHP');

        // Outros delimitadores e o modificador u (sem efeito no banco)
        $hash = new PropertySpecification($base, 'phone', new RegexSpecification('#^\d{2}/\d{4}$#u'));
        $this->assertEquals([':p1' => '^\d{2}/\d{4}$'], (new SqlQueryVisitor('mysql'))->translate($hash)->getParameters());

        // Modificador i: REGEX insensível de cada dialeto
        $ci = new PropertySpecification($base, 'code', new RegexSpecification('/^abc/i'));
        $this->assertEquals('"code" ~* :p1', (new SqlQueryVisitor('pgsql'))->translate($ci)->toSql());
        $this->assertEquals('`code` REGEXP :p1', (new SqlQueryVisitor('mysql'))->translate($ci)->toSql());
        $this->assertEquals('REGEXP_LIKE("CODE", :p1, \'i\')', (new SqlQueryVisitor('oracle'))->translate($ci)->toSql());
        $this->assertEquals([':p1' => '^abc'], (new SqlQueryVisitor('oracle'))->translate($ci)->getParameters());

        // Modificador sem equivalente portável: recusa explícita
        $multiline = new PropertySpecification($base, 'code', new RegexSpecification('/^abc$/m'));
        $e = $this->assertThrows(UnsupportedSqlOperationException::class, fn() => (new SqlQueryVisitor('pgsql'))->translate($multiline));
        $this->assertTrue(str_contains($e->getMessage(), 'm'), 'a mensagem nomeia o modificador recusado');

        // Regressão: padrão sem delimitadores (uso legado nos testes) passa inalterado
        $raw = new PropertySpecification($base, 'phone', new RegexSpecification('^[0-9]+$'));
        $this->assertEquals([':p1' => '^[0-9]+$'], (new SqlQueryVisitor('pgsql'))->translate($raw)->getParameters());

        // Regressão: isBlank() (regex interna) também sai limpo
        $this->assertEquals([':p1' => '^\s*$'], Spec::toSql(Spec::property('name', Spec::isBlank()), 'pgsql')->getBindings());
    }

    /**
     * BUG-20261007-ZY6E (#32): os caracteres %, _ e ! literais de um padrão wildcard (like()) iam ao
     * SQL sem escape e o LIKE casava mais do que a avaliação em memória (fnmatch). Agora recebem o
     * mesmo escape das fábricas de string (bug #29), com ESCAPE '!' só quando necessário.
     */
    private function testWildcardLiteralsAreEscapedInLike(): void
    {
        // 1. Reprodução: % literal no padrão, nos sete dialetos
        $spec = Spec::property('name', Spec::like('100%*'));
        $expected = [
            'pgsql' => '"name" LIKE :p1 ESCAPE \'!\'',
            'mysql' => 'BINARY `name` LIKE :p1 ESCAPE \'!\'',
            'sqlsrv' => '[name] LIKE :p1 ESCAPE \'!\'',
            'sqlite' => '"name" LIKE :p1 ESCAPE \'!\'',
            'ansi' => '"name" LIKE :p1 ESCAPE \'!\'',
            'oracle' => '"NAME" LIKE :p1 ESCAPE \'!\'',
            'firebird' => '"NAME" LIKE :p1 ESCAPE \'!\'',
        ];
        foreach ($expected as $d => $sql) {
            $clause = Spec::toSql($spec, $d);
            $this->assertEquals($sql, $clause->getSql(), "$d: like com % literal leva ESCAPE");
            $this->assertEquals([':p1' => '100!%%'], $clause->getBindings(), "$d: % literal escapado, * vira %");
        }

        // 2. _ e ! literais; ? vira _ sem escape
        $this->assertEquals([':p1' => 'a!_b_'], Spec::toSql(Spec::property('name', Spec::like('a_b?')), 'pgsql')->getBindings());
        $this->assertEquals([':p1' => '50!%!!%'], Spec::toSql(Spec::property('name', Spec::like('50%!*')), 'pgsql')->getBindings());

        // 3. Barra invertida: escape padrão do LIKE no MySQL, neutralizada pela cláusula ESCAPE explícita
        $clause = Spec::toSql(Spec::property('path', Spec::like('C:\\*')), 'mysql');
        $this->assertEquals('BINARY `path` LIKE :p1 ESCAPE \'!\'', $clause->getSql());
        $this->assertEquals([':p1' => 'C:\\%'], $clause->getBindings());

        // 4. Ignore-case usa o LIKE insensível do dialeto, com o mesmo escape
        $ci = Spec::toSql(Spec::property('name', Spec::wildcardExpressionMatcherIgnoreCase('100%*')), 'pgsql');
        $this->assertEquals('"name" ILIKE :p1 ESCAPE \'!\'', $ci->getSql());
        $this->assertEquals([':p1' => '100!%%'], $ci->getBindings());

        // 5. NOT envolve a cláusula inteira
        $this->assertEquals('NOT ("name" LIKE :p1 ESCAPE \'!\')', Spec::toSql(Spec::not($spec), 'pgsql')->getSql());

        // 6. Regressão: padrão sem % / _ / ! / barra invertida sai exatamente como antes, sem ESCAPE
        $plain = Spec::toSql(Spec::property('name', Spec::like('J*hn?')), 'pgsql');
        $this->assertEquals('"name" LIKE :p1', $plain->getSql());
        $this->assertEquals([':p1' => 'J%hn_'], $plain->getBindings());

        // 7. Paridade com a memória: o LIKE passa a distinguir o que fnmatch distingue
        $this->assertTrue(Spec::like('100%*')->isSatisfiedBy('100%x'));
        $this->assertFalse(Spec::like('100%*')->isSatisfiedBy('1000x'), 'em memória 1000x nunca casou; o SQL agora também não');
        $this->assertTrue(Spec::like('a_b?')->isSatisfiedBy('a_bc'));
        $this->assertFalse(Spec::like('a_b?')->isSatisfiedBy('aXbc'));
    }

    /**
     * RN-07 (forward 017, v1.5.0): InSpecification vira `"col" IN (:p1, :p2)`; antes a cadeia de OR
     * emitia `("col" = :p1 OR "col" = :p2)`. in([]) é a forma falsa do dialeto (`1 = 0`); um null no
     * conjunto vira `OR "col" IS NULL` (IN nunca casa NULL em SQL); notIn() vira NOT (... IN ...).
     */
    private function testRn07InTranslatesToSqlInList(): void
    {
        // Cenário Gherkin
        $spec = Spec::property('status', Spec::in('A', 'B'));
        $where = Spec::toSql($spec, SqlDialect::POSTGRESQL);
        $this->assertEquals('"status" IN (:p1, :p2)', $where->getSql());
        $this->assertEquals([':p1' => 'A', ':p2' => 'B'], $where->getBindings());

        // Cada dialeto com o próprio quoting
        $this->assertEquals('`status` IN (:p1, :p2)', Spec::toSql($spec, 'mysql')->getSql());
        $this->assertEquals('[status] IN (:p1, :p2)', Spec::toSql($spec, 'sqlsrv')->getSql());
        $this->assertEquals('"STATUS" IN (:p1, :p2)', Spec::toSql($spec, 'oracle')->getSql());
        $this->assertEquals('"STATUS" IN (:p1, :p2)', Spec::toSql($spec, 'firebird')->getSql());
        $this->assertEquals('"status" IN (:p1, :p2)', Spec::toSql($spec, 'sqlite')->getSql());
        $this->assertEquals('"status" IN (:p1, :p2)', Spec::toSql($spec, 'ansi')->getSql());

        // Vazio: a forma falsa do dialeto, sem parâmetros
        $empty = Spec::toSql(Spec::property('status', Spec::in()), 'pgsql');
        $this->assertEquals('1 = 0', $empty->getSql());
        $this->assertEquals([], $empty->getBindings());

        // null e booleanos no conjunto
        $this->assertEquals('("status" IN (:p1) OR "status" IS NULL)', Spec::toSql(Spec::property('status', Spec::in('A', null)), 'pgsql')->getSql());
        $this->assertEquals('"status" IS NULL', Spec::toSql(Spec::property('status', Spec::in(null)), 'pgsql')->getSql());
        $this->assertEquals('`ativo` IN (1)', Spec::toSql(Spec::property('ativo', Spec::in(true)), 'mysql')->getSql());

        // Negação e composição: numeração de parâmetros contínua
        $this->assertEquals('NOT ("status" IN (:p1, :p2))', Spec::toSql(Spec::property('status', Spec::notIn('A', 'B')), 'pgsql')->getSql());
        $combined = Spec::toSql(
            Spec::property('status', Spec::in('A', 'B'))->and(Spec::property('uf', Spec::in(['SP', 'RJ', 'MG']))),
            'pgsql'
        );
        $this->assertEquals('("status" IN (:p1, :p2) AND "uf" IN (:p3, :p4, :p5))', $combined->getSql());
        $this->assertEquals([':p1' => 'A', ':p2' => 'B', ':p3' => 'SP', ':p4' => 'RJ', ':p5' => 'MG'], $combined->getBindings());

        // Fora de propriedade não há coluna
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => Spec::toSql(Spec::in(1, 2), 'pgsql'));

        // O SQL emitido roda num SQLite em memória e devolve o mesmo que a avaliação em memória
        if (extension_loaded('pdo_sqlite')) {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE t (id INTEGER, status TEXT)');
            foreach ([[1, 'A'], [2, 'B'], [3, 'C'], [4, null], [5, 'a']] as [$id, $st]) {
                $stmt = $pdo->prepare('INSERT INTO t (id, status) VALUES (?, ?)');
                $stmt->execute([$id, $st]);
            }
            foreach ([Spec::in('A', 'B'), Spec::in('A', null), Spec::in(), Spec::notIn('A', 'B')] as $leaf) {
                $clause = Spec::toSql(Spec::property('status', $leaf), 'sqlite');
                $stmt = $pdo->prepare('SELECT id FROM t WHERE ' . $clause->getSql() . ' ORDER BY id');
                $stmt->execute($clause->getBindings());
                $sqlIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

                $memoryIds = [];
                foreach ([[1, 'A'], [2, 'B'], [3, 'C'], [4, null], [5, 'a']] as [$id, $st]) {
                    if ($leaf->isSatisfiedBy($st)) {
                        $memoryIds[] = $id;
                    }
                }
                if ($leaf instanceof \Antevemus\ASpecification\Specifications\NotSpecification) {
                    // SQL: NOT (NULL IN (...)) é desconhecido, a linha nula não volta; a folha em memória
                    // aceita null para notIn. Diferença de três valores conhecida da negação em SQL.
                    $memoryIds = array_values(array_diff($memoryIds, [4]));
                }
                $this->assertEquals($memoryIds, $sqlIds, 'SQL e memória concordam para ' . $clause->getSql());
            }
        }
    }

    /**
     * Forward 018 RN-07 / §3 cenário "tradução": toSql() de uma chamada declarativa de método lança
     * a mesma exceção de folha não traduzível que o predicado de must(), em qualquer posição da árvore.
     */
    private function testF018MethodCallIsNotTranslatableToSql(): void
    {
        $call = Spec::calling('isEligibleFor', [new \DateTimeImmutable('2026-12-01')], Spec::isTrue());
        $cases = [
            'folha solta' => $call,
            'sob propriedade' => Spec::property('contract', $call),
            'em conjunção' => Spec::property('status', Spec::equalTo('A'))->and($call),
            'negada' => $call->not(),
            'whereMethod' => Spec::property('status', Spec::equalTo('A'))->whereMethod('total', [3], Spec::greaterThan(10)),
        ];
        foreach ($cases as $label => $spec) {
            foreach (['pgsql', 'mysql', 'ansi'] as $dialect) {
                $e = $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $spec->toSql($dialect), "{$label} / {$dialect}");
                $this->assertTrue(str_contains($e->getMessage(), '(...)'), "mensagem nomeia o método: {$label}");
            }
        }
        $e = $this->assertThrows(NonTranslatableSpecificationException::class, fn() => Spec::toSql($call, 'pgsql'));
        $this->assertTrue(str_contains($e->getMessage(), 'isEligibleFor(...)'));
    }

    /**
     * Forward 020 (v1.6.0), RN-07: IBM Db2. Aspas duplas em MAIÚSCULAS (como Oracle/Firebird), booleanos 1/0,
     * LIKE insensível por LOWER(), REGEXP_LIKE com 'c'/'i', paginação SQL:2008 (FETCH FIRST / OFFSET ... ROWS).
     */
    private function testRn07Db2Dialect(): void
    {
        foreach (['db2' => 'db2', 'ibm' => 'db2', 'ibm_db2' => 'db2', 'pdo_ibm' => 'db2', 'DB2' => 'db2'] as $driver => $family) {
            $this->assertEquals($family, SqlDialectFactory::create($driver)->getFamily(), "driver {$driver}");
        }
        $this->assertTrue(SqlDialect::fromDriver('ibm_db2') === SqlDialect::DB2);

        $db2 = SqlDialectFactory::create(SqlDialect::DB2);
        $this->assertEquals('"STATUS"', $db2->escapeIdentifier('status'));
        $this->assertEquals('"C"."STATUS"', $db2->escapeIdentifier('c.status'));
        $this->assertEquals('LOWER(name)', $db2->escapeIdentifier('LOWER(name)'), 'função declarada pelo mapper passa verbatim');
        $this->assertThrows(UnsafeIdentifierException::class, fn() => $db2->escapeIdentifier('name; DROP'));
        $this->assertEquals('1', $db2->formatBoolean(true));
        $this->assertEquals('0', $db2->formatBoolean(false));
        $this->assertEquals('1 = 1', $db2->getTrueCondition());
        $this->assertEquals('1 = 0', $db2->getFalseCondition());

        $this->assertDialectClauses('db2', [
            'eq' => '"AGE" = :p1',
            'gte' => '"AGE" >= :p1',
            'lte' => '"AGE" <= :p1',
            'neq' => '"STATUS" <> :p1',
            'in' => '"STATUS" IN (:p1, :p2)',
            'inNull' => '("STATUS" IN (:p1) OR "STATUS" IS NULL)',
            'isNull' => '"DELETED_AT" IS NULL',
            'notNull' => '"DELETED_AT" IS NOT NULL',
            'bool' => '"ACTIVE" = 1',
            'notBool' => '"ACTIVE" = 0',
            'likeEscape' => '"PROMO" LIKE :p1 ESCAPE \'!\'',
            'likeCi' => 'LOWER("NAME") LIKE LOWER(:p1)',
            'regex' => 'REGEXP_LIKE("PHONE", :p1, \'c\')',
            'regexCi' => 'REGEXP_LIKE("CODE", :p1, \'i\')',
            'combined' => '("AGE" >= :p1 AND "STATUS" IN (:p2, :p3))',
        ]);
        $this->assertTrue($db2->supportsRegex());

        $this->assertPagination($db2, [
            'limit' => 'SELECT * FROM t ORDER BY id FETCH FIRST 10 ROWS ONLY',
            'both' => 'SELECT * FROM t ORDER BY id OFFSET 20 ROWS FETCH FIRST 10 ROWS ONLY',
            'offset' => 'SELECT * FROM t ORDER BY id OFFSET 20 ROWS',
        ]);
    }

    /**
     * Forward 020 (v1.6.0), RN-07: IBM Informix. Sem DELIMIDENT o Informix lê "x" como literal de texto, então
     * o identificador sai sem delimitador (a gramática fechada já garante que é seguro); com DELIMIDENT declarado
     * sai entre aspas em minúsculas. Booleanos 't'/'f', LIKE insensível por LOWER(), sem REGEX, paginação
     * SKIP/FIRST na cláusula de projeção.
     */
    private function testRn07InformixDialect(): void
    {
        foreach (['informix' => 'informix', 'ifx' => 'informix', 'pdo_informix' => 'informix'] as $driver => $family) {
            $this->assertEquals($family, SqlDialectFactory::create($driver)->getFamily(), "driver {$driver}");
        }
        $this->assertTrue(SqlDialect::fromDriver('pdo_informix') === SqlDialect::INFORMIX);

        $ifx = SqlDialectFactory::create(SqlDialect::INFORMIX);
        $this->assertEquals('status', $ifx->escapeIdentifier('status'));
        $this->assertEquals('c.status', $ifx->escapeIdentifier('c.status'));
        $this->assertEquals('Val_Salary', $ifx->escapeIdentifier('Val_Salary'), 'sem delimitador o Informix ignora a caixa');
        $this->assertThrows(UnsafeIdentifierException::class, fn() => $ifx->escapeIdentifier('name; DROP'));
        $this->assertThrows(UnsafeIdentifierException::class, fn() => $ifx->escapeIdentifier('"status"'));
        $this->assertThrows(UnsafeIdentifierException::class, fn() => $ifx->escapeIdentifier('a b'));
        $this->assertEquals("'t'", $ifx->formatBoolean(true));
        $this->assertEquals("'f'", $ifx->formatBoolean(false));
        $this->assertEquals('1 = 0', $ifx->getFalseCondition());

        $delimited = new InformixDialect(delimitedIdentifiers: true);
        $this->assertEquals('"status"', $delimited->escapeIdentifier('STATUS'));
        $this->assertEquals('"c"."val_salary"', $delimited->escapeIdentifier('C.Val_Salary'));
        $this->assertEquals('"status" = :p1', (new SqlQueryVisitor($delimited))->translate(Spec::property('status', Spec::equalTo('A')))->toSql());

        $this->assertDialectClauses('informix', [
            'eq' => 'age = :p1',
            'gte' => 'age >= :p1',
            'lte' => 'age <= :p1',
            'neq' => 'status <> :p1',
            'in' => 'status IN (:p1, :p2)',
            'inNull' => '(status IN (:p1) OR status IS NULL)',
            'isNull' => 'deleted_at IS NULL',
            'notNull' => 'deleted_at IS NOT NULL',
            'bool' => "active = 't'",
            'notBool' => "active = 'f'",
            'likeEscape' => 'promo LIKE :p1 ESCAPE \'!\'',
            'likeCi' => 'LOWER(name) LIKE LOWER(:p1)',
            'regex' => UnsupportedSqlOperationException::class,
            'regexCi' => UnsupportedSqlOperationException::class,
            'combined' => '(age >= :p1 AND status IN (:p2, :p3))',
        ]);
        $this->assertFalse($ifx->supportsRegex());
        $this->assertEquals("active IN ('t')", Spec::toSql(Spec::property('active', Spec::in(true)), 'informix')->getSql());

        $this->assertPagination($ifx, [
            'limit' => 'SELECT FIRST 10 * FROM t ORDER BY id',
            'both' => 'SELECT SKIP 20 FIRST 10 * FROM t ORDER BY id',
            'offset' => 'SELECT SKIP 20 * FROM t ORDER BY id',
        ]);
        $this->assertEquals('select SKIP 5 FIRST 1 DISTINCT a FROM t', $ifx->paginate('select DISTINCT a FROM t', 1, 5), 'SKIP/FIRST antes de DISTINCT, palavra-chave em qualquer caixa');
        $this->assertThrows(\InvalidArgumentException::class, fn() => $ifx->paginate('WITH x AS (SELECT 1) SELECT * FROM x', 10));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $ifx->paginate('SELECTED_ROWS', 10), 'SELECT precisa ser a palavra inteira');
    }

    /**
     * Forward 020 (v1.6.0), RN-07: DuckDB. Aspas duplas sem mudar a caixa, TRUE/FALSE, ILIKE, regexp_matches()
     * (busca, como preg_match) com flag 'i', paginação LIMIT/OFFSET.
     */
    private function testRn07DuckDbDialect(): void
    {
        foreach (['duckdb' => 'duckdb', 'pdo_duckdb' => 'duckdb', 'DuckDB' => 'duckdb'] as $driver => $family) {
            $this->assertEquals($family, SqlDialectFactory::create($driver)->getFamily(), "driver {$driver}");
        }
        $this->assertTrue(SqlDialect::fromDriver('duckdb') === SqlDialect::DUCKDB);

        $duck = SqlDialectFactory::create(SqlDialect::DUCKDB);
        $this->assertEquals('"status"', $duck->escapeIdentifier('status'));
        $this->assertEquals('"c"."Val_Salary"', $duck->escapeIdentifier('c.Val_Salary'), 'caixa preservada');
        $this->assertThrows(UnsafeIdentifierException::class, fn() => $duck->escapeIdentifier('name; DROP'));
        $this->assertEquals('TRUE', $duck->formatBoolean(true));
        $this->assertEquals('FALSE', $duck->formatBoolean(false));

        $this->assertDialectClauses('duckdb', [
            'eq' => '"age" = :p1',
            'gte' => '"age" >= :p1',
            'lte' => '"age" <= :p1',
            'neq' => '"status" <> :p1',
            'in' => '"status" IN (:p1, :p2)',
            'inNull' => '("status" IN (:p1) OR "status" IS NULL)',
            'isNull' => '"deleted_at" IS NULL',
            'notNull' => '"deleted_at" IS NOT NULL',
            'bool' => '"active" = TRUE',
            'notBool' => '"active" = FALSE',
            'likeEscape' => '"promo" LIKE :p1 ESCAPE \'!\'',
            'likeCi' => '"name" ILIKE :p1',
            'regex' => 'regexp_matches("phone", :p1)',
            'regexCi' => 'regexp_matches("code", :p1, \'i\')',
            'combined' => '("age" >= :p1 AND "status" IN (:p2, :p3))',
        ]);
        $this->assertTrue($duck->supportsRegex());
        $this->assertEquals('"name" ILIKE :p1 ESCAPE \'!\'', Spec::toSql(Spec::property('name', Spec::wildcardExpressionMatcherIgnoreCase('100%*')), 'duckdb')->getSql());

        $this->assertPagination($duck, [
            'limit' => 'SELECT * FROM t ORDER BY id LIMIT 10',
            'both' => 'SELECT * FROM t ORDER BY id LIMIT 10 OFFSET 20',
            'offset' => 'SELECT * FROM t ORDER BY id OFFSET 20',
        ]);
    }

    /**
     * Forward 020 (v1.6.0), RN-07: paginate() (ISqlPagination) em todos os dialetos, com validação dos argumentos;
     * a forma do SQLite roda num banco em memória.
     */
    private function testPaginationAcrossDialects(): void
    {
        $expected = [
            'pgsql' => ['SELECT * FROM t ORDER BY id LIMIT 10', 'SELECT * FROM t ORDER BY id LIMIT 10 OFFSET 20', 'SELECT * FROM t ORDER BY id OFFSET 20'],
            'mysql' => ['SELECT * FROM t ORDER BY id LIMIT 10', 'SELECT * FROM t ORDER BY id LIMIT 10 OFFSET 20', 'SELECT * FROM t ORDER BY id LIMIT 18446744073709551615 OFFSET 20'],
            'sqlite' => ['SELECT * FROM t ORDER BY id LIMIT 10', 'SELECT * FROM t ORDER BY id LIMIT 10 OFFSET 20', 'SELECT * FROM t ORDER BY id LIMIT -1 OFFSET 20'],
            'sqlsrv' => ['SELECT * FROM t ORDER BY id OFFSET 0 ROWS FETCH NEXT 10 ROWS ONLY', 'SELECT * FROM t ORDER BY id OFFSET 20 ROWS FETCH NEXT 10 ROWS ONLY', 'SELECT * FROM t ORDER BY id OFFSET 20 ROWS'],
            'oracle' => ['SELECT * FROM t ORDER BY id FETCH FIRST 10 ROWS ONLY', 'SELECT * FROM t ORDER BY id OFFSET 20 ROWS FETCH FIRST 10 ROWS ONLY', 'SELECT * FROM t ORDER BY id OFFSET 20 ROWS'],
            'firebird' => ['SELECT * FROM t ORDER BY id FETCH FIRST 10 ROWS ONLY', 'SELECT * FROM t ORDER BY id OFFSET 20 ROWS FETCH FIRST 10 ROWS ONLY', 'SELECT * FROM t ORDER BY id OFFSET 20 ROWS'],
            'ansi' => ['SELECT * FROM t ORDER BY id FETCH FIRST 10 ROWS ONLY', 'SELECT * FROM t ORDER BY id OFFSET 20 ROWS FETCH FIRST 10 ROWS ONLY', 'SELECT * FROM t ORDER BY id OFFSET 20 ROWS'],
        ];
        foreach ($expected as $driver => [$limit, $both, $offset]) {
            $this->assertPagination(SqlDialectFactory::create($driver), ['limit' => $limit, 'both' => $both, 'offset' => $offset]);
        }
        foreach (['mssql', 'dblib'] as $alias) {
            $this->assertInstanceOf(ISqlPagination::class, SqlDialectFactory::create($alias));
        }

        // SQL Server exige ORDER BY antes de OFFSET/FETCH
        $this->assertEquals(
            'SELECT * FROM t ORDER BY (SELECT NULL) OFFSET 0 ROWS FETCH NEXT 5 ROWS ONLY',
            SqlDialectFactory::create('sqlsrv')->paginate('SELECT * FROM t', 5)
        );

        // Validação e normalização comuns a todos
        $pg = SqlDialectFactory::create('pgsql');
        $this->assertEquals('SELECT 1', $pg->paginate('SELECT 1;  ', null, null), 'sem limite nem deslocamento: inalterado, sem o ponto e vírgula');
        $this->assertEquals('SELECT 1 LIMIT 3', $pg->paginate('SELECT 1;', 3, 0), 'deslocamento 0 é omitido');
        $this->assertThrows(\InvalidArgumentException::class, fn() => $pg->paginate('SELECT 1', 0));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $pg->paginate('SELECT 1', 10, -1));

        // A forma do SQLite executa e devolve a janela esperada, inclusive o deslocamento sem limite
        if (extension_loaded('pdo_sqlite')) {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE t (id INTEGER)');
            for ($i = 1; $i <= 30; $i++) {
                $pdo->exec("INSERT INTO t (id) VALUES ({$i})");
            }
            $sqlite = SqlDialectFactory::create('sqlite');
            $ids = fn(string $sql): array => array_map('intval', $pdo->query($sql)->fetchAll(\PDO::FETCH_COLUMN));
            $this->assertEquals([21, 22, 23], $ids($sqlite->paginate('SELECT id FROM t ORDER BY id', 3, 20)));
            $this->assertEquals([29, 30], $ids($sqlite->paginate('SELECT id FROM t ORDER BY id', null, 28)));
            $this->assertEquals([1, 2], $ids($sqlite->paginate('SELECT id FROM t ORDER BY id', 2)));
        }
    }

    /**
     * Traduz o mesmo conjunto de folhas num dialeto e compara com o esperado (string) ou com a exceção (classe).
     *
     * @param array<string, string> $expected
     */
    private function assertDialectClauses(string $driver, array $expected): void
    {
        $leaves = [
            'eq' => Spec::property('age', Spec::equalTo(30)),
            'gte' => Spec::property('age', Spec::greaterThanOrEqualTo(18)),
            'lte' => Spec::property('age', Spec::lessThanOrEqualTo(65)),
            'neq' => Spec::property('status', Spec::notEqual('X')),
            'in' => Spec::property('status', Spec::in('A', 'B')),
            'inNull' => Spec::property('status', Spec::in('A', null)),
            'isNull' => Spec::property('deletedAt', Spec::isNull()),
            'notNull' => Spec::property('deletedAt', Spec::isNotNull()),
            'bool' => Spec::property('active', Spec::equalTo(true)),
            'notBool' => Spec::property('active', Spec::notEqual(true)),
            'likeEscape' => Spec::property('promo', Spec::contains('50%_off')),
            'likeCi' => Spec::property('name', Spec::startsWith('ab', false)),
            'regex' => Spec::property('phone', Spec::regex('/^[0-9]+$/')),
            'regexCi' => Spec::property('code', Spec::regex('/^abc/i')),
            'combined' => Spec::property('age', Spec::greaterThanOrEqualTo(18))->and(Spec::property('status', Spec::in('A', 'B'))),
        ];

        foreach ($expected as $case => $sql) {
            if (class_exists($sql)) {
                $this->assertThrows($sql, fn() => Spec::toSql($leaves[$case], $driver), "{$driver}/{$case}: recusado");
                continue;
            }
            $clause = Spec::toSql($leaves[$case], $driver);
            $this->assertEquals($sql, $clause->getSql(), "{$driver}/{$case}");
        }

        // Os valores nunca entram no SQL: vão como bindings, com o escape do LIKE quando preciso
        $this->assertEquals([':p1' => 18], Spec::toSql($leaves['gte'], $driver)->getBindings());
        $this->assertEquals([':p1' => 'A', ':p2' => 'B'], Spec::toSql($leaves['in'], $driver)->getBindings());
        $this->assertEquals([':p1' => '%50!%!_off%'], Spec::toSql($leaves['likeEscape'], $driver)->getBindings());
        $this->assertEquals([], Spec::toSql($leaves['isNull'], $driver)->getBindings());
        $this->assertEquals('NOT (' . Spec::toSql($leaves['in'], $driver)->getSql() . ')', Spec::toSql(Spec::property('status', Spec::notIn('A', 'B')), $driver)->getSql());
    }

    /**
     * @param array{limit: string, both: string, offset: string} $expected
     */
    private function assertPagination(object $dialect, array $expected): void
    {
        $this->assertInstanceOf(ISqlPagination::class, $dialect);
        $base = 'SELECT * FROM t ORDER BY id';
        $family = $dialect->getFamily();
        $this->assertEquals($expected['limit'], $dialect->paginate($base, 10), "{$family}: só limite");
        $this->assertEquals($expected['both'], $dialect->paginate($base, 10, 20), "{$family}: limite e deslocamento");
        $this->assertEquals($expected['offset'], $dialect->paginate($base . ';', null, 20), "{$family}: só deslocamento");
        $this->assertEquals($base, $dialect->paginate($base, null), "{$family}: nada a aplicar");
    }
}
