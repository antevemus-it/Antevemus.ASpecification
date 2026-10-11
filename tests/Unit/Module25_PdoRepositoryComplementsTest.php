<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\DomainEvents\InMemoryDomainEventPublisher;
use Antevemus\ASpecification\DomainEvents\SpecificationTrigger;
use Antevemus\ASpecification\DomainEvents\State\InMemoryTriggerStateStore;
use Antevemus\ASpecification\DomainEvents\TriggeringRepository;
use Antevemus\ASpecification\DomainEvents\TriggerSet;
use Antevemus\ASpecification\DomainEvents\TriggerSweeper;
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Persistence\InMemoryQueryLogger;
use Antevemus\ASpecification\Persistence\IQueryLogger;
use Antevemus\ASpecification\Persistence\PdoTransactionManager;
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\EntityMappingException;
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\OptimisticLockException;
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\PdoRepositoryException;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\BooleanStorage;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\EntityMapping;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\InQuerySpecification;
use Antevemus\ASpecification\Repositories\Pdo\PdoRepository;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Linq\ALinqSpecificationVisitor;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\Exceptions\IncompatibleTypeException;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Tests\Support\PdoArticle;
use Antevemus\ASpecification\Tests\Support\PdoArticleStatus;
use Antevemus\ASpecification\Tests\Support\PdoAuditedNote;
use Antevemus\ASpecification\Tests\Support\PdoCounter;
use Antevemus\ASpecification\Tests\Support\PdoFlagDefaultRecord;
use Antevemus\ASpecification\Tests\Support\PdoFlagRecord;
use Antevemus\ASpecification\Tests\Support\PdoLocalEvent;
use Antevemus\ASpecification\Tests\Support\PdoNullParityRow;
use Antevemus\ASpecification\Tests\Support\PdoRegion;
use Antevemus\ASpecification\Tests\Support\PdoSale;
use Antevemus\ASpecification\Tests\Support\PdoSecretHolder;
use Antevemus\ASpecification\Tests\Support\PdoRecordingPdo;
use Antevemus\ASpecification\Tests\Support\PdoTag;
use Antevemus\ASpecification\Tests\Support\PdoTrashableDocument;
use Antevemus\ASpecification\Tests\Support\PdoTypedRecord;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use Antevemus\ASpecification\Tests\Support\ReadmeProduct;
use Antevemus\ASpecification\Tests\Support\ReadmeSale;
use Antevemus\ASpecification\Tests\Support\ReadmeTicket;
use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Util\UlidGenerator;
use Antevemus\ASpecification\Util\UuidV7Generator;
use Closure;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Module25Clock - Movable clock of the lifecycle tests.
 *
 * @version 1.13.0
 */
final class Module25Clock
{
    public function __construct(public DateTimeImmutable $now)
    {
    }

    /** @return Closure(): DateTimeImmutable */
    public function closure(): Closure
    {
        return fn(): DateTimeImmutable => $this->now;
    }
}

/**
 * Module25BrokenLogger - A logger that always throws (a broken log never changes a statement).
 *
 * @version 1.13.0
 */
final class Module25BrokenLogger implements IQueryLogger
{
    public int $calls = 0;

    public function log(string $sql, int $parameterCount, float $milliseconds, ?array $parameters): void
    {
        $this->calls++;
        throw new RuntimeException('log down');
    }
}

/**
 * Módulo 25 (forward 031, E1, v1.13.0): complementos do PdoRepository.
 *
 * - D1: porta de log de SQL (IQueryLogger, withQueryLogger()); valores dos parâmetros fora do log por
 *   padrão (RN-02), dentro só com logParameters: true; falha também é registrada; logger quebrado não muda
 *   o resultado.
 * - D2: booleano como inteiro por coluna e por mapeamento (atributo e builder), precedência coluna >
 *   mapeamento; a especificação compila 1/0 (no PostgreSQL `smallint = TRUE` é erro: o defeito que a
 *   opção corrige é mostrado lá); leitura aceita os dois formatos.
 * - D3: fuso por mapeamento e por repositório, precedência coluna > mapeamento > repositório > UTC, no
 *   valor gravado (lido cru do banco), no valor lido e no parâmetro de consulta.
 * - D4: #[CreatedAt]/#[UpdatedAt] com relógio injetável (truncado ao segundo); #[SoftDelete] sem e com
 *   propriedade, com e sem versão; withTrashed()/onlyTrashed() como visões; forceRemove(); ganchos
 *   before/after com aborto dentro da transação; EntityMapping::extract()/hydrate(); coerência com o
 *   TriggeringRepository (saída na remoção lógica, entrada no reviver) e com o TriggerSweeper (o
 *   removido logicamente some para a varredura e para o baseline).
 * - D5: insertAll() multi-linha sem SELECT, putAll() com um SELECT ... IN por lote, ids Auto em lote
 *   (RETURNING no PostgreSQL, uma linha por vez nos demais), lotes limitados pelo driver.
 * - D6 (E2): select() projeta sem hidratar; aggregate() com groupBy de várias chaves, count/sum/avg/min/
 *   max, NULL como no SQL (ignorado por SUM/AVG, soma de nada = null, grupo NULL primeiro), Decimal como
 *   texto canônico; updateWhere() com versão incrementada em toda linha tocada, #[UpdatedAt] carimbado,
 *   só linhas vivas, sem ganchos; Spec::inQuery() SQL × memória (uma execução por instância), escopo de
 *   soft delete da subconsulta, parâmetros das duas consultas, aninhada, outra conexão recusada, e a
 *   paridade de nulos do Módulo 22 estendida com folhas inQuery (memória = ALinq = simplify = repositório
 *   = SQL); updateWhere × putAll com 10 000 linhas no SQLite.
 * - D8: sem as opções, nada muda (o Módulo 22 inteiro segue verde; aqui, os padrões).
 * - RN-03: insertAll × putAll × inserção por linha com 10 000 entidades no SQLite, números impressos.
 * - RN-04: a suíte portável roda em PostgreSQL 17 e MySQL 8.4 com ASPEC_PDO_PGSQL_DSN /
 *   ASPEC_PDO_MYSQL_DSN (tests/Fixtures/pdo_repository/run_portability.sh); sem as variáveis, avisa e pula.
 *
 * Suíte própria (e não uma extensão do Módulo 22) pelo mesmo motivo do Módulo 24 frente ao 23: o
 * Módulo 22 continua provando, sem mudar uma asserção, que o 1.11/1.12 sem as opções se comporta igual
 * (D8); este concentra as tabelas e fixtures novas (complements.*.sql) e é onde as etapas E2 e E3 do
 * forward 031 crescem.
 *
 * @version    1.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class Module25_PdoRepositoryComplementsTest extends TestCase
{
    private const SCHEMA_DIR = __DIR__ . '/../Fixtures/pdo_repository';

    public function run(): void
    {
        $this->testMappingLifecycleAndDefaults();
        $this->testMappingValidation();
        $this->testExtractAndHydrate();
        $this->testReadme171And172BlocksAreTheSameInBothLanguages();

        if (!extension_loaded('pdo_sqlite')) {
            fwrite(STDOUT, "    [AVISO] pdo_sqlite ausente; o Módulo 25 roda só os testes de mapeamento.\n");
            return;
        }

        $sqlite = $this->sqlite();
        $this->runPortableSuite($sqlite, 'sqlite');

        // SQLite: forma dos comandos, padrões (D8) e RN-03
        $this->testStatementShapes();
        $this->testDefaultsChangeNothing();
        $this->testRn03Benchmark();
        $this->testAggregateAndSelectValidation();
        $this->testUpdateWhereValidation();
        $this->testInQueryOutsideRepository();
        $this->testSetBasedStatementShapes();
        $this->testUpdateWhereBenchmark();

        // README 17.1 e 17.2 (1.13.0)
        $this->testReadme171RunsAsWritten();
        $this->testReadme172RunsAsWritten();

        // RN-04
        foreach (['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL'] as $family => $label) {
            $pdo = $this->connectFromEnvironment($family);
            if ($pdo === null) {
                fwrite(STDOUT, "    [AVISO] ASPEC_PDO_" . strtoupper($family) . "_DSN não definido; RN-04 em {$label} pulado.\n");
                continue;
            }
            $this->loadSchema($pdo, $family);
            $this->runPortableSuite($pdo, $family);
            $this->testRn03Benchmark($pdo, $family);
            fwrite(STDOUT, "    [RN-04] {$label} " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ": suíte portável do 031/E1+E2 OK\n");
        }
    }

    private function runPortableSuite(PDO $pdo, string $family): void
    {
        $this->testQueryLogger($pdo, $family);
        $this->testBooleanStorage($pdo, $family);
        $this->testTimezoneDefaults($pdo, $family);
        $this->testTimestamps($pdo, $family);
        $this->testSoftDelete($pdo, $family);
        $this->testSoftDeleteWithVersion($pdo, $family);
        $this->testHooks($pdo, $family);
        $this->testInsertAllAndBatchedPutAll($pdo, $family);
        $this->testAutoIdentifiersInBatch($pdo, $family);
        $this->testSoftDeleteWithTriggersAndSweeper($pdo, $family);
        // E2 (D6)
        $this->testSelectProjection($pdo, $family);
        $this->testAggregates($pdo, $family);
        $this->testUpdateWhere($pdo, $family);
        $this->testInQuery($pdo, $family);
        $this->testInQueryNullParity($pdo, $family);
    }

    ///////////////////////////////////////////////////////////////////////////
    // Mapeamento (D2, D3, D4)
    ///////////////////////////////////////////////////////////////////////////

    private function testMappingLifecycleAndDefaults(): void
    {
        // Atributos = builder, em qualquer ordem de chamada
        $note = EntityMapping::fromAttributes(PdoAuditedNote::class);
        $noteBuilder = EntityMapping::for(PdoAuditedNote::class)
            ->table('aspec_note')
            ->softDelete('deleted_at')
            ->id('entityId', IdStrategy::Ulid, 'id')
            ->column('title')
            ->column('score')
            ->createdAt('createdAt')
            ->updatedAt('updatedAt');
        $this->assertTrue($note->equals($noteBuilder), 'D4: atributos e builder produzem o mesmo mapeamento (soft delete sem propriedade)');
        $this->assertEquals('created_at', $note->getCreatedAt()?->column);
        $this->assertTrue($note->getCreatedAt()?->isCreatedAt === true && $note->getUpdatedAt()?->isUpdatedAt === true);
        $this->assertEquals('', $note->getSoftDelete()?->property, 'coluna de soft delete sem propriedade');
        $this->assertEquals('deleted_at', $note->getSoftDelete()?->column);
        $this->assertTrue($note->hasSoftDelete());
        $this->assertFalse(isset($note->getColumns()['']), 'a coluna sem propriedade não está entre as mapeadas');

        $doc = EntityMapping::fromAttributes(PdoTrashableDocument::class);
        $docBuilder = EntityMapping::for(PdoTrashableDocument::class)
            ->table('aspec_trash_doc')
            ->updatedAt('updatedAt')
            ->softDelete('deleted_at', 'deletedAt')
            ->version('version', 'version')
            ->id('id')
            ->column('title');
        $this->assertTrue($doc->equals($docBuilder), 'D4: soft delete com propriedade, versão e DateTime mutável');
        $this->assertEquals('deletedAt', $doc->getSoftDelete()?->property);
        $this->assertEquals(DateTime::class, $doc->getUpdatedAt()?->dateClass);
        $this->assertTrue(EntityMapping::fromAttributes(PdoArticle::class)->getSoftDelete() === null);

        // D2: padrão no #[Table], coluna vence; o builder aplica o padrão a colunas já mapeadas
        $flags = EntityMapping::fromAttributes(PdoFlagDefaultRecord::class);
        $this->assertEquals(BooleanStorage::Integer, $flags->getBooleanStorage());
        $this->assertEquals(BooleanStorage::Integer, $flags->getColumn('active')?->booleanStorage, 'herda o padrão do mapeamento');
        $this->assertEquals(BooleanStorage::Native, $flags->getColumn('native')?->booleanStorage, 'a coluna vence o mapeamento');
        $late = EntityMapping::for(PdoFlagDefaultRecord::class)
            ->table('aspec_flag')
            ->id('id', IdStrategy::Assigned)
            ->column('active')
            ->column('native', booleanAs: BooleanStorage::Native)
            ->column('label');
        $this->assertEquals(BooleanStorage::Native, $late->getColumn('active')?->booleanStorage);
        $lateInteger = $late->booleanAs(BooleanStorage::Integer);
        $this->assertTrue($lateInteger->equals($flags), 'padrão definido depois das colunas = no #[Table]');
        $this->assertEquals(BooleanStorage::Native, $late->getColumn('active')?->booleanStorage, 'imutável');
        $this->assertEquals(BooleanStorage::Integer, EntityMapping::fromAttributes(PdoFlagRecord::class)->getColumn('active')?->booleanStorage);
        $this->assertEquals(BooleanStorage::Native, EntityMapping::fromAttributes(PdoFlagRecord::class)->getColumn('native')?->booleanStorage);

        // D3: fuso no #[Table], coluna vence; padrão tardio também se aplica
        $events = EntityMapping::fromAttributes(PdoLocalEvent::class);
        $this->assertEquals('America/Sao_Paulo', $events->getTimezone());
        $this->assertEquals('America/Sao_Paulo', $events->getColumn('happenedAt')?->timezone);
        $this->assertFalse((bool) $events->getColumn('happenedAt')?->timezoneDeclared);
        $this->assertEquals('UTC', $events->getColumn('utcAt')?->timezone, 'a coluna vence o mapeamento');
        $this->assertTrue((bool) $events->getColumn('utcAt')?->timezoneDeclared);
        $plain = EntityMapping::for(PdoLocalEvent::class)->table('aspec_local_event')->id('id', IdStrategy::Assigned)
            ->column('happenedAt')->column('utcAt', timezone: 'UTC')->column('day', type: ColumnType::Date);
        $this->assertEquals('UTC', $plain->getColumn('happenedAt')?->timezone, 'sem padrão: UTC');
        $this->assertTrue($plain->timezone('America/Sao_Paulo')->equals($events), 'padrão tardio = no #[Table]');
        $this->assertTrue($plain->getTimezone() === null, 'imutável');

        // Soft delete sem propriedade recebe o fuso do mapeamento
        $this->assertEquals('America/Sao_Paulo', $noteBuilder->timezone('America/Sao_Paulo')->getSoftDelete()?->timezone);
    }

    private function testMappingValidation(): void
    {
        $fail = fn(Closure $f, string $why) => $this->assertThrows(EntityMappingException::class, $f, $why);
        $note = EntityMapping::for(PdoAuditedNote::class)->table('aspec_note')->id('entityId', IdStrategy::Ulid, 'id');

        $fail(fn() => $note->createdAt('title'), 'carimbo exige coluna temporal');
        $fail(fn() => $note->updatedAt('score'), 'carimbo exige coluna temporal');
        $fail(fn() => $note->createdAt('createdAt')->createdAt('updatedAt'), 'um só carimbo de criação');
        $fail(fn() => $note->createdAt('createdAt')->updatedAt('createdAt'), 'uma propriedade, um papel');
        $fail(fn() => $note->createdAt('entityId'), 'o id não é carimbo');
        $fail(fn() => EntityMapping::for(PdoAuditedNote::class)->createdAt('createdAt')->id('createdAt'), 'carimbo não vira id');
        $fail(fn() => EntityMapping::for(PdoTrashableDocument::class)->updatedAt('updatedAt')->version('updatedAt'), 'carimbo não vira versão');
        $fail(fn() => $note->column('title')->softDelete('title'), 'coluna de soft delete já mapeada a outra propriedade');
        $fail(fn() => $note->softDelete('deleted_at')->column('title', 'deleted_at'), 'coluna mapeada sobre a de soft delete');
        $fail(fn() => $note->softDelete('deleted_at; DROP TABLE x'), 'RN-02: nome de coluna validado');
        $fail(fn() => $note->createdAt('createdAt', 'c"--'), 'RN-02: nome de coluna validado');
        $fail(fn() => $note->timezone('Mars/Olympus'), 'fuso desconhecido no mapeamento');
        $fail(fn() => EntityMapping::for(PdoTrashableDocument::class)->softDelete('deleted_at', 'deletedAt')->softDelete('x', 'updatedAt'), 'um só soft delete');
        $strict = new class extends AbstractEntity {
            public DateTimeImmutable $at;

            public function getEntityId(): mixed
            {
                return 1;
            }
        };
        $fail(fn() => EntityMapping::for(get_class($strict))->softDelete('at', 'at'), 'soft delete exige propriedade que aceite nulo');
        $this->assertEquals('at', EntityMapping::for(get_class($strict))->createdAt('at')->getCreatedAt()?->property, 'carimbo não exige nulo');

        if (extension_loaded('pdo_sqlite')) {
            $repo = PdoRepository::forEntity(new PDO('sqlite::memory:'), PdoArticle::class);
            $fail(fn() => $repo->withTrashed(), 'withTrashed() sem soft delete');
            $fail(fn() => $repo->onlyTrashed(), 'onlyTrashed() sem soft delete');
            $this->assertThrows(InvalidArgumentException::class, fn() => $repo->withTimezone('Mars/Olympus'));
            $this->assertThrows(InvalidArgumentException::class, fn() => $repo->withBatchSize(0));
        }
    }

    /** D4: extract()/hydrate() públicos (toArray/fromArray). */
    private function testExtractAndHydrate(): void
    {
        $mapping = EntityMapping::fromAttributes(PdoArticle::class);
        $article = new PdoArticle('Alpha', PdoArticleStatus::Published, 10, '9.90', true, new DateTimeImmutable('2026-01-02 10:00:00Z'), ['x', 'y'], 'n1');
        $row = $mapping->extract($article);
        $this->assertEquals(['id', 'title', 'status', 'views', 'price', 'published', 'published_at', 'tags', 'note'], array_keys($row), 'chaves = colunas, na ordem do mapeamento');
        $this->assertTrue($row['status'] === PdoArticleStatus::Published, 'valores PHP, não a forma gravada');
        $this->assertTrue($row['published_at'] instanceof DateTimeImmutable);
        $this->assertEquals($article->getEntityId(), $row['id']);

        $before = PdoArticle::$constructed;
        $copy = $mapping->hydrate($row);
        $this->assertEquals($before, PdoArticle::$constructed, 'hydrate() não chama o construtor');
        $this->assertTrue($copy instanceof PdoArticle && $copy !== $article);
        $this->assertEquals($this->snapshot($article), $this->snapshot($copy), 'hydrate(extract()) devolve o mesmo estado');
        $this->assertTrue($copy->getTimeOfCreation() instanceof DateTimeImmutable, 'timeOfCreation preenchido');

        $fromText = $mapping->hydrate([
            'id' => '01JABCDEFGHJKMNPQRSTVWXYZ0',
            'title' => 'Texto',
            'status' => 'archived',
            'views' => '42',
            'price' => '1.50',
            'published' => 't',
            'published_at' => '2026-01-02 10:00:00',
            'tags' => '["a","b"]',
            'note' => null,
            'not_a_column' => 'ignored',
        ]);
        $this->assertEquals(42, $fromText->getViews(), 'texto vira int');
        $this->assertTrue($fromText->getStatus() === PdoArticleStatus::Archived, 'valor vira caso do enum');
        $this->assertTrue($fromText->isPublished(), "'t' vira true");
        $this->assertEquals('2026-01-02T10:00:00+00:00', $fromText->getPublishedAt()?->format('c'), 'data no fuso da coluna');
        $this->assertEquals(['a', 'b'], $fromText->getTags(), 'JSON vira array');
        $this->assertTrue($fromText->getNote() === null);

        $this->assertThrows(InvalidArgumentException::class, fn() => $mapping->extract(new PdoCounter('x')), 'outra classe');
        $this->assertThrows(InvalidArgumentException::class, fn() => $mapping->hydrate(['published_at' => 'não é data']));
        $this->assertThrows(InvalidArgumentException::class, fn() => $mapping->hydrate(['views' => ['array']]));

        $typed = EntityMapping::fromAttributes(PdoTypedRecord::class)->hydrate([
            'id' => 'T',
            'moment_mut' => new DateTimeImmutable('2026-01-01 00:00:01Z'),
            'moment' => '2026-06-01 09:34:56',
            'f' => 3,
        ]);
        $this->assertTrue($typed->momentMut instanceof DateTime, 'data convertida para a classe da propriedade');
        $this->assertEquals('America/Sao_Paulo', $typed->moment?->getTimezone()->getName(), 'texto lido no fuso da coluna');
        $this->assertTrue($typed->f === 3.0, 'int em coluna float vira float');

        $events = EntityMapping::fromAttributes(PdoLocalEvent::class)->hydrate(['id' => 1, 'happened_at' => '2026-03-10 12:00:00']);
        $this->assertEquals('2026-03-10T15:00:00+00:00', $events->happenedAt?->setTimezone(new DateTimeZone('UTC'))->format('c'), 'hora local do mapeamento');
    }

    ///////////////////////////////////////////////////////////////////////////
    // D1: log de SQL
    ///////////////////////////////////////////////////////////////////////////

    private function testQueryLogger(PDO $pdo, string $family): void
    {
        $pdo->exec('DELETE FROM aspec_article');
        $logger = new InMemoryQueryLogger();
        $repo = PdoRepository::forEntity($pdo, PdoArticle::class);
        $this->assertTrue($repo->getQueryLogger() === null && !$repo->logsParameterValues(), 'D1: sem logger por padrão');
        $this->assertTrue($repo->withQueryLogger($logger) === $repo);
        $this->assertFalse($repo->logsParameterValues(), 'RN-02: valores fora do log por padrão');

        $secret = 'cpf 123.456.789-00';
        $repo->put(new PdoArticle('t1', note: $secret));
        $this->assertEquals(1, $repo->count(Spec::property('note', Spec::equal($secret))));
        $entries = $logger->entries();
        $this->assertTrue(count($entries) >= 3, "{$family}: SELECT de existência, INSERT e COUNT registrados");
        foreach ($entries as $entry) {
            $this->assertTrue($entry['parameters'] === null, "{$family}: sem valores");
            $this->assertTrue($entry['milliseconds'] >= 0.0);
            $this->assertEquals(substr_count($entry['sql'], ':'), $entry['parameterCount'], "{$family}: contagem de parâmetros = placeholders");
        }
        $this->assertFalse(str_contains((string) json_encode($entries, JSON_UNESCAPED_UNICODE), '123.456'), "{$family}: RN-02, o valor não aparece em lugar nenhum do log");
        $this->assertTrue(str_starts_with($logger->statements()[count($entries) - 1], 'SELECT COUNT(*) FROM'));

        $values = new InMemoryQueryLogger();
        $repo->withQueryLogger($values, logParameters: true);
        $this->assertTrue($repo->logsParameterValues());
        $repo->count(Spec::property('note', Spec::equal($secret)));
        $this->assertEquals([':p1' => $secret], $values->entries()[0]['parameters'], "{$family}: com logParameters os valores vão ao log");

        $repo->withQueryLogger(null);
        $logger->clear();
        $repo->count(Spec::alwaysTrue());
        $this->assertCount(0, $logger->entries(), 'logger removido');

        // A falha também é registrada; um logger quebrado não muda o resultado
        $failures = new InMemoryQueryLogger();
        $missing = (new PdoRepository($pdo, EntityMapping::for(PdoCounter::class)->table('aspec_missing')->id('id')->column('label')))->withQueryLogger($failures);
        $this->assertThrows(PdoRepositoryException::class, fn() => $missing->count(Spec::alwaysTrue()));
        $this->assertCount(1, $failures->entries(), "{$family}: o comando que falhou foi registrado");
        $broken = new Module25BrokenLogger();
        $repo->withQueryLogger($broken);
        $this->assertEquals(1, $repo->count(Spec::alwaysTrue()), 'logger quebrado não muda o resultado');
        $this->assertTrue($broken->calls > 0);
        $repo->withQueryLogger(null);
        $this->assertThrows(InvalidArgumentException::class, fn() => new InMemoryQueryLogger(0));
        $bounded = new InMemoryQueryLogger(2);
        foreach (['a', 'b', 'c'] as $sql) {
            $bounded->log($sql, 0, 0.0, null);
        }
        $this->assertEquals(['b', 'c'], $bounded->statements(), 'capacidade: os mais antigos saem');
    }

    ///////////////////////////////////////////////////////////////////////////
    // D2: booleano como inteiro
    ///////////////////////////////////////////////////////////////////////////

    private function testBooleanStorage(PDO $pdo, string $family): void
    {
        foreach ([
            'coluna' => PdoRepository::forEntity($pdo, PdoFlagRecord::class),
            '#[Table]' => PdoRepository::forEntity($pdo, PdoFlagDefaultRecord::class),
            'builder' => new PdoRepository($pdo, EntityMapping::for(PdoFlagRecord::class)->table('aspec_flag')->id('id', IdStrategy::Assigned)
                ->column('active')->column('native', booleanAs: BooleanStorage::Native)->column('label')->booleanAs(BooleanStorage::Integer)),
        ] as $how => $repo) {
            $pdo->exec('DELETE FROM aspec_flag');
            $class = $repo->getMapping()->getEntityClass();
            $repo->putAll([new $class(1, true, true), new $class(2, false, false), new $class(3, null, null)]);

            $this->assertEquals('1', (string) $this->raw($pdo, 'SELECT active FROM aspec_flag WHERE id = 1'), "{$family}/{$how}: true gravado como 1");
            $this->assertEquals('0', (string) $this->raw($pdo, 'SELECT active FROM aspec_flag WHERE id = 2'), "{$family}/{$how}: false gravado como 0");
            $native = $this->raw($pdo, 'SELECT native FROM aspec_flag WHERE id = 1');
            $this->assertTrue($family === 'pgsql' ? $native === true : (string) $native === '1', "{$family}/{$how}: a coluna Native segue o driver");

            $ids = fn($spec): array => array_map(fn(IEntity $e): int => $e->getEntityId(), $repo->findAll($spec));
            $this->assertEquals([1], $ids(Spec::property('active', Spec::isTrue())), "{$family}/{$how}: isTrue() compila 1");
            $this->assertEquals([2], $ids(Spec::property('active', Spec::isFalse())));
            $this->assertEquals([1], $ids(Spec::property('active', Spec::equal(true))));
            $this->assertEquals([2], $ids(Spec::property('active', Spec::notEqual(true))));
            $this->assertEquals([1, 2], $ids(Spec::property('active', Spec::in(true, false))));
            $this->assertEquals([3], $ids(Spec::property('active', Spec::isNull())));
            $this->assertEquals([1], $ids(Spec::property('native', Spec::isTrue())), "{$family}/{$how}: a coluna Native continua com o literal do dialeto");
            $this->assertEquals([1], $ids(Spec::property('active', Spec::isTrue())->and(Spec::property('native', Spec::isTrue()))), 'as duas na mesma árvore');

            $one = $repo->findById(1);
            $this->assertTrue($one->active === true && $one->native === true, "{$family}/{$how}: lidos como bool");
            $this->assertTrue($repo->findById(2)->active === false);
            $this->assertTrue($repo->findById(3)->active === null);
            $one->active = false;
            $repo->update($one);
            $this->assertEquals('0', (string) $this->raw($pdo, 'SELECT active FROM aspec_flag WHERE id = 1'), "{$family}/{$how}: UPDATE grava 0");
        }

        // Leitura aceita os dois formatos; a consulta de uma coluna SMALLINT como Native é o defeito que D2 corrige no PostgreSQL
        $pdo->exec('DELETE FROM aspec_flag');
        $pdo->exec('INSERT INTO aspec_flag (id, active, native) VALUES (7, 1, ' . ($family === 'pgsql' ? 'TRUE' : '1') . ')');
        $legacy = new PdoRepository($pdo, EntityMapping::for(PdoFlagRecord::class)->table('aspec_flag')->id('id', IdStrategy::Assigned)->column('active')->column('native'));
        $this->assertTrue($legacy->findById(7)?->active === true, "{$family}: SMALLINT lido como bool também por um mapeamento Native");
        if ($family === 'pgsql') {
            $this->assertThrows(PdoRepositoryException::class, fn() => $legacy->count(Spec::property('active', Spec::isTrue())), 'pgsql: smallint = TRUE é erro sem booleanAs: Integer');
        } else {
            $this->assertEquals(1, $legacy->count(Spec::property('active', Spec::isTrue())), "{$family}: Native já é 1/0");
        }
        $this->assertEquals(1, PdoRepository::forEntity($pdo, PdoFlagRecord::class)->count(Spec::property('active', Spec::isTrue())), "{$family}: com Integer a mesma consulta funciona");
    }

    ///////////////////////////////////////////////////////////////////////////
    // D3: fuso
    ///////////////////////////////////////////////////////////////////////////

    private function testTimezoneDefaults(PDO $pdo, string $family): void
    {
        $pdo->exec('DELETE FROM aspec_local_event');
        $instant = new DateTimeImmutable('2026-03-10 15:00:00', new DateTimeZone('UTC'));
        $tokyo = new DateTimeZone('Asia/Tokyo');

        // Mapeamento (#[Table(timezone:)]) e coluna (#[Column(timezone: 'UTC')])
        $byTable = PdoRepository::forEntity($pdo, PdoLocalEvent::class);
        $e1 = new PdoLocalEvent(1, $instant, $instant);
        $e1->day = new DateTimeImmutable('2026-03-10');
        $byTable->put($e1);
        $this->assertTrue(str_starts_with((string) $this->raw($pdo, 'SELECT happened_at FROM aspec_local_event WHERE id = 1'), '2026-03-10 12:00:00'), "{$family}: hora local do mapeamento gravada sem fuso");
        $this->assertTrue(str_starts_with((string) $this->raw($pdo, 'SELECT utc_at FROM aspec_local_event WHERE id = 1'), '2026-03-10 15:00:00'), "{$family}: a coluna vence o mapeamento");
        $read = $byTable->findById(1);
        $this->assertEquals('America/Sao_Paulo', $read->happenedAt?->getTimezone()->getName());
        $this->assertEquals($instant->getTimestamp(), $read->happenedAt?->getTimestamp(), "{$family}: mesmo instante na volta");
        $this->assertEquals('UTC', $read->utcAt?->getTimezone()->getName());
        $this->assertEquals('2026-03-10', $read->day?->format('Y-m-d'));
        $this->assertEquals(1, $byTable->count(Spec::property('happenedAt', Spec::equal($instant->setTimezone($tokyo)))), "{$family}: parâmetro convertido para o fuso do mapeamento");

        // O repositório não vence o mapeamento
        $byTable->withTimezone('Asia/Tokyo');
        $this->assertEquals('Asia/Tokyo', $byTable->getTimezone());
        $this->assertEquals('America/Sao_Paulo', $byTable->getMapping()->getColumn('happenedAt')?->timezone, 'mapeamento > repositório');
        $byTable->put(new PdoLocalEvent(2, $instant, $instant));
        $this->assertTrue(str_starts_with((string) $this->raw($pdo, 'SELECT happened_at FROM aspec_local_event WHERE id = 2'), '2026-03-10 12:00:00'));

        // Sem fuso no mapeamento: o do repositório; a coluna continua vencendo; null volta ao UTC
        $plainMapping = EntityMapping::for(PdoLocalEvent::class)->table('aspec_local_event')->id('id', IdStrategy::Assigned)
            ->column('happenedAt')->column('utcAt', timezone: 'UTC')->column('day', type: ColumnType::Date);
        $byRepo = (new PdoRepository($pdo, $plainMapping))->withTimezone('Asia/Tokyo');
        $this->assertEquals('Asia/Tokyo', $byRepo->getMapping()->getColumn('happenedAt')?->timezone, 'repositório > UTC');
        $this->assertEquals('UTC', $byRepo->getMapping()->getColumn('utcAt')?->timezone, 'coluna > repositório');
        $byRepo->put(new PdoLocalEvent(3, $instant, $instant));
        $this->assertTrue(str_starts_with((string) $this->raw($pdo, 'SELECT happened_at FROM aspec_local_event WHERE id = 3'), '2026-03-11 00:00:00'), "{$family}: hora de Tóquio");
        $this->assertTrue(str_starts_with((string) $this->raw($pdo, 'SELECT utc_at FROM aspec_local_event WHERE id = 3'), '2026-03-10 15:00:00'));
        $this->assertEquals('Asia/Tokyo', $byRepo->findById(3)?->happenedAt?->getTimezone()->getName());
        $this->assertEquals([3], array_map(fn($e) => $e->getEntityId(), $byRepo->findAll(Spec::property('happenedAt', Spec::equal($instant)))), "{$family}: consulta no fuso do repositório");
        $byRepo->withTimezone(null);
        $this->assertTrue($byRepo->getMapping() === $plainMapping, 'sem fuso de repositório, o mapeamento é o mesmo objeto');
        $byRepo->put(new PdoLocalEvent(4, $instant));
        $this->assertTrue(str_starts_with((string) $this->raw($pdo, 'SELECT happened_at FROM aspec_local_event WHERE id = 4'), '2026-03-10 15:00:00'), "{$family}: UTC por fim");
    }

    ///////////////////////////////////////////////////////////////////////////
    // D4: carimbos
    ///////////////////////////////////////////////////////////////////////////

    private function testTimestamps(PDO $pdo, string $family): void
    {
        $pdo->exec('DELETE FROM aspec_note');
        $clock = new Module25Clock(new DateTimeImmutable('2026-05-01 10:00:00.750000', new DateTimeZone('UTC')));
        $repo = PdoRepository::forEntity($pdo, PdoAuditedNote::class)->withClock($clock->closure());

        $n1 = new PdoAuditedNote('n1', 1);
        $repo->put($n1);
        $this->assertEquals('2026-05-01T10:00:00.000000+00:00', $n1->getCreatedAt()?->format('Y-m-d\TH:i:s.uP'), "{$family}: criado com o relógio, truncado ao segundo");
        $this->assertEquals($n1->getCreatedAt()?->format('U'), $n1->getUpdatedAt()?->format('U'), 'INSERT: alterado = criado');
        $this->assertTrue(str_starts_with((string) $this->raw($pdo, "SELECT created_at FROM aspec_note WHERE id = '{$n1->getEntityId()}'"), '2026-05-01 10:00:00'));
        $this->assertEquals($n1->getCreatedAt()?->format('c'), $repo->findById($n1->getEntityId())?->getCreatedAt()?->format('c'), "{$family}: o lido é o que a entidade tem");

        $imported = new PdoAuditedNote('imported', 2, new DateTimeImmutable('2020-01-01 08:00:00Z'));
        $repo->put($imported);
        $this->assertEquals('2020-01-01T08:00:00+00:00', $imported->getCreatedAt()?->format('c'), 'valor já presente é mantido');
        $this->assertEquals('2026-05-01T10:00:00+00:00', $imported->getUpdatedAt()?->format('c'));

        $clock->now = $clock->now->modify('+1 hour');
        $n1->title = 'n1 changed';
        $repo->update($n1);
        $this->assertEquals('2026-05-01T10:00:00+00:00', $n1->getCreatedAt()?->format('c'), 'UPDATE não mexe na criação');
        $this->assertEquals('2026-05-01T11:00:00+00:00', $n1->getUpdatedAt()?->format('c'), 'UPDATE carimba a alteração');
        $this->assertTrue(str_starts_with((string) $this->raw($pdo, "SELECT updated_at FROM aspec_note WHERE id = '{$n1->getEntityId()}'"), '2026-05-01 11:00:00'));

        // Upsert de um objeto novo com o id de uma linha existente: a criação gravada fica
        $fresh = $repo->getMapping()->hydrate(['id' => $n1->getEntityId(), 'title' => 'fresh', 'score' => 3]);
        $repo->put($fresh);
        $this->assertTrue(str_starts_with((string) $this->raw($pdo, "SELECT created_at FROM aspec_note WHERE id = '{$n1->getEntityId()}'"), '2026-05-01 10:00:00'), "{$family}: created_at nunca vai no UPDATE");
        $this->assertTrue($fresh->getCreatedAt() === null, 'e a entidade não é inventada');

        $clock->now = $clock->now->modify('+1 hour');
        $repo->updateWithDelta($imported, Spec::property('score', Spec::equal(20)));
        $this->assertEquals(20, $repo->findById($imported->getEntityId())?->score);
        $this->assertEquals('2026-05-01T12:00:00+00:00', $imported->getUpdatedAt()?->format('c'), 'updateWithDelta() carimba');

        $batch = [new PdoAuditedNote('b1'), new PdoAuditedNote('b2')];
        $repo->putAll($batch);
        $this->assertEquals($batch[0]->getCreatedAt()?->format('c'), $batch[1]->getCreatedAt()?->format('c'), 'um instante por chamada');

        $repo->withClock(null);
        $before = time();
        $now = new PdoAuditedNote('system');
        $repo->put($now);
        $this->assertTrue((int) $now->getCreatedAt()?->format('U') >= $before - 1 && (int) $now->getCreatedAt()?->format('U') <= time(), 'relógio do sistema por padrão');
        $repo->withClock(static fn(): string => 'yesterday');
        $this->assertThrows(RuntimeException::class, fn() => $repo->put(new PdoAuditedNote('bad clock')));
        $this->assertEquals(0, $repo->count(Spec::property('title', Spec::equal('bad clock'))));
    }

    ///////////////////////////////////////////////////////////////////////////
    // D4: soft delete
    ///////////////////////////////////////////////////////////////////////////

    private function testSoftDelete(PDO $pdo, string $family): void
    {
        $pdo->exec('DELETE FROM aspec_note');
        $clock = new Module25Clock(new DateTimeImmutable('2026-06-01 09:00:00Z'));
        $repo = PdoRepository::forEntity($pdo, PdoAuditedNote::class)->withClock($clock->closure());
        $all = Spec::alwaysTrue();
        $a = new PdoAuditedNote('a', 1);
        $b = new PdoAuditedNote('b', 5);
        $c = new PdoAuditedNote('c', 9);
        $repo->putAll([$a, $b, $c]);
        $titles = fn(iterable $notes): array => array_map(fn(PdoAuditedNote $n): string => $n->title, is_array($notes) ? $notes : iterator_to_array($notes, false));
        $rawRows = fn(): int => (int) $this->raw($pdo, 'SELECT COUNT(*) FROM aspec_note');
        $bId = $b->getEntityId();

        $clock->now = $clock->now->modify('+1 day');
        $this->assertTrue($repo->remove($b), "{$family}: remove() marca");
        $this->assertEquals(3, $rawRows(), "{$family}: a linha continua lá");
        $this->assertTrue(str_starts_with((string) $this->raw($pdo, "SELECT deleted_at FROM aspec_note WHERE id = '{$bId}'"), '2026-06-02 09:00:00'), "{$family}: com o instante do relógio");
        $this->assertTrue(str_starts_with((string) $this->raw($pdo, "SELECT updated_at FROM aspec_note WHERE id = '{$bId}'"), '2026-06-02 09:00:00'), 'e a alteração carimbada');
        $this->assertEquals('2026-06-02T09:00:00+00:00', $b->getUpdatedAt()?->format('c'));

        // Toda leitura deixa a marcada de fora
        $this->assertEquals(2, $repo->count($all));
        $this->assertEquals(['a', 'c'], $titles($repo->findAll($all)));
        $this->assertEquals(['a', 'c'], $titles($repo->iterate($all)));
        $this->assertTrue($repo->findById($bId) === null, 'findById()');
        $this->assertFalse($repo->contains($b), 'contains()');
        $this->assertTrue($repo->findSingle(Spec::property('score', Spec::equal(5))) === null, 'findSingle()');
        $this->assertEquals(['c', 'a'], $titles($repo->findPage($all, 5, 0, ['score' => 'DESC'])), 'findPage()');
        $this->assertEquals(1, $repo->count(Spec::property('score', Spec::greaterThan(1))));
        $this->assertFalse($repo->remove($b), 'remover de novo: false');

        // Visões
        $with = $repo->withTrashed();
        $only = $repo->onlyTrashed();
        $this->assertTrue($with !== $repo && $with->seesTrashed() && $only->seesTrashed() && !$repo->seesTrashed(), 'visões são cópias');
        $this->assertEquals(3, $with->count($all));
        $this->assertEquals('b', $with->findById($bId)?->title);
        $this->assertTrue($with->contains($b));
        $this->assertEquals(['b'], $titles($only->findAll($all)));
        $this->assertEquals(1, $only->count(Spec::property('score', Spec::greaterThan(1))));
        $this->assertEquals(2, $repo->count($all), 'o repositório original não mudou');
        $this->assertEquals(0, $only->removeBy($all), 'onlyTrashed(): remoção não alcança linha marcada');
        $this->assertFalse($only->remove($b));
        $markedAt = $this->raw($pdo, "SELECT deleted_at FROM aspec_note WHERE id = '{$bId}'");

        // removeBy marca só as vivas, um comando
        $clock->now = $clock->now->modify('+1 day');
        $this->assertEquals(1, $repo->removeBy(Spec::property('score', Spec::greaterThanOrEqualTo(9))), "{$family}: removeBy() marca");
        $this->assertEquals(0, $repo->removeBy(Spec::property('score', Spec::greaterThanOrEqualTo(9))), 'já marcada');
        $this->assertEquals(3, $rawRows());
        $this->assertEquals(1, $with->removeBy($all), 'withTrashed(): só as vivas são marcadas');
        $this->assertEquals($markedAt, $this->raw($pdo, "SELECT deleted_at FROM aspec_note WHERE id = '{$bId}'"), 'o instante de quem já estava marcada não muda');
        $this->assertEquals(0, $repo->count($all));

        // put() de uma marcada, sem versão: volta (como reinserir depois de um DELETE)
        $repo->put($b);
        $this->assertEquals(['b'], $titles($repo->findAll($all)), "{$family}: put() revive");
        $this->assertTrue($this->raw($pdo, "SELECT deleted_at FROM aspec_note WHERE id = '{$bId}'") === null);
        $this->assertTrue($b->getCreatedAt() !== null && str_starts_with((string) $this->raw($pdo, "SELECT created_at FROM aspec_note WHERE id = '{$bId}'"), '2026-06-01 09:00:00'), 'criação preservada');

        // Especificação avaliada em PHP (fallback): também marca
        $closure = new PredicateSpecification(fn(PdoAuditedNote $n): bool => $n->title === 'b');
        $this->assertEquals(1, $repo->withInMemoryFallback()->removeBy($closure));
        $repo->withInMemoryFallback(false);
        $this->assertEquals(3, $rawRows());
        $this->assertEquals(0, $repo->count($all));

        // forceRemove() apaga de verdade, marcada ou não
        $this->assertTrue($repo->forceRemove($c), "{$family}: forceRemove() de marcada");
        $this->assertEquals(2, $rawRows());
        $repo->put($a);
        $this->assertTrue($repo->forceRemove($a), 'forceRemove() de viva');
        $this->assertFalse($repo->forceRemove($a));
        $this->assertEquals(1, $rawRows());

        // insertAll() sobre o id de uma marcada falha na chave (sem SELECT de existência)
        $this->assertThrows(PdoRepositoryException::class, fn() => $repo->insertAll([$b]));
        $this->assertEquals(1, $rawRows());
    }

    private function testSoftDeleteWithVersion(PDO $pdo, string $family): void
    {
        $pdo->exec('DELETE FROM aspec_trash_doc');
        $clock = new Module25Clock(new DateTimeImmutable('2026-07-01 08:00:00Z'));
        $repo = PdoRepository::forEntity($pdo, PdoTrashableDocument::class)->withClock($clock->closure());

        $doc = new PdoTrashableDocument('d');
        $repo->put($doc);
        $this->assertEquals(1, $doc->getVersion());
        $this->assertTrue($doc->getDeletedAt() === null && $doc->getUpdatedAt() instanceof DateTime, "{$family}: DateTime mutável carimbado");
        $id = $doc->getEntityId();
        $stale = $repo->findById($id);

        $clock->now = $clock->now->modify('+1 hour');
        $this->assertTrue($repo->remove($doc));
        $this->assertEquals('2026-07-01T09:00:00+00:00', $doc->getDeletedAt()?->format('c'), "{$family}: propriedade de soft delete preenchida");
        $this->assertEquals(2, $doc->getVersion(), 'a marcação incrementa a versão');
        $this->assertEquals('2', (string) $this->raw($pdo, "SELECT version FROM aspec_trash_doc WHERE id = {$id}"));
        $this->assertFalse($repo->remove($stale), 'cópia velha de uma marcada: já removida, sem exceção');
        $gone = $this->assertThrows(OptimisticLockException::class, fn() => $repo->put($stale), 'versão sobre linha marcada não ressuscita');
        $this->assertTrue($gone->getActualVersion() === null);
        $this->assertThrows(OptimisticLockException::class, fn() => $repo->put($doc), 'nem a versão atual');
        $trashed = $repo->onlyTrashed()->findById($id);
        $this->assertEquals('2026-07-01T09:00:00+00:00', $trashed?->getDeletedAt()?->format('c'), "{$family}: hidratada com o instante");
        $this->assertEquals(2, $trashed?->getVersion());

        // Remoção de cópia velha de uma viva: exceção, nada marcado
        $live = new PdoTrashableDocument('live');
        $repo->put($live);
        $old = $repo->findById($live->getEntityId());
        $live->title = 'live v2';
        $repo->put($live);
        $lock = $this->assertThrows(OptimisticLockException::class, fn() => $repo->remove($old));
        $this->assertEquals(2, $lock->getActualVersion());
        $this->assertTrue($repo->contains($live), "{$family}: continua viva");

        $this->assertTrue($repo->forceRemove($trashed), 'forceRemove() da cópia atual de uma marcada');
        $this->assertEquals('0', (string) $this->raw($pdo, "SELECT COUNT(*) FROM aspec_trash_doc WHERE id = {$id}"));
    }

    ///////////////////////////////////////////////////////////////////////////
    // D4: ganchos
    ///////////////////////////////////////////////////////////////////////////

    private function testHooks(PDO $pdo, string $family): void
    {
        $pdo->exec('DELETE FROM aspec_note');
        $log = [];
        $repo = PdoRepository::forEntity($pdo, PdoAuditedNote::class)->withClock((new Module25Clock(new DateTimeImmutable('2026-08-01 00:00:00Z')))->closure());
        $seen = null;
        $repo->beforePut(function (PdoAuditedNote $n, PdoRepository $r) use (&$log, &$seen, $repo): void {
            $log[] = 'beforePut:' . $n->title . ':' . ($n->getCreatedAt() === null ? 'sem-carimbo' : 'carimbado');
            $seen = $r;
            if ($n->title === 'forbidden') {
                throw new RuntimeException('forbidden title');
            }
        })->afterPut(function (PdoAuditedNote $n) use (&$log): void {
            $log[] = 'afterPut:' . $n->title . ':' . ($n->getCreatedAt() === null ? 'sem-carimbo' : 'carimbado');
            if ($n->title === 'explode-after') {
                throw new RuntimeException('after failed');
            }
        })->beforeRemove(function (PdoAuditedNote $n) use (&$log): void {
            $log[] = 'beforeRemove:' . $n->title;
            if ($n->score === 666) {
                throw new RuntimeException('protected');
            }
        })->afterRemove(function (PdoAuditedNote $n) use (&$log): void {
            $log[] = 'afterRemove:' . $n->title;
        });

        $repo->put(new PdoAuditedNote('one', 1));
        $this->assertEquals(['beforePut:one:sem-carimbo', 'afterPut:one:carimbado'], $log, "{$family}: before antes do carimbo, after depois");
        $this->assertTrue($seen === $repo, 'o gancho recebe o repositório');

        $log = [];
        $repo->putAll([new PdoAuditedNote('two', 2), new PdoAuditedNote('three', 3)]);
        $this->assertEquals(['beforePut:two:sem-carimbo', 'beforePut:three:sem-carimbo', 'afterPut:two:carimbado', 'afterPut:three:carimbado'], $log, 'putAll(): before do lote, depois cada escrita com seu after');

        $count = $repo->count(Spec::alwaysTrue());
        $this->assertThrows(RuntimeException::class, fn() => $repo->put(new PdoAuditedNote('forbidden')));
        $this->assertThrows(RuntimeException::class, fn() => $repo->putAll([new PdoAuditedNote('ok-before'), new PdoAuditedNote('forbidden')]));
        $this->assertThrows(RuntimeException::class, fn() => $repo->insertAll([new PdoAuditedNote('ok-insert'), new PdoAuditedNote('forbidden')]));
        $this->assertThrows(RuntimeException::class, fn() => $repo->put(new PdoAuditedNote('explode-after')));
        $this->assertEquals($count, $repo->count(Spec::alwaysTrue()), "{$family}: exceção num gancho desfaz a escrita");
        $this->assertFalse($pdo->inTransaction());

        $log = [];
        $repo->insertAll([new PdoAuditedNote('i1'), new PdoAuditedNote('i2')]);
        $this->assertEquals(['beforePut:i1:sem-carimbo', 'beforePut:i2:sem-carimbo', 'afterPut:i1:carimbado', 'afterPut:i2:carimbado'], $log, 'insertAll(): ganchos de put');

        // Remoções
        $guarded = new PdoAuditedNote('guarded', 666);
        $repo->put($guarded);
        $this->assertThrows(RuntimeException::class, fn() => $repo->remove($guarded));
        $this->assertTrue($repo->contains($guarded), "{$family}: beforeRemove aborta");
        $log = [];
        $this->assertFalse($repo->remove(new PdoAuditedNote('never stored')));
        $this->assertEquals(['beforeRemove:never stored'], $log, 'afterRemove só quando removeu');

        $log = [];
        $this->assertEquals(2, $repo->removeBy(Spec::property('score', Spec::in(2, 3))), "{$family}: removeBy() com ganchos");
        sort($log);
        $this->assertEquals(['afterRemove:three', 'afterRemove:two', 'beforeRemove:three', 'beforeRemove:two'], $log, 'cada entidade passa pelos ganchos');
        $this->assertThrows(RuntimeException::class, fn() => $repo->removeBy(Spec::property('score', Spec::greaterThan(100))), 'uma protegida aborta o lote inteiro');
        $this->assertTrue($repo->contains($guarded));
        $this->assertTrue($repo->forceRemove($repo->findSingle(Spec::property('title', Spec::equal('one')))), 'forceRemove() com ganchos');

        // Dentro de uma transação de fora: a exceção do gancho chega a ela
        $tm = new PdoTransactionManager($pdo);
        $tx = new PdoRepository($pdo, $repo->getMapping(), $tm);
        $tx->beforePut(static function (PdoAuditedNote $n): void {
            if ($n->title === 'veto') {
                throw new RuntimeException('veto');
            }
        });
        try {
            $tm->transactional(function () use ($tx): void {
                $tx->put(new PdoAuditedNote('outer-1'));
                $tx->put(new PdoAuditedNote('veto'));
            });
        } catch (RuntimeException $e) {
            $this->assertEquals('veto', $e->getMessage());
        }
        $this->assertEquals(0, $tx->count(Spec::property('title', Spec::equal('outer-1'))), "{$family}: a transação de fora desfez tudo");
    }

    ///////////////////////////////////////////////////////////////////////////
    // D5: lotes
    ///////////////////////////////////////////////////////////////////////////

    private function testInsertAllAndBatchedPutAll(PDO $pdo, string $family): void
    {
        $pdo->exec('DELETE FROM aspec_article');
        $repo = PdoRepository::forEntity($pdo, PdoArticle::class)->withBatchSize(10);
        $this->assertEquals(10, $repo->getBatchSize());
        $articles = [];
        for ($i = 0; $i < 25; $i++) {
            $articles[] = new PdoArticle('ins ' . $i, PdoArticleStatus::Published, $i, '1.25', $i % 2 === 0, new DateTimeImmutable('2026-01-01 00:00:00Z'), ['t' . $i], $i % 3 === 0 ? null : 'n');
        }
        $this->assertEquals(25, $repo->insertAll($articles), "{$family}: insertAll() conta as linhas");
        $this->assertEquals(25, $repo->count(Spec::alwaysTrue()));
        $back = $repo->findById($articles[17]->getEntityId());
        $this->assertEquals($this->snapshot($articles[17]), $this->snapshot($back), "{$family}: ida e volta pelo multi-linha");
        $this->assertEquals(0, $repo->insertAll([]));

        $dup = [new PdoArticle('novo'), $articles[3]];
        $this->assertThrows(PdoRepositoryException::class, fn() => $repo->insertAll($dup), 'linha existente falha na chave');
        $this->assertEquals(25, $repo->count(Spec::alwaysTrue()), "{$family}: nada do insertAll() ficou");
        $this->assertFalse($pdo->inTransaction());

        // putAll(): upsert com um SELECT por lote, inclusive o mesmo id duas vezes
        $articles[0]->setTitle('upd 0');
        $articles[24]->setViews(999);
        $new = new PdoArticle('new one');
        $repo->putAll([$articles[0], $new, $articles[24], $new, $articles[0]]);
        $this->assertEquals(26, $repo->count(Spec::alwaysTrue()), "{$family}: duas atualizações, uma inserção");
        $this->assertEquals('upd 0', $repo->findById($articles[0]->getEntityId())?->getTitle());
        $this->assertEquals(999, $repo->findById($articles[24]->getEntityId())?->getViews());

        // Max: uma leitura por lote, acima de todo id que o lote já traz
        $pdo->exec('DELETE FROM aspec_counter_plain');
        $max = new PdoRepository($pdo, EntityMapping::for(PdoCounter::class)->table('aspec_counter_plain')->id('id', IdStrategy::Max)->column('label'));
        $batch = [new PdoCounter('m1'), new PdoCounter('m2'), new PdoCounter('preset', 3), new PdoCounter('m3'), new PdoCounter('m4')];
        $max->insertAll($batch);
        $ids = array_map(fn(PdoCounter $c): int => $c->getEntityId(), $batch);
        $this->assertEquals([4, 5, 3, 6, 7], $ids, "{$family}: Max em lote");
        $max->insertAll([new PdoCounter('m5')]);
        $this->assertEquals(8, $max->findSingle(Spec::property('label', Spec::equal('m5')))?->getEntityId());

        // Ulid / Uuid7 / Assigned
        $pdo->exec('DELETE FROM aspec_tag');
        $ulid = new PdoRepository($pdo, EntityMapping::for(PdoTag::class)->table('aspec_tag')->id('id', IdStrategy::Ulid)->column('name'));
        $tags = [new PdoTag('u1'), new PdoTag('u2')];
        $ulid->insertAll($tags);
        $this->assertTrue(UlidGenerator::isValid((string) $tags[0]->getEntityId()) && $tags[0]->getEntityId() !== $tags[1]->getEntityId());
        $uuid = new PdoRepository($pdo, EntityMapping::for(PdoTag::class)->table('aspec_tag')->id('id', IdStrategy::Uuid7)->column('name'));
        $more = [new PdoTag('v1')];
        $uuid->insertAll($more);
        $this->assertTrue(UuidV7Generator::isValid((string) $more[0]->getEntityId()));
        $assigned = new PdoRepository($pdo, EntityMapping::for(PdoCounter::class)->table('aspec_counter_plain')->id('id', IdStrategy::Assigned)->column('label'));
        $this->assertThrows(InvalidArgumentException::class, fn() => $assigned->insertAll([new PdoCounter('a', 100), new PdoCounter('no id')]));
        $this->assertTrue($assigned->findById(100) === null, "{$family}: nada inserido");
        $this->assertThrows(InvalidArgumentException::class, fn() => $repo->insertAll([new PdoCounter('wrong class')]));

        // Versão: 1 em cada linha nova
        $pdo->exec('DELETE FROM aspec_trash_doc');
        $docs = [new PdoTrashableDocument('x'), new PdoTrashableDocument('y')];
        PdoRepository::forEntity($pdo, PdoTrashableDocument::class)->insertAll($docs);
        $this->assertTrue($docs[0]->getVersion() === 1 && $docs[1]->getVersion() === 1 && $docs[1]->getUpdatedAt() !== null);
    }

    private function testAutoIdentifiersInBatch(PDO $pdo, string $family): void
    {
        $pdo->exec('DELETE FROM aspec_counter');
        $logger = new InMemoryQueryLogger();
        $repo = (new PdoRepository($pdo, EntityMapping::for(PdoCounter::class)->table('aspec_counter')->id('id')->column('label')))
            ->withBatchSize(3)
            ->withQueryLogger($logger);
        $counters = [];
        for ($i = 0; $i < 7; $i++) {
            $counters[] = new PdoCounter('auto-' . $i);
        }
        $counters[] = new PdoCounter('preset', 5000);
        $this->assertEquals(8, $repo->insertAll($counters));
        $statements = $logger->statements();
        $previous = 0;
        foreach (array_slice($counters, 0, 7) as $counter) {
            $this->assertTrue(is_int($counter->getEntityId()) && $counter->getEntityId() > $previous, "{$family}: ids gerados crescentes, na ordem das entidades");
            $previous = $counter->getEntityId();
            $this->assertEquals($counter->getLabel(), $repo->findById($counter->getEntityId())?->getLabel(), "{$family}: cada id é o da sua linha");
        }
        $this->assertEquals(5000, $counters[7]->getEntityId());
        $inserts = count(array_filter($statements, static fn(string $sql): bool => str_starts_with($sql, 'INSERT')));
        if ($family === 'pgsql') {
            $this->assertEquals(4, $inserts, 'pgsql: 3 INSERT ... RETURNING para 7 ids gerados + 1 do preset');
        } else {
            $this->assertEquals(8, $inserts, "{$family}: um INSERT por id gerado (sequência contígua não garantida) + 1 do preset");
        }
        $this->assertEquals(0, count(array_filter($statements, static fn(string $sql): bool => str_starts_with($sql, 'SELECT'))), "{$family}: insertAll() sem nenhum SELECT");
    }

    ///////////////////////////////////////////////////////////////////////////
    // D4: coerência com o TriggeringRepository e o TriggerSweeper
    ///////////////////////////////////////////////////////////////////////////

    private function testSoftDeleteWithTriggersAndSweeper(PDO $pdo, string $family): void
    {
        $pdo->exec('DELETE FROM aspec_note');
        $now = new DateTimeImmutable('2026-09-01 12:00:00Z');
        $inner = PdoRepository::forEntity($pdo, PdoAuditedNote::class)->withClock(static fn(): DateTimeImmutable => $now);
        $publisher = new InMemoryDomainEventPublisher();
        $states = new InMemoryTriggerStateStore();
        $triggers = new TriggerSet(new SpecificationTrigger('note.high', Spec::property('score', Spec::greaterThan(5)), onEnter: 'note.high.entered', onExit: 'note.high.left'));
        $notes = new TriggeringRepository($inner, $triggers, $publisher, $inner->getTransactionManager(), clock: static fn(): DateTimeImmutable => $now, states: $states);

        $n = new PdoAuditedNote('n', 9);
        $notes->put($n);
        $this->assertEquals(['note.high.entered'], $publisher->publishedNames(), "{$family}: entrada");
        $this->assertTrue($notes->remove($n), 'remoção lógica pelo decorador');
        $this->assertEquals(['note.high.entered', 'note.high.left'], $publisher->publishedNames(), "{$family}: a remoção lógica é uma saída");
        $this->assertEquals('1', (string) $this->raw($pdo, "SELECT COUNT(*) FROM aspec_note WHERE id = '{$n->getEntityId()}'"), 'e a linha continua lá');
        $this->assertEquals([], $states->findByEntity(PdoAuditedNote::class, (string) $n->getEntityId()), 'estado apagado como numa remoção');
        $this->assertFalse($notes->remove($n), 'remover de novo: nada');
        $this->assertCount(2, $publisher->published());

        $m = new PdoAuditedNote('m', 8);
        $notes->put($m);
        $publisher->drain();
        $this->assertEquals(1, $notes->removeBy(Spec::property('score', Spec::greaterThan(5))), 'removeBy() pelo decorador: só a viva');
        $this->assertEquals(['note.high.left'], $publisher->publishedNames(), "{$family}: saída só da viva (a marcada não é vítima)");
        $publisher->drain();
        $notes->put($n);
        $this->assertEquals(['note.high.entered'], $publisher->publishedNames(), "{$family}: reviver é uma entrada");

        // Varredor: a removida logicamente some para ele
        $pdo->exec('DELETE FROM aspec_note');
        $timed = new TriggerSet(SpecificationTrigger::timed('note.positive', static fn(DateTimeImmutable $at): \Antevemus\ASpecification\Contracts\ISpecification => Spec::property('score', Spec::greaterThan(0)), onEnter: 'note.positive.entered', onExit: 'note.positive.left'));
        $sweptStates = new InMemoryTriggerStateStore();
        $sweptEvents = new InMemoryDomainEventPublisher();
        $sweeper = new TriggerSweeper($inner, PdoAuditedNote::class, $timed, $sweptStates, $sweptEvents, $inner->getTransactionManager());
        $k1 = new PdoAuditedNote('k1', 3);
        $k2 = new PdoAuditedNote('k2', 4);
        $inner->putAll([$k1, $k2]);
        $baseline = $sweeper->baseline($now);
        $this->assertEquals(2, $baseline->recordedCount(), "{$family}: baseline registra as duas");
        $inner->remove($k1);   // direto no repositório, sem o decorador
        $report = $sweeper->sweep($now);
        $this->assertEquals(1, $report->exitedCount(), "{$family}: o varredor vê a marcada como removida");
        $this->assertEquals(['note.positive.left'], $sweptEvents->publishedNames());
        $this->assertEquals([], $sweptStates->findByEntity(PdoAuditedNote::class, (string) $k1->getEntityId()), 'estado da removida apagado');
        $this->assertEquals(0, $sweeper->sweep($now)->exitedCount(), 'idempotente');
        $inner->remove($k2);
        $again = $sweeper->baseline($now);
        $this->assertEquals([], $sweptStates->findByEntity(PdoAuditedNote::class, (string) $k2->getEntityId()), "{$family}: baseline apaga o estado da marcada");
        $this->assertCount(1, $sweptEvents->published(), 'baseline não publica');
        $this->assertEquals(0, $again->enteredCount() + $again->exitedCount());
    }

    ///////////////////////////////////////////////////////////////////////////
    // SQLite: forma dos comandos
    ///////////////////////////////////////////////////////////////////////////

    private function testStatementShapes(): void
    {
        $pdo = new PdoRecordingPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->loadSchema($pdo, 'sqlite');
        $logger = new InMemoryQueryLogger();
        $repo = PdoRepository::forEntity($pdo, PdoArticle::class)->withQueryLogger($logger);
        $count = fn(string $prefix): int => count(array_filter($pdo->statements, static fn(string $sql): bool => str_starts_with($sql, $prefix)));
        $make = static function (int $n, string $prefix): array {
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $out[] = new PdoArticle($prefix . $i, views: $i);
            }
            return $out;
        };

        $pdo->reset();
        $repo->putAll($make(2500, 'p'));
        $this->assertEquals(3, $count('SELECT'), 'D5: putAll() com um SELECT ... IN por lote de 1000');
        $this->assertTrue(str_contains($pdo->statements[0], '"id" IN (?, ?, ?'), 'SELECT ... IN');
        $this->assertEquals(2500, $count('INSERT'), 'putAll() escreve uma a uma (upsert)');
        $this->assertEquals(count($pdo->statements), count($logger->entries()), 'D1: o logger recebe todos os comandos');

        $pdo->reset();
        $this->assertEquals(2500, $repo->insertAll($make(2500, 'i')));
        $this->assertEquals(0, $count('SELECT'), 'D5: insertAll() sem SELECT de existência');
        $this->assertEquals(3, $count('INSERT'), 'insertAll(): 3 INSERT multi-linha (1000 linhas, abaixo de 32766 parâmetros)');
        $this->assertEquals(1000 * 9, substr_count($pdo->statements[0], '?'), '9 colunas × 1000 linhas, parâmetros posicionais');

        $pdo->reset();
        $small = $repo->withBatchSize(5000);
        $small->insertAll($make(4000, 's'));
        $this->assertEquals(2, $count('INSERT'), 'limite de parâmetros do SQLite: 32766 / 9 = 3640 linhas por comando');

        $pdo->reset();
        $repo->put($make(1, 'one')[0]);
        $this->assertEquals('SELECT "id" FROM "aspec_article" WHERE "id" = :id', $pdo->statements[0], 'put(): um SELECT por igualdade');

        // Soft delete: a condição entra em toda leitura; removeBy sem ganchos = um UPDATE
        $notes = PdoRepository::forEntity($pdo, PdoAuditedNote::class);
        $notes->putAll([new PdoAuditedNote('a', 1), new PdoAuditedNote('b', 2)]);
        $pdo->reset();
        $notes->insertAll([new PdoAuditedNote('z', 0)]);
        $this->assertEquals('INSERT INTO "aspec_note" ("id", "title", "score", "created_at", "updated_at", "deleted_at") VALUES (?, ?, ?, ?, ?, ?)', $pdo->statements[0], 'a marca sem propriedade vai NULL no INSERT');
        $pdo->reset();
        $notes->count(Spec::property('score', Spec::greaterThan(0)));
        $this->assertEquals('SELECT COUNT(*) FROM "aspec_note" WHERE (("score" IS NOT NULL AND "score" > :p1) AND "deleted_at" IS NULL)', $pdo->statements[0]);
        $pdo->reset();
        $notes->withTrashed()->count(Spec::alwaysTrue());
        $notes->onlyTrashed()->count(Spec::alwaysTrue());
        $this->assertEquals(['SELECT COUNT(*) FROM "aspec_note" WHERE 1 = 1', 'SELECT COUNT(*) FROM "aspec_note" WHERE (1 = 1 AND "deleted_at" IS NOT NULL)'], $pdo->statements);
        $pdo->reset();
        $notes->removeBy(Spec::property('score', Spec::equal(1)));
        $this->assertCount(1, $pdo->statements, 'removeBy() sem ganchos: um comando');
        $this->assertEquals('UPDATE "aspec_note" SET "deleted_at" = :lc_deleted_at, "updated_at" = :lc_updated_at WHERE (("score" IS NOT NULL AND "score" = :p1) AND "deleted_at" IS NULL)', $pdo->statements[0]);
        $notes->afterRemove(static function (): void {
        });
        $pdo->reset();
        $notes->removeBy(Spec::property('score', Spec::equal(2)));
        $this->assertCount(2, $pdo->statements, 'removeBy() com ganchos: SELECT + UPDATE por id');
        $this->assertTrue(str_contains($pdo->statements[1], '"id" IN (:d0) AND "deleted_at" IS NULL'));

        // D2: o literal booleano por coluna, no dialeto do PostgreSQL (só compila)
        $pg = new PdoRepository($pdo, EntityMapping::fromAttributes(PdoFlagRecord::class), dialect: 'pgsql');
        $this->assertEquals('(("active" IS NOT NULL AND "active" = 1) AND ("native" IS NOT NULL AND "native" = TRUE))', $pg->toWhereClause(Spec::property('active', Spec::isTrue())->and(Spec::property('native', Spec::isTrue())))->toSql());
        $this->assertEquals('("active" IS NOT NULL AND "active" IN (1, 0))', $pg->toWhereClause(Spec::property('active', Spec::in(true, false)))->toSql());
        $this->assertEquals('("active" IS NOT NULL AND "active" = 0)', $pg->toWhereClause(Spec::property('active', Spec::notEqual(true)))->toSql());
    }

    /** D8: sem as opções, os padrões são os do 1.12.0. */
    private function testDefaultsChangeNothing(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $mapping = EntityMapping::fromAttributes(PdoArticle::class);
        $repo = new PdoRepository($pdo, $mapping);
        $this->assertTrue($repo->getMapping() === $mapping, 'D8: o mapeamento é o mesmo objeto');
        $this->assertTrue($repo->getQueryLogger() === null);
        $this->assertTrue($repo->getTimezone() === null);
        $this->assertEquals(PdoRepository::DEFAULT_BATCH_SIZE, $repo->getBatchSize());
        $this->assertFalse($repo->seesTrashed());
        $this->assertEquals('UTC', EntityMapping::fromAttributes(PdoTypedRecord::class)->getColumn('b')?->timezone, 'UTC sem fuso declarado');
        $this->assertEquals(BooleanStorage::Native, $mapping->getColumn('published')?->booleanStorage, 'Native sem opção');
        $this->assertTrue($mapping->getCreatedAt() === null && $mapping->getUpdatedAt() === null && !$mapping->hasSoftDelete());
    }

    ///////////////////////////////////////////////////////////////////////////
    // RN-03
    ///////////////////////////////////////////////////////////////////////////

    /**
     * insertAll × putAll × put() por linha, 10 000 entidades. No SQLite em memória cada execução usa um
     * banco novo; em PostgreSQL/MySQL (RN-04) a mesma tabela, esvaziada antes de cada uma.
     *
     */
    private function testRn03Benchmark(?PDO $server = null, string $family = 'sqlite'): void
    {
        $rows = 10000;
        $make = static function (int $n, string $prefix): array {
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $out[] = new PdoArticle($prefix . $i, PdoArticleStatus::Published, $i, '1.00', $i % 2 === 0, new DateTimeImmutable('2026-01-01 00:00:00Z'), ['t' . ($i % 7)]);
            }
            return $out;
        };
        $results = [];
        foreach ([
            'insertAll' => fn(PdoRepository $r, array $e) => $r->insertAll($e),
            'putAll' => fn(PdoRepository $r, array $e) => $r->putAll($e),
            'put por linha, 1 transação' => fn(PdoRepository $r, array $e) => $r->getTransactionManager()->transactional(function () use ($r, $e): void {
                foreach ($e as $entity) {
                    $r->put($entity);
                }
            }),
            'put por linha, autocommit' => function (PdoRepository $r, array $e): void {
                foreach ($e as $entity) {
                    $r->put($entity);
                }
            },
        ] as $label => $write) {
            if ($server !== null && $label === 'put por linha, autocommit') {
                continue;   // um commit por linha em servidor leva minutos (medido uma vez: PostgreSQL 35 s, MySQL 71 s)
            }
            if ($server === null) {
                $pdo = new PDO('sqlite::memory:');
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->loadSchema($pdo, 'sqlite');
            } else {
                $pdo = $server;
                $pdo->exec('DELETE FROM aspec_article');
            }
            $logger = new InMemoryQueryLogger($rows * 3);
            $repo = PdoRepository::forEntity($pdo, PdoArticle::class)->withQueryLogger($logger);
            $entities = $make($rows, $label);
            gc_collect_cycles();
            $t = microtime(true);
            $write($repo, $entities);
            $ms = (microtime(true) - $t) * 1000;
            $statements = $logger->statements();
            $this->assertEquals($rows, $repo->count(Spec::alwaysTrue()), "RN-03 ({$family}): {$label} gravou tudo");
            $selects = count(array_filter($statements, static fn(string $s): bool => str_starts_with($s, 'SELECT')));
            $inserts = count(array_filter($statements, static fn(string $s): bool => str_starts_with($s, 'INSERT')));
            $results[$label] = ['ms' => $ms, 'selects' => $selects, 'inserts' => $inserts];
        }

        $this->assertEquals(['selects' => 0, 'inserts' => 10], array_slice($results['insertAll'], 1), "RN-03 ({$family}): insertAll = 10 INSERT, 0 SELECT");
        $this->assertEquals(['selects' => 10, 'inserts' => $rows], array_slice($results['putAll'], 1), "RN-03 ({$family}): putAll = 10 SELECT ... IN");
        $this->assertEquals(['selects' => $rows, 'inserts' => $rows], array_slice($results['put por linha, 1 transação'], 1), "RN-03 ({$family}): por linha = 1 SELECT por entidade (o putAll do 1.12)");
        $this->assertTrue($results['insertAll']['ms'] < $results['put por linha, 1 transação']['ms'], "RN-03 ({$family}): insertAll mais rápido que por linha");

        $parts = [];
        foreach ($results as $label => $r) {
            $parts[] = sprintf('%s %.0f ms (%d SELECT, %d INSERT)', $label, $r['ms'], $r['selects'], $r['inserts']);
        }
        fwrite(STDOUT, sprintf(
            "    [RN-03] %s, %d entidades: %s; insertAll %.1fx e putAll %.1fx mais rápidos que put por linha numa transação\n",
            $family,
            $rows,
            implode('; ', $parts),
            $results['put por linha, 1 transação']['ms'] / max($results['insertAll']['ms'], 0.001),
            $results['put por linha, 1 transação']['ms'] / max($results['putAll']['ms'], 0.001)
        ));
    }

    ///////////////////////////////////////////////////////////////////////////
    // E2 (D6): projeção, agregados, UPDATE em massa, subconsulta
    ///////////////////////////////////////////////////////////////////////////

    /**
     * As vendas da E2 (ids 1..7; a 6 é removida logicamente). Valores escolhidos para os agregados:
     * NULL em cada coluna, grupo de região NULL, decimais cuja soma em ponto flutuante não é exata.
     *
     * @return array{0: PdoRepository, 1: Module25Clock, 2: array<int, PdoSale>}
     */
    private function seedSales(PDO $pdo): array
    {
        $pdo->exec('DELETE FROM aspec_sale');
        $clock = new Module25Clock(new DateTimeImmutable('2026-08-01 10:00:00Z'));
        $sales = PdoRepository::forEntity($pdo, PdoSale::class)->withClock($clock->closure());
        $d = static fn(string $day): DateTimeImmutable => new DateTimeImmutable($day . ' 00:00:00Z');
        $rows = [
            1 => new PdoSale(1, 'north', 'x', 2, '10.50', 1.5, $d('2026-01-02')),
            2 => new PdoSale(2, 'north', 'y', 3, '0.20', null, $d('2026-01-05')),
            3 => new PdoSale(3, 'south', 'x', null, null, 2.0),
            4 => new PdoSale(4, null, 'z', 5, '9.90', null, $d('2026-02-01')),
            5 => new PdoSale(5, 'south', 'y', 4, '1.25', 0.5, $d('2026-01-03')),
            6 => new PdoSale(6, 'north', 'x', 1, '100.00', 3.25, $d('2026-03-01')),
            7 => new PdoSale(7, null, null),
        ];
        $sales->putAll(array_values($rows));
        $sales->remove($rows[6]);

        return [$sales, $clock, $rows];
    }

    /** select(): projeção sem hidratar, conversão por coluna, escopo de soft delete, fallback. */
    private function testSelectProjection(PDO $pdo, string $family): void
    {
        [$sales] = $this->seedSales($pdo);
        $spec = Spec::property('qty', Spec::greaterThan(1));
        $rows = iterator_to_array($sales->select($spec, ['id', 'region', 'amount', 'soldOn', 'weight', 'qty']), false);
        $this->assertEquals([1, 2, 4, 5], array_column($rows, 'id'), "{$family}: select() pelo identificador, só as vivas");
        $this->assertEquals(['id', 'region', 'amount', 'soldOn', 'weight', 'qty'], array_keys($rows[0]), 'chaves = propriedades pedidas, na ordem');
        $this->assertEquals(['north', 'north', null, 'south'], array_column($rows, 'region'));
        $this->assertEquals(['10.50', '0.20', '9.90', '1.25'], array_column($rows, 'amount'), "{$family}: Decimal lido como texto");
        $this->assertTrue($rows[0]['soldOn'] instanceof DateTimeImmutable && $rows[0]['soldOn']->format('Y-m-d') === '2026-01-02', "{$family}: Date lida como data");
        $this->assertTrue($rows[2]['soldOn'] instanceof DateTimeImmutable && $rows[1]['weight'] === null && $rows[0]['weight'] === 1.5, 'Float e NULL');
        $this->assertTrue($rows[0]['qty'] === 2 && $rows[0]['id'] === 1, 'Integer como int');
        $this->assertTrue(!is_object($rows[0]) && is_array($rows[0]), 'array, não entidade');

        $this->assertEquals([1, 2, 3, 4, 5, 6, 7], array_column(iterator_to_array($sales->withTrashed()->select(Spec::alwaysTrue(), ['id']), false), 'id'), "{$family}: withTrashed() vê a removida");
        $this->assertEquals([1, 2, 3, 4, 5, 7], array_column(iterator_to_array($sales->select(Spec::alwaysTrue(), ['id']), false), 'id'), "{$family}: sem a visão, não");
        $this->assertEquals([[ 'id' => 6, 'deletedAt' => true ]], array_map(static fn(array $r): array => ['id' => $r['id'], 'deletedAt' => $r['deletedAt'] instanceof DateTimeImmutable], iterator_to_array($sales->onlyTrashed()->select(Spec::alwaysTrue(), ['id', 'deletedAt']), false)), 'onlyTrashed(), a marca projetada');
        $this->assertEquals([], iterator_to_array($sales->select(Spec::property('qty', Spec::greaterThan(100)), ['id']), false), 'nada');
        $this->assertEquals([['id' => 3], ['id' => 7]], iterator_to_array($sales->select(Spec::property('qty', Spec::isNull()), ['id']), false), "{$family}: isNull() na projeção");

        // Fallback: a especificação avaliada em PHP projeta as entidades
        $closure = new PredicateSpecification(static fn(PdoSale $s): bool => $s->region === 'south');
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $sales->select($closure, ['id']), 'sem fallback: recusada na chamada');
        $memory = iterator_to_array($sales->withInMemoryFallback()->select($closure, ['id', 'amount']), false);
        $sales->withInMemoryFallback(false);
        $this->assertEquals([['id' => 3, 'amount' => null], ['id' => 5, 'amount' => '1.25']], $memory, "{$family}: fallback projeta em PHP");
    }

    /** aggregate(): agrupamentos, conversões, NULL, soft delete. */
    private function testAggregates(PDO $pdo, string $family): void
    {
        [$sales] = $this->seedSales($pdo);
        $all = Spec::alwaysTrue();
        $date = static fn(mixed $v): ?string => $v instanceof DateTimeImmutable ? $v->format('Y-m-d') : ($v === null ? null : 'not a date');

        $byRegion = $sales->aggregate($all)->groupBy('region')
            ->count()->count('qty')->sum('qty')->avg('qty')->sum('amount')->min('amount')->max('amount')
            ->sum('weight')->avg('weight')->min('soldOn')->max('soldOn', 'lastSale')->get();
        foreach ($byRegion as $i => $row) {
            $byRegion[$i]['min_soldOn'] = $date($row['min_soldOn']);
            $byRegion[$i]['lastSale'] = $date($row['lastSale']);
        }
        $this->assertEquals([
            ['region' => null, 'count' => 2, 'count_qty' => 1, 'sum_qty' => 5, 'avg_qty' => 5.0, 'sum_amount' => '9.9', 'min_amount' => '9.9', 'max_amount' => '9.9', 'sum_weight' => null, 'avg_weight' => null, 'min_soldOn' => '2026-02-01', 'lastSale' => '2026-02-01'],
            ['region' => 'north', 'count' => 2, 'count_qty' => 2, 'sum_qty' => 5, 'avg_qty' => 2.5, 'sum_amount' => '10.7', 'min_amount' => '0.2', 'max_amount' => '10.5', 'sum_weight' => 1.5, 'avg_weight' => 1.5, 'min_soldOn' => '2026-01-02', 'lastSale' => '2026-01-05'],
            ['region' => 'south', 'count' => 2, 'count_qty' => 1, 'sum_qty' => 4, 'avg_qty' => 4.0, 'sum_amount' => '1.25', 'min_amount' => '1.25', 'max_amount' => '1.25', 'sum_weight' => 2.5, 'avg_weight' => 1.25, 'min_soldOn' => '2026-01-03', 'lastSale' => '2026-01-03'],
        ], $byRegion, "{$family}: groupBy('region'), grupo NULL primeiro, NULL ignorado por SUM/AVG/MIN/MAX, sum de nada = null");

        // Dois níveis, NULL primeiro em cada chave
        $this->assertEquals([
            ['region' => null, 'product' => null, 'count' => 1, 'sum_qty' => null],
            ['region' => null, 'product' => 'z', 'count' => 1, 'sum_qty' => 5],
            ['region' => 'north', 'product' => 'x', 'count' => 1, 'sum_qty' => 2],
            ['region' => 'north', 'product' => 'y', 'count' => 1, 'sum_qty' => 3],
            ['region' => 'south', 'product' => 'x', 'count' => 1, 'sum_qty' => null],
            ['region' => 'south', 'product' => 'y', 'count' => 1, 'sum_qty' => 4],
        ], $sales->aggregate($all)->groupBy('region', 'product')->count()->sum('qty')->get(), "{$family}: groupBy com duas chaves");

        // Sem groupBy: uma linha; seleção vazia: count 0 e o resto null; com groupBy: nenhuma linha
        $this->assertEquals([['count' => 6, 'count_qty' => 4, 'sum_qty' => 14, 'avg_qty' => 3.5, 'sum_amount' => '21.85', 'sum_weight' => 4.0]],
            $sales->aggregate($all)->count()->count('qty')->sum('qty')->avg('qty')->sum('amount')->sum('weight')->get(), "{$family}: total");
        $none = Spec::property('qty', Spec::greaterThan(100));
        $this->assertEquals([['count' => 0, 'sum_qty' => null, 'avg_amount' => null, 'max_soldOn' => null]],
            $sales->aggregate($none)->count()->sum('qty')->avg('amount')->max('soldOn')->get(), "{$family}: seleção vazia sem groupBy");
        $this->assertEquals([], $sales->aggregate($none)->groupBy('region')->count()->get(), 'seleção vazia com groupBy');
        $this->assertEquals([['region' => null], ['region' => 'north'], ['region' => 'south']], $sales->aggregate($all)->groupBy('region')->get(), 'só groupBy: os grupos');

        // Filtro e escopo de soft delete
        $this->assertEquals([['region' => 'north', 'n' => 1, 'total' => '10.5']], $sales->aggregate(Spec::property('qty', Spec::lessThan(3))->and(Spec::property('region', Spec::isNotNull())))->groupBy('region')->count(null, 'n')->sum('amount', 'total')->get(), "{$family}: filtro + alias");
        $this->assertEquals([['count' => 7, 'sum_amount' => '121.85']], $sales->withTrashed()->aggregate($all)->count()->sum('amount')->get(), "{$family}: withTrashed() soma a removida");
        $this->assertEquals([['region' => 'north', 'sum_qty' => 1]], $sales->onlyTrashed()->aggregate($all)->groupBy('region')->sum('qty')->get(), 'onlyTrashed()');

        // A consulta é imutável
        $base = $sales->aggregate($all)->count();
        $grouped = $base->groupBy('region');
        $this->assertEquals([['count' => 6]], $base->get(), 'groupBy() devolve outra consulta');
        $this->assertCount(3, $grouped->get());

        // Não traduzível: recusada mesmo com fallback
        $closure = new PredicateSpecification(static fn(PdoSale $s): bool => true);
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $sales->withInMemoryFallback()->aggregate($closure), "{$family}: agregado é do banco");
        $sales->withInMemoryFallback(false);
    }

    /** Validações do agregado e da projeção (sem banco além do SQLite). */
    private function testAggregateAndSelectValidation(): void
    {
        $pdo = $this->sqlite();
        $sales = PdoRepository::forEntity($pdo, PdoSale::class);
        $articles = PdoRepository::forEntity($pdo, PdoArticle::class);
        $q = $sales->aggregate(Spec::alwaysTrue());
        $this->assertThrows(InvalidArgumentException::class, fn() => $q->sum('region'), 'sum de texto');
        $this->assertThrows(InvalidArgumentException::class, fn() => $q->avg('soldOn'), 'avg de data');
        $this->assertThrows(InvalidArgumentException::class, fn() => $q->sum('nope'), 'propriedade inexistente');
        $this->assertThrows(InvalidArgumentException::class, fn() => $q->count()->count(), 'chave repetida');
        $this->assertThrows(InvalidArgumentException::class, fn() => $q->groupBy('region')->sum('qty', 'region'), 'alias = chave de grupo');
        $this->assertThrows(InvalidArgumentException::class, fn() => $q->groupBy('region', 'region'), 'grupo repetido');
        $this->assertThrows(InvalidArgumentException::class, fn() => $q->get(), 'nada a calcular');
        $this->assertThrows(InvalidArgumentException::class, fn() => $q->groupBy(), 'groupBy() vazio');
        $aq = $articles->aggregate(Spec::alwaysTrue());
        $this->assertThrows(InvalidArgumentException::class, fn() => $aq->min('published'), 'min de Boolean');
        $this->assertThrows(InvalidArgumentException::class, fn() => $aq->max('tags'), 'max de Json');
        $this->assertThrows(InvalidArgumentException::class, fn() => $aq->groupBy('tags'), 'groupBy de Json');
        $this->assertEquals([['count_tags' => 0, 'min_status' => null]], $aq->count('tags')->min('status')->get(), 'count de Json e min de Enum valem');

        // Legibilidade: a mesma regra das especificações (propriedade privada sem getter)
        $secrets = PdoRepository::forEntity($pdo, PdoSecretHolder::class);
        $hidden = 'secret';   // privada, sem getter nem #[Specifiable]
        $this->assertEquals([], iterator_to_array($secrets->select(Spec::alwaysTrue(), ['marked']), false), 'a #[Specifiable] é legível');
        $this->assertThrows(InvalidArgumentException::class, fn() => $secrets->select(Spec::alwaysTrue(), [$hidden]), 'select() recusa propriedade não legível');
        $this->assertThrows(InvalidArgumentException::class, fn() => $secrets->aggregate(Spec::alwaysTrue())->max($hidden), 'agregado recusa propriedade não legível');
        $this->assertThrows(InvalidArgumentException::class, fn() => $secrets->aggregate(Spec::alwaysTrue())->groupBy($hidden), 'groupBy recusa propriedade não legível');
        $this->assertThrows(InvalidArgumentException::class, fn() => $sales->select(Spec::alwaysTrue(), []), 'select() sem propriedades');
        $this->assertThrows(InvalidArgumentException::class, fn() => $sales->select(Spec::alwaysTrue(), ['id', 'id']), 'select() repetida');
    }

    /** updateWhere(): UPDATE em massa, versão, UpdatedAt, soft delete, sem ganchos. */
    private function testUpdateWhere(PDO $pdo, string $family): void
    {
        [$sales, $clock, $rows] = $this->seedSales($pdo);
        $north = Spec::property('region', Spec::equalTo('north'));
        $stale = $sales->findById(1);
        $this->assertEquals(1, $stale?->getVersion());
        $versionOf6 = $this->raw($pdo, 'SELECT version FROM aspec_sale WHERE id = 6');

        $clock->now = new DateTimeImmutable('2026-08-02 11:30:00Z');
        $this->assertEquals(2, $sales->updateWhere($north, ['product' => 'w', 'amount' => '2.50', 'soldOn' => new DateTimeImmutable('2026-09-09 00:00:00Z'), 'qty' => 7]), "{$family}: updateWhere() conta as vivas tocadas");
        $one = $sales->findById(1);
        $this->assertEquals(['w', '2.50', '2026-09-09', 7, 2], [$one?->product, $one?->amount, $one?->soldOn?->format('Y-m-d'), $one?->qty, $one?->getVersion()], "{$family}: valores convertidos pelo tipo, versão incrementada");
        $this->assertEquals('2026-08-02 11:30:00', substr((string) $this->raw($pdo, 'SELECT updated_at FROM aspec_sale WHERE id = 2'), 0, 19), "{$family}: #[UpdatedAt] carimbado");
        $this->assertEquals(2, (int) $this->raw($pdo, 'SELECT version FROM aspec_sale WHERE id = 2'), 'versão de cada linha tocada');
        $this->assertEquals('x', $this->raw($pdo, 'SELECT product FROM aspec_sale WHERE id = 6'), "{$family}: a removida logicamente não é tocada");
        $this->assertEquals((int) $versionOf6, (int) $this->raw($pdo, 'SELECT version FROM aspec_sale WHERE id = 6'));
        $this->assertEquals('2026-08-01 10:00:00', substr((string) $this->raw($pdo, 'SELECT updated_at FROM aspec_sale WHERE id = 6'), 0, 19), 'nem carimbada');
        $this->assertEquals(1, (int) $this->raw($pdo, 'SELECT version FROM aspec_sale WHERE id = 5'), 'fora do filtro: intacta');
        $stale->product = 'stale';
        $this->assertThrows(OptimisticLockException::class, fn() => $sales->put($stale), "{$family}: cópia carregada antes ficou velha");

        // NULL como valor; filtro que casa NULL; nenhum gancho roda
        $hooked = PdoRepository::forEntity($pdo, PdoSale::class)->withClock($clock->closure())
            ->beforePut(static function (): void {
                throw new RuntimeException('hook ran');
            })->afterPut(static function (): void {
                throw new RuntimeException('hook ran');
            });
        $this->assertEquals(2, $hooked->updateWhere(Spec::property('region', Spec::isNull()), ['region' => 'west', 'weight' => null]), "{$family}: sem ganchos por entidade");
        $this->assertThrows(RuntimeException::class, fn() => $hooked->put(new PdoSale(99, 'x')), 'controle: put() roda o gancho');
        $this->assertEquals([4, 7], array_column(iterator_to_array($sales->select(Spec::property('region', Spec::equalTo('west')), ['id']), false), 'id'));

        // Visões: só as vivas
        $this->assertEquals(0, $sales->onlyTrashed()->updateWhere(Spec::alwaysTrue(), ['product' => 'q']), 'onlyTrashed(): nenhuma');
        $this->assertEquals(6, $sales->withTrashed()->updateWhere(Spec::alwaysTrue(), ['product' => 'q']), "{$family}: withTrashed(): só as vivas");
        $this->assertEquals('x', $this->raw($pdo, 'SELECT product FROM aspec_sale WHERE id = 6'));

        // Fallback: avaliada em PHP, atualiza por identificador
        $closure = new PredicateSpecification(static fn(PdoSale $s): bool => $s->id % 2 === 1);
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $sales->updateWhere($closure, ['product' => 'odd']));
        $this->assertEquals(4, $sales->withInMemoryFallback()->updateWhere($closure, ['product' => 'odd']), "{$family}: fallback (1, 3, 5, 7; a 6 não é viva)");
        $sales->withInMemoryFallback(false);
        $this->assertEquals([1, 3, 5, 7], array_column(iterator_to_array($sales->select(Spec::property('product', Spec::equalTo('odd')), ['id']), false), 'id'));
        $this->assertEquals(4, (int) $this->raw($pdo, 'SELECT version FROM aspec_sale WHERE id = 1'), 'versão: 1 (put) + 3 updateWhere');

        // Sem versão nem UpdatedAt (aspec_null_parity): só os valores
        $pdo->exec('DELETE FROM aspec_null_parity');
        $plain = PdoRepository::forEntity($pdo, PdoNullParityRow::class);
        $plain->putAll([new PdoNullParityRow(1, 1, null, 'a'), new PdoNullParityRow(2, 2, null, 'b')]);
        $this->assertEquals(1, $plain->updateWhere(Spec::property('n', Spec::greaterThan(1)), ['m' => 5, 's' => 'z']));
        $this->assertEquals([[1, null, 'a'], [2, 5, 'z']], array_map(static fn(array $r): array => array_values($r), iterator_to_array($plain->select(Spec::alwaysTrue(), ['id', 'm', 's']), false)), "{$family}: tabela sem ciclo de vida");
    }

    /** Validações de updateWhere(): recusadas antes de qualquer comando. */
    private function testUpdateWhereValidation(): void
    {
        $pdo = new PdoRecordingPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->loadSchema($pdo, 'sqlite');
        $sales = PdoRepository::forEntity($pdo, PdoSale::class);
        $all = Spec::alwaysTrue();
        $pdo->reset();
        foreach ([
            'vazio' => [],
            'id' => ['id' => 9],
            'versão' => ['version' => 3],
            'UpdatedAt' => ['updatedAt' => new DateTimeImmutable()],
            'SoftDelete' => ['deletedAt' => null],
            'inexistente' => ['nope' => 1],
            'tipo' => ['qty' => 'many'],
            'posicional' => [0 => 'x'],
        ] as $label => $values) {
            $this->assertThrows(InvalidArgumentException::class, fn() => $sales->updateWhere($all, $values), "updateWhere() recusa: {$label}");
        }
        $this->assertEquals([], $pdo->statements, 'nenhum comando enviado');
        $notes = PdoRepository::forEntity($pdo, PdoAuditedNote::class);
        $this->assertThrows(InvalidArgumentException::class, fn() => $notes->updateWhere($all, ['createdAt' => new DateTimeImmutable()]), 'CreatedAt');
    }

    /** Spec::inQuery(): SQL × memória, escopo, parâmetros das duas consultas, conexões. */
    private function testInQuery(PDO $pdo, string $family): void
    {
        [$sales] = $this->seedSales($pdo);
        $pdo->exec('DELETE FROM aspec_region');
        $regions = PdoRepository::forEntity($pdo, PdoRegion::class);
        $regions->putAll([new PdoRegion('north', 'a'), new PdoRegion('south', 'b'), new PdoRegion('east', null), $west = new PdoRegion('west', 'a')]);
        $regions->remove($west);
        $sales->put(new PdoSale(8, 'west', 'x', 6, '3.00'));
        $all = Spec::alwaysTrue();
        $live = $sales->find($all);
        $ids = static fn(array $entities): array => array_map(static fn(PdoSale $s): int => $s->id, $entities);
        $memory = static fn(ISpecification $spec): array => array_values(array_map(
            static fn(PdoSale $s): int => $s->id,
            array_filter($live, static fn(PdoSale $s): bool => $spec->isSatisfiedBy($s))
        ));

        $zoneA = Spec::property('zone', Spec::equalTo('a'));
        $cases = [
            'zona a (west removida)' => [Spec::inQuery('region', $regions, $zoneA, 'code'), [1, 2]],
            'withTrashed() na subconsulta' => [Spec::inQuery('region', $regions->withTrashed(), $zoneA, 'code'), [1, 2, 8]],
            'onlyTrashed() na subconsulta' => [Spec::inQuery('region', $regions->onlyTrashed(), $all, 'code'), [8]],
            'NOT: NULL não entra' => [Spec::not(Spec::inQuery('region', $regions, $zoneA, 'code')), [3, 4, 5, 7, 8]],
            'NOT dentro da propriedade' => [Spec::property('region', Spec::not(new InQuerySpecification($regions, $zoneA, 'code'))), [3, 5, 8]],
            'zona NULL na subconsulta' => [Spec::inQuery('region', $regions, Spec::property('zone', Spec::isNull()), 'code'), []],
            'parâmetros das duas consultas' => [Spec::property('qty', Spec::greaterThan(2))->and(Spec::inQuery('region', $regions, Spec::property('zone', Spec::in('a', 'b')), 'code')), [2, 5]],
            'aninhada' => [Spec::inQuery('region', $regions, Spec::inQuery('code', $sales, Spec::property('qty', Spec::greaterThan(3)), 'region'), 'code'), [3, 5]],
            'duas subconsultas' => [Spec::inQuery('region', $regions, $zoneA, 'code')->or(Spec::inQuery('region', $regions, Spec::property('zone', Spec::equalTo('b')), 'code')), [1, 2, 3, 5]],
            'subconsulta na própria tabela' => [Spec::inQuery('id', $sales, Spec::property('qty', Spec::greaterThanOrEqualTo(4)), 'qty'), [4, 5]],
        ];
        foreach ($cases as $label => [$spec, $expected]) {
            $this->assertEquals($expected, $ids($sales->find($spec)), "{$family}: inQuery SQL — {$label}");
            $this->assertEquals($expected, $memory($spec), "{$family}: inQuery memória — {$label}");
            $this->assertEquals(count($expected), $sales->count($spec), "{$family}: count — {$label}");
        }

        // Uma execução por instância em memória
        $leaf = new InQuerySpecification($regions, $zoneA, 'code');
        foreach ($live as $sale) {
            Spec::property('region', $leaf)->isSatisfiedBy($sale);
        }
        $this->assertEquals(1, $leaf->getExecutionCount(), "{$family}: a subconsulta roda uma vez por instância");

        // Nas outras operações
        $inA = Spec::inQuery('region', $regions, $zoneA, 'code');
        $this->assertEquals([['id' => 1], ['id' => 2]], iterator_to_array($sales->select($inA, ['id']), false), "{$family}: select() com subconsulta");
        $this->assertEquals([['region' => 'north', 'count' => 2]], $sales->aggregate($inA)->groupBy('region')->count()->get(), "{$family}: aggregate() com subconsulta");
        $this->assertEquals(2, $sales->updateWhere($inA, ['product' => 'in-a']), "{$family}: updateWhere() com subconsulta de outra tabela");
        $this->assertEquals(1, $regions->removeBy(Spec::not(Spec::inQuery('code', $sales, $all, 'region'))), "{$family}: removeBy() com subconsulta (east não vendeu)");
        $this->assertEquals(['north', 'south'], array_column(iterator_to_array($regions->select($all, ['code']), false), 'code'));

        // Outra conexão: não traduzível; com fallback, avaliada em PHP
        $elsewhere = new PDO('sqlite::memory:');
        $elsewhere->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $elsewhere->exec('CREATE TABLE aspec_region (code VARCHAR(10) NOT NULL PRIMARY KEY, zone VARCHAR(10) NULL, deleted_at TEXT NULL)');
        $remote = PdoRepository::forEntity($elsewhere, PdoRegion::class);
        $remote->putAll([new PdoRegion('south', 'a')]);
        $far = Spec::inQuery('region', $remote, $zoneA, 'code');
        $e = $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $sales->find($far), "{$family}: outra conexão recusada");
        $this->assertTrue(str_contains($e->getMessage(), 'another connection'), 'mensagem clara');
        $this->assertEquals([3, 5], $ids($sales->withInMemoryFallback()->find($far)), "{$family}: com fallback, em PHP");
        $sales->withInMemoryFallback(false);
        $foreign = new PdoRepository($pdo, EntityMapping::fromAttributes(PdoSale::class), dialect: $family === 'mysql' ? 'pgsql' : 'mysql');
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $foreign->toWhereClause($inA), "{$family}: outro dialeto recusado");
    }

    /** inQuery fora de um PdoRepository; tipos; igualdade estrutural. */
    private function testInQueryOutsideRepository(): void
    {
        $pdo = $this->sqlite();
        $regions = PdoRepository::forEntity($pdo, PdoRegion::class);
        $regions->putAll([new PdoRegion('north', 'a')]);
        $spec = Spec::inQuery('region', $regions, Spec::alwaysTrue(), 'code');
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => Spec::toSql($spec, 'sqlite'), 'Spec::toSql() não traduz');
        $this->assertThrows(\Antevemus\ASpecification\Criteria\Exceptions\NonTranslatableCriteriaException::class, fn() => Spec::toCriteria($spec), 'TCriteria não traduz');
        $predicate = ALinqSpecificationVisitor::createPredicate($spec);
        $this->assertTrue($predicate(new PdoSale(1, 'north')) && !$predicate(new PdoSale(2, 'south')) && !$predicate(new PdoSale(3, null)), 'ALinq avalia em PHP');
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::inQuery('region', $regions, Spec::alwaysTrue(), 'nope'), 'propriedade da subconsulta inexistente');
        $this->assertThrows(IncompatibleTypeException::class, fn() => Spec::inQuery('qty', $regions, Spec::alwaysTrue(), 'code')->isSatisfiedBy(new PdoSale(1, 'north', null, 5)), 'int contra textos: erro de tipo, nunca falso silencioso');
        $this->assertTrue(Spec::inQuery('qty', $regions, Spec::property('zone', Spec::equalTo('none')), 'code')->isSatisfiedBy(new PdoSale(1, 'north', null, 5)) === false, 'conjunto vazio: falso');
        $this->assertTrue((new InQuerySpecification($regions, Spec::alwaysTrue(), 'code'))->equals(new InQuerySpecification($regions, Spec::alwaysTrue(), 'code')), 'equals() estrutural');
        $this->assertFalse((new InQuerySpecification($regions, Spec::alwaysTrue(), 'code'))->equals(new InQuerySpecification($regions->withTrashed(), Spec::alwaysTrue(), 'code')), 'outro repositório (visão): diferente');
        $this->assertEquals('inQuery(aspec_region.code)', (string) new InQuerySpecification($regions, Spec::alwaysTrue(), 'code'));
    }

    /**
     * Paridade de nulos com subconsulta: árvores aleatórias (semente fixa) sobre aspec_null_parity
     * misturando comparações e Spec::inQuery() (sobre a própria tabela, colunas com NULL), combinadas
     * por and/or/not/nor e por not() dentro da propriedade. Pernas: memória (isSatisfiedBy(), evaluate(),
     * ALinq, simplify()), PdoRepository::find() e o banco executando toWhereClause(). Estende a prova do
     * Módulo 22 (1.11.0), que não pode receber a folha: as pernas Spec::toSql() e TCriteria de lá não a
     * traduzem (ela precisa do repositório).
     */
    private function testInQueryNullParity(PDO $pdo, string $family, int $trees = 200): void
    {
        $pdo->exec('DELETE FROM aspec_null_parity');
        $repository = PdoRepository::forEntity($pdo, PdoNullParityRow::class);
        $rows = [];
        $id = 0;
        foreach ([null, 1, 2, 3, 4] as $n) {
            foreach ([null, 1, 2, 3, 4] as $m) {
                foreach ([null, 'a', 'b'] as $s) {
                    $rows[] = new PdoNullParityRow(++$id, $n, $m, $s);
                }
            }
        }
        $repository->putAll($rows);

        mt_srand(20261012);
        $violations = [];
        $subqueries = 0;
        $withNull = 0;
        $nonTrivial = 0;
        for ($i = 0; $i < $trees; $i++) {
            $leaves = 0;
            $tree = $this->inQueryParityTree($repository, 3, $leaves);
            $subqueries += $leaves;
            $label = sprintf('#%d %s', $i, (string) $tree);
            $linq = ALinqSpecificationVisitor::createPredicate($tree);
            $simplified = Spec::simplify($tree);
            $memory = [];
            foreach ($rows as $row) {
                $satisfied = $tree->isSatisfiedBy($row);
                $result = $tree->evaluate($row);
                if ($result->isError || $result->isSatisfied !== $satisfied) {
                    $violations[] = "{$family} evaluate() ≠ isSatisfiedBy() na linha {$row->id}: {$label}";
                }
                if ($linq($row) !== $satisfied || $simplified->isSatisfiedBy($row) !== $satisfied) {
                    $violations[] = "{$family} ALinq/simplify ≠ memória na linha {$row->id}: {$label}";
                }
                if ($satisfied) {
                    $memory[] = $row->id;
                }
            }
            $found = array_map(static fn(PdoNullParityRow $r): int => $r->id, $repository->find($tree));
            sort($found);
            $where = $repository->toWhereClause($tree);
            $statement = $pdo->prepare('SELECT id FROM aspec_null_parity WHERE ' . $where->toSql() . ' ORDER BY id');
            foreach ($where->getParameters() as $name => $value) {
                $statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $statement->execute();
            $sql = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
            foreach (['PdoRepository' => $found, 'SQL' => $sql] as $leg => $legIds) {
                if ($legIds !== $memory) {
                    $violations[] = sprintf('%s %s ≠ memória: %s | só memória [%s] só %s [%s] | %s', $family, $leg, $label, implode(',', array_diff($memory, $legIds)), $leg, implode(',', array_diff($legIds, $memory)), $where->toSql());
                }
            }
            if ($memory !== [] && count($memory) < count($rows)) {
                $nonTrivial++;
            }
            foreach ($memory as $rowId) {
                $row = $rows[$rowId - 1];
                if ($row->n === null || $row->m === null || $row->s === null) {
                    $withNull++;
                    break;
                }
            }
        }
        $this->assertEquals([], $violations, implode(PHP_EOL, array_slice($violations, 0, 5)));
        $this->assertTrue($subqueries >= $trees, "{$family}: só {$subqueries} folhas inQuery");
        $this->assertTrue($nonTrivial > $trees / 3 && $withNull > $trees / 5, "{$family}: árvores triviais demais ({$nonTrivial} não triviais, {$withNull} com NULL)");
        fwrite(STDOUT, sprintf("    [NULOS+inQuery] %s: %d árvores × %d linhas, %d subconsultas; memória = ALinq = simplify = PdoRepository = SQL (%d não triviais, %d aceitaram linha com NULL)\n", $family, $trees, count($rows), $subqueries, $nonTrivial, $withNull));
    }

    private function inQueryParityTree(PdoRepository $repository, int $depth, int &$leaves): ISpecification
    {
        if ($depth === 0 || mt_rand(0, 9) < 3) {
            return $this->inQueryParityLeaf($repository, $leaves);
        }
        $d = $depth - 1;

        return match (mt_rand(0, 4)) {
            0 => Spec::not($this->inQueryParityTree($repository, $d, $leaves)),
            1 => $this->inQueryParityTree($repository, $d, $leaves)->and($this->inQueryParityTree($repository, $d, $leaves)),
            2 => $this->inQueryParityTree($repository, $d, $leaves)->or($this->inQueryParityTree($repository, $d, $leaves)),
            3 => Spec::nor($this->inQueryParityTree($repository, $d, $leaves), $this->inQueryParityTree($repository, $d, $leaves)),
            default => Spec::anyOf($this->inQueryParityTree($repository, $d, $leaves), $this->inQueryParityTree($repository, $d, $leaves)),
        };
    }

    private function inQueryParityLeaf(PdoRepository $repository, int &$leaves): ISpecification
    {
        $int = static fn(): int => mt_rand(1, 4);
        $column = ['n', 'm'][mt_rand(0, 1)];
        $simple = fn(): ISpecification => match (mt_rand(0, 5)) {
            0 => Spec::property(['n', 'm'][mt_rand(0, 1)], Spec::greaterThan($int())),
            1 => Spec::property(['n', 'm'][mt_rand(0, 1)], Spec::isNull()),
            2 => Spec::property('s', Spec::equalTo(['a', 'b'][mt_rand(0, 1)])),
            3 => Spec::property('s', Spec::isNull()),
            4 => Spec::not(Spec::property(['n', 'm'][mt_rand(0, 1)], Spec::lessThan($int()))),
            default => Spec::property(['n', 'm'][mt_rand(0, 1)], Spec::in($int(), $int(), null)),
        };
        if (mt_rand(0, 9) < 5) {
            $leaves++;
            $inner = new InQuerySpecification($repository, $simple(), ['n', 'm'][mt_rand(0, 1)]);

            return match (mt_rand(0, 2)) {
                0 => Spec::property($column, $inner),
                1 => Spec::property($column, Spec::not($inner)),
                default => Spec::property($column, $inner->or(Spec::equalTo($int()))),
            };
        }

        return $simple();
    }

    /** SQLite: os comandos gerados. */
    private function testSetBasedStatementShapes(): void
    {
        $pdo = new PdoRecordingPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->loadSchema($pdo, 'sqlite');
        $clock = new Module25Clock(new DateTimeImmutable('2026-08-01 10:00:00Z'));
        $sales = PdoRepository::forEntity($pdo, PdoSale::class)->withClock($clock->closure());
        $regions = PdoRepository::forEntity($pdo, PdoRegion::class);
        $qty = Spec::property('qty', Spec::greaterThan(1));

        $pdo->reset();
        $lazy = $sales->select($qty, ['region', 'amount']);
        $this->assertEquals([], $pdo->statements, 'select(): a consulta roda no primeiro pull');
        iterator_to_array($lazy);
        $this->assertEquals(['SELECT "region", "amount" FROM "aspec_sale" WHERE (("qty" IS NOT NULL AND "qty" > :p1) AND "deleted_at" IS NULL) ORDER BY "id" ASC'], $pdo->statements, 'select(): só as colunas pedidas');

        $pdo->reset();
        $sales->aggregate($qty)->groupBy('region', 'product')->count()->sum('amount')->avg('qty')->max('soldOn')->get();
        $this->assertEquals(['SELECT "region", "product", COUNT(*), SUM(CAST("amount" AS REAL)), AVG("qty"), MAX("sold_on") FROM "aspec_sale" WHERE (("qty" IS NOT NULL AND "qty" > :p1) AND "deleted_at" IS NULL) GROUP BY "region", "product" ORDER BY CASE WHEN "region" IS NULL THEN 0 ELSE 1 END, "region", CASE WHEN "product" IS NULL THEN 0 ELSE 1 END, "product"'], $pdo->statements, 'aggregate()');

        $pdo->reset();
        $sales->updateWhere($qty, ['product' => 'w', 'amount' => '1.00']);
        $this->assertEquals(['UPDATE "aspec_sale" SET "product" = :set0, "amount" = :set1, "updated_at" = :lc_updated_at, "version" = COALESCE("version", 0) + 1 WHERE (("qty" IS NOT NULL AND "qty" > :p1) AND "deleted_at" IS NULL)'], $pdo->statements, 'updateWhere(): um comando');

        $tree = $qty->and(Spec::inQuery('region', $regions, Spec::property('zone', Spec::in('a', 'b'))->and(Spec::inQuery('code', $sales, Spec::property('qty', Spec::lessThan(9)), 'region')), 'code'));
        $where = $sales->toWhereClause($tree);
        $this->assertEquals('(("qty" IS NOT NULL AND "qty" > :p1) AND ("region" IS NOT NULL AND "region" IN (SELECT "code" FROM "aspec_region" WHERE (((("zone" IS NOT NULL AND "zone" IN (:ps1_1, :ps1_2)) AND "code" IN (SELECT "region" FROM "aspec_sale" WHERE ((("qty" IS NOT NULL AND "qty" < :ps1_s1_1) AND "deleted_at" IS NULL) AND "region" IS NOT NULL))) AND "deleted_at" IS NULL) AND "code" IS NOT NULL))))', $where->toSql(), 'inQuery aninhada: parâmetros com prefixos próprios; o identificador (code) sem guarda de NULL');
        $this->assertEquals([':p1' => 1, ':ps1_1' => 'a', ':ps1_2' => 'b', ':ps1_s1_1' => 9], $where->getParameters(), 'todos os valores ligados');
        $this->assertEquals('SELECT "code" FROM "aspec_region" WHERE ((("zone" IS NOT NULL AND "zone" = :q1) AND "deleted_at" IS NULL) AND "code" IS NOT NULL)', $regions->toSubquery(Spec::property('zone', Spec::equalTo('a')), 'code')->toSql(), 'toSubquery(): prefixo padrão q');
        $sub = $regions->toSubquery(Spec::property('zone', Spec::equalTo('a')), 'code', 'x');
        $this->assertEquals(['SELECT "code" FROM "aspec_region" WHERE ((("zone" IS NOT NULL AND "zone" = :x1) AND "deleted_at" IS NULL) AND "code" IS NOT NULL)', [':x1' => 'a']], [$sub->toSql(), $sub->getParameters()], 'toSubquery(): escopo e NULL fora');
        $this->assertThrows(InvalidArgumentException::class, fn() => $regions->toSubquery(Spec::alwaysTrue(), 'code', '1x'), 'prefixo inválido');
        $this->assertTrue($sales->withTrashed()->toSubquery(Spec::alwaysTrue(), 'region')->toSql() === 'SELECT "region" FROM "aspec_sale" WHERE (1 = 1 AND "region" IS NOT NULL)', 'withTrashed(): sem a condição do soft delete');
    }

    /** RN-03 de E2: updateWhere × update por entidade (putAll) com 10 000 linhas no SQLite. */
    private function testUpdateWhereBenchmark(): void
    {
        $rows = 10000;
        $results = [];
        foreach (['updateWhere', 'putAll (por entidade)'] as $label) {
            $pdo = $this->sqlite();
            $sales = PdoRepository::forEntity($pdo, PdoSale::class);
            $make = [];
            for ($i = 1; $i <= $rows; $i++) {
                $make[] = new PdoSale($i, ['north', 'south'][$i % 2], 'p' . ($i % 10), $i % 50, '1.00');
            }
            $sales->insertAll($make);
            $logger = new InMemoryQueryLogger($rows * 3);
            $sales->withQueryLogger($logger);
            gc_collect_cycles();
            $t = microtime(true);
            if ($label === 'updateWhere') {
                $touched = $sales->updateWhere(Spec::alwaysTrue(), ['product' => 'bulk']);
            } else {
                $entities = $sales->find(Spec::alwaysTrue());
                foreach ($entities as $entity) {
                    $entity->product = 'bulk';
                }
                $sales->putAll($entities);
                $touched = count($entities);
            }
            $ms = (microtime(true) - $t) * 1000;
            $statements = count($logger->statements());
            $this->assertEquals($rows, $touched, "RN-03/E2: {$label} tocou tudo");
            $this->assertEquals([['n' => $rows]], $sales->aggregate(Spec::property('product', Spec::equalTo('bulk')))->count(null, 'n')->get());
            $this->assertEquals([['v' => 2]], $sales->aggregate(Spec::alwaysTrue())->min('version', 'v')->get(), 'toda linha com a versão incrementada');
            $results[$label] = ['ms' => $ms, 'statements' => $statements];
        }
        $this->assertEquals(1, $results['updateWhere']['statements'], 'updateWhere: um comando');
        $this->assertTrue($results['updateWhere']['ms'] < $results['putAll (por entidade)']['ms'], 'updateWhere mais rápido');
        fwrite(STDOUT, sprintf(
            "    [RN-03/E2] sqlite, %d linhas: updateWhere %.0f ms (%d comando); find + putAll %.0f ms (%d comandos); %.1fx\n",
            $rows,
            $results['updateWhere']['ms'],
            $results['updateWhere']['statements'],
            $results['putAll (por entidade)']['ms'],
            $results['putAll (por entidade)']['statements'],
            $results['putAll (por entidade)']['ms'] / max($results['updateWhere']['ms'], 0.001)
        ));
    }

    ///////////////////////////////////////////////////////////////////////////
    // Apoio
    ///////////////////////////////////////////////////////////////////////////

    private function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->loadSchema($pdo, 'sqlite');

        return $pdo;
    }

    /** O esquema do 028 (tabelas reutilizadas) e o do 031. */
    private function loadSchema(PDO $pdo, string $family): void
    {
        foreach (["schema.{$family}.sql", "complements.{$family}.sql"] as $file) {
            $sql = (string) file_get_contents(self::SCHEMA_DIR . '/' . $file);
            $sql = (string) preg_replace('/^--.*$/m', '', $sql);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                $pdo->exec($statement);
            }
        }
    }

    private function connectFromEnvironment(string $family): ?PDO
    {
        $dsn = getenv('ASPEC_PDO_' . strtoupper($family) . '_DSN');
        if ($dsn === false || $dsn === '' || !in_array('pdo_' . $family, array_map('strtolower', get_loaded_extensions()), true)) {
            return null;
        }
        $user = getenv('ASPEC_PDO_' . strtoupper($family) . '_USER');
        $password = getenv('ASPEC_PDO_' . strtoupper($family) . '_PASSWORD');

        return new PDO($dsn, $user === false ? null : $user, $password === false ? null : $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /** A primeira coluna da primeira linha, crua do driver. */
    private function raw(PDO $pdo, string $sql): mixed
    {
        $statement = $pdo->query($sql);
        $value = $statement->fetchColumn();
        $statement->closeCursor();

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private function snapshot(PdoArticle $a): array
    {
        return [
            $a->getEntityId(),
            $a->getTitle(),
            $a->getStatus()->value,
            $a->getViews(),
            $a->getPrice(),
            $a->isPublished(),
            $a->getPublishedAt()?->format('U.u'),
            $a->getTags(),
            $a->getNote(),
        ];
    }

    ///////////////////////////////////////////////////////////////////////////
    // README (1.13.0): exemplos 17.1 e 17.2 transcritos (EN/pt-BR); cada comentário "// valor" do README
    // vira uma asserção. Única troca: Ticket e Sale são ReadmeTicket e ReadmeSale (tests/Support),
    // escritas iguais; o $pdo e o $products do exemplo 17 são recriados aqui (os três produtos dele).
    ///////////////////////////////////////////////////////////////////////////

    /** Os blocos dos exemplos 17.1 (dois) e 17.2 (um) são iguais nos dois idiomas, fora os comentários. */
    private function testReadme171And172BlocksAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['17.1' => 2, '17.2' => 1] as $example => $blocks) {
            $english = ReadmeBlocks::of($root . '/README.md', $example);
            $portuguese = ReadmeBlocks::of($root . '/README.pt-BR.md', $example);
            $this->assertCount($blocks, $english, "{$blocks} bloco(s) PHP no exemplo {$example} (EN)");
            $this->assertCount($blocks, $portuguese, "{$blocks} bloco(s) PHP no exemplo {$example} (pt-BR)");
            foreach ($english as $i => $block) {
                $this->assertEquals(ReadmeBlocks::codeTokens($block), ReadmeBlocks::codeTokens($portuguese[$i]), "README {$example}: bloco {$i} igual fora os comentários");
            }
        }
        // O exemplo 17 segue com os seus três blocos (os subexemplos não vazam para ele)
        $this->assertCount(3, ReadmeBlocks::of($root . '/README.md', 17));
    }

    /** O $pdo e o $products do exemplo 17, no estado em que o exemplo os deixa (Keyboard e Mouse ativos, Cable não). */
    private function readme17World(): object
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE product (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(80) NOT NULL,
    unit_price TEXT NOT NULL, active INTEGER NOT NULL, cost TEXT NOT NULL, version INTEGER NOT NULL)');
        $products = PdoRepository::forEntity($pdo, ReadmeProduct::class);
        $products->put(new ReadmeProduct('Keyboard', '149.90', cost: '90.00'));
        $products->putAll([new ReadmeProduct('Mouse', '59.90'), new ReadmeProduct('Cable', '19.90', active: false)]);

        return (object) ['pdo' => $pdo, 'products' => $products];
    }

    /** README 17.1: carimbos no fuso do mapeamento, booleano 1/0, log sem valores, gancho, exclusão lógica. */
    private function testReadme171RunsAsWritten(): void
    {
        $pdo = $this->readme17World()->pdo;

        // --- bloco 1 ---
        $pdo->exec('CREATE TABLE ticket (id INTEGER PRIMARY KEY AUTOINCREMENT, subject VARCHAR(80) NOT NULL,
    urgent SMALLINT NOT NULL, created_at TEXT, updated_at TEXT, deleted_at TEXT)');

        $now = new DateTimeImmutable('2026-03-10 14:30:00.750 UTC');
        $log = new InMemoryQueryLogger();
        $tickets = PdoRepository::forEntity($pdo, ReadmeTicket::class)
            ->withClock(function () use (&$now): DateTimeImmutable { return $now; })
            ->withQueryLogger($log)
            ->beforePut(function (ReadmeTicket $ticket): void {
                if (trim($ticket->subject) === '') {
                    throw new InvalidArgumentException('A ticket needs a subject.');
                }
            });

        $ticket = new ReadmeTicket('Printer on fire', urgent: true);
        $tickets->put($ticket);
        $this->assertEquals('2026-03-10 11:30:00 -03:00', $ticket->createdAt->format('Y-m-d H:i:s P'));
        $this->assertEquals(['2026-03-10 11:30:00', 1], $pdo->query('SELECT created_at, urgent FROM ticket')->fetch(PDO::FETCH_NUM));
        $this->assertEquals(5, $log->entries()[0]['parameterCount']);
        $this->assertEquals(null, $log->entries()[0]['parameters']);
        // Prosa: truncado ao segundo; o INSERT carimba os dois; um booleano Integer compila para "= 1"
        $this->assertEquals('000000', $ticket->createdAt->format('u'));
        $this->assertTrue($ticket->updatedAt == $ticket->createdAt);
        $this->assertEquals('("urgent" IS NOT NULL AND "urgent" = 1)', $tickets->toWhereClause(Spec::property('urgent', Spec::isTrue()))->toSql());
        $this->assertEquals(BooleanStorage::Integer, $tickets->getMapping()->getBooleanStorage());

        $now = $now->modify('+2 hours');
        $ticket->subject = 'Printer on fire, 3rd floor';
        $tickets->put($ticket);
        $this->assertEquals('13:30', $ticket->updatedAt->format('H:i'));
        $this->assertEquals('11:30', $ticket->createdAt->format('H:i'));

        // --- bloco 2 ---
        $caught = false;
        try {
            $tickets->put(new ReadmeTicket('  '));
        } catch (InvalidArgumentException $e) {
            $caught = true;
        }
        $this->assertTrue($caught, 'README 17.1: o gancho beforePut recusa');
        $this->assertEquals(1, (int) $pdo->query('SELECT COUNT(*) FROM ticket')->fetchColumn(), 'README 17.1: nada foi gravado');

        $tickets->remove($ticket);
        $this->assertEquals(1, (int) $pdo->query('SELECT COUNT(*) FROM ticket WHERE deleted_at IS NOT NULL')->fetchColumn(), 'README 17.1: a linha fica, marcada');
        $this->assertEquals(0, $tickets->count(Spec::alwaysTrue()));
        $this->assertEquals(null, $tickets->findById(1));
        $this->assertEquals([], iterator_to_array($tickets->select(Spec::alwaysTrue(), ['subject']), false));
        $this->assertEquals(1, $tickets->withTrashed()->count(Spec::alwaysTrue()));
        $this->assertEquals('Printer on fire, 3rd floor', $tickets->onlyTrashed()->findById(1)->subject);
        $this->assertTrue($tickets->forceRemove($ticket));
        $this->assertEquals(0, $tickets->withTrashed()->count(Spec::alwaysTrue()));
        $this->assertEquals(0, (int) $pdo->query('SELECT COUNT(*) FROM ticket')->fetchColumn(), 'README 17.1: DELETE de verdade');

        // Prosa: extract()/hydrate() sem banco
        $mapping = $tickets->getMapping();
        $row = $mapping->extract(new ReadmeTicket('Paper jam'));
        $this->assertEquals(['id', 'created_at', 'updated_at', 'subject', 'urgent'], array_keys($row));
        $hydrated = $mapping->hydrate(['id' => 7, 'subject' => 'Toner', 'urgent' => '1']);
        $this->assertTrue($hydrated instanceof ReadmeTicket && $hydrated->getEntityId() === 7 && $hydrated->urgent === true);
    }

    /** README 17.2: insertAll num INSERT, updateWhere, select, aggregate com groupBy, Spec::inQuery. */
    private function testReadme172RunsAsWritten(): void
    {
        $world = $this->readme17World();
        $pdo = $world->pdo;
        $products = $world->products;
        $log = new InMemoryQueryLogger();

        // --- bloco 1 ---
        $pdo->exec('CREATE TABLE sale (id CHAR(26) PRIMARY KEY, product_id INTEGER NOT NULL, region VARCHAR(10),
    amount TEXT NOT NULL, quantity INTEGER NOT NULL)');
        $log->clear();
        $sales = PdoRepository::forEntity($pdo, ReadmeSale::class)->withQueryLogger($log);

        $this->assertEquals(4, $sales->insertAll([
            new ReadmeSale(1, 'north', '139.90'),
            new ReadmeSale(1, 'south', '279.80', quantity: 2),
            new ReadmeSale(2, 'north', '59.90'),
            new ReadmeSale(3, null, '19.90'),
        ]));
        $this->assertEquals(1, count($log->statements()), 'README 17.2: um INSERT de várias linhas: ' . implode(' | ', $log->statements()));

        $log->clear();
        $this->assertEquals(1, $sales->updateWhere(Spec::property('region', Spec::isNull()), ['region' => 'west']));
        $this->assertEquals(1, count($log->statements()), 'README 17.2: um UPDATE, nenhuma entidade carregada');

        $this->assertEquals(
            [['productId' => 1, 'amount' => '139.90'], ['productId' => 2, 'amount' => '59.90']],
            iterator_to_array($sales->select(Spec::property('region', Spec::equalTo('north')), ['productId', 'amount']), false)
        );

        $this->assertEquals(
            [
                ['region' => 'north', 'sum_amount' => '199.8', 'sum_quantity' => 2, 'count' => 2],
                ['region' => 'south', 'sum_amount' => '279.8', 'sum_quantity' => 2, 'count' => 1],
                ['region' => 'west', 'sum_amount' => '19.9', 'sum_quantity' => 1, 'count' => 1],
            ],
            $sales->aggregate(Spec::alwaysTrue())->groupBy('region')->sum('amount')->sum('quantity')->count()->get()
        );

        $log->clear();
        $this->assertEquals(3, $sales->count(Spec::inQuery('productId', $products, Spec::property('active', Spec::isTrue()), 'entityId')));
        $this->assertTrue(count($log->statements()) === 1 && str_contains($log->statements()[0], '"product_id" IN (SELECT "id" FROM "product"'), 'README 17.2: uma consulta com subconsulta: ' . implode(' | ', $log->statements()));

        // Prosa: a soma de nada é null, não 0; count() conta linhas
        $this->assertEquals([['sum_amount' => null, 'count' => 0]], $sales->aggregate(Spec::property('region', Spec::equalTo('nowhere')))->sum('amount')->count()->get());
        // Prosa: updateWhere recusa o identificador antes de qualquer SQL
        $this->assertThrows(InvalidArgumentException::class, fn() => $sales->updateWhere(Spec::alwaysTrue(), ['entityId' => 'x']));
    }
}
