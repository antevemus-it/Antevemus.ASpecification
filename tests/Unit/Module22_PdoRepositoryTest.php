<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\PartitionCreationException;
use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;
use Antevemus\ASpecification\Contracts\Repositories\IRepository;
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Persistence\ITransactionManager;
use Antevemus\ASpecification\Persistence\PdoTransactionManager;
use Antevemus\ASpecification\Repositories\InMemoryRepository;
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\EntityMappingException;
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\OptimisticLockException;
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\PartitioningNotSupportedException;
use Antevemus\ASpecification\Repositories\Pdo\Exceptions\PdoRepositoryException;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\EntityMapping;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\PdoRepository;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Linq\ALinqSpecificationVisitor;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Specifications\PredicateSpecification;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;
use Antevemus\ASpecification\Sql\Exceptions\UnsupportedSqlOperationException;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryTransactionManager;
use Antevemus\ASpecification\StateMachine\Persistence\ITransactionManager as MachineTransactionManager;
use Antevemus\ASpecification\StateMachine\Persistence\Pdo\PdoTransactionManager as MachinePdoTransactionManager;
use Antevemus\ASpecification\Tests\Support\PdoArticle;
use Antevemus\ASpecification\Tests\Support\PdoArticleStatus;
use Antevemus\ASpecification\Tests\Support\PdoColor;
use Antevemus\ASpecification\Tests\Support\PdoCounter;
use Antevemus\ASpecification\Tests\Support\PdoCountingRepository;
use Antevemus\ASpecification\Tests\Support\PdoNullParityRow;
use Antevemus\ASpecification\Tests\Support\PdoPriority;
use Antevemus\ASpecification\Tests\Support\PdoRecordingPdo;
use Antevemus\ASpecification\Tests\Support\PdoSecretHolder;
use Antevemus\ASpecification\Tests\Support\PdoTag;
use Antevemus\ASpecification\Tests\Support\PdoTypedRecord;
use Antevemus\ASpecification\Tests\Support\PdoVersionedDocument;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use Antevemus\ASpecification\Tests\Support\ReadmeProduct;
use Antevemus\ASpecification\Tests\TestCase;
use Antevemus\ASpecification\Util\UlidGenerator;
use Antevemus\ASpecification\Util\UuidV7Generator;
use Closure;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Módulo 22 (forward 028, v1.11.0): PdoRepository, o IRepository sobre PDO.
 *
 * - RN-02: a bateria de contrato roda contra o InMemoryRepository e contra o PdoRepository (SQLite em
 *   memória) com as MESMAS instâncias e as mesmas expectativas, e as assinaturas de resultado dos dois
 *   precisam ser idênticas. O Módulo 3 não pôde ser parametrizado (usa closures, TTL e entidades
 *   anônimas que o SQL não traduz nem hidrata), então a bateria foi transcrita aqui para o que o
 *   contrato IRepository garante.
 * - RN-03: a mesma bateria (e os testes de tipos, identidade, bloqueio otimista, atomicidade e
 *   transação compartilhada) roda em PostgreSQL e MySQL reais quando ASPEC_PDO_PGSQL_DSN /
 *   ASPEC_PDO_MYSQL_DSN apontam para eles (tests/Fixtures/pdo_repository/run_portability.sh sobe os
 *   contêineres descartáveis); sem as variáveis, avisa e pula.
 * - RN-04: valores só por parâmetro; nomes só do mapeamento, validados; nada da especificação vira SQL.
 * - RN-05: benchmark de 10 000 linhas no SQLite (iterate em memória constante, count sem hidratar,
 *   findSingle com uma consulta), números impressos.
 * - README 17 (1.11.0): os três blocos do exemplo transcritos e iguais em EN e pt-BR fora os comentários.
 * - Semântica de nulo (1.11.0): prova gerativa de paridade, 400 árvores aleatórias com semente fixa sobre
 *   80 linhas com NULL em cada coluna; memória (isSatisfiedBy, evaluate, ALinq, simplify) = SQL do
 *   visitor = PdoRepository = TCriteria, linha a linha, no SQLite e, com as variáveis do RN-03, em
 *   PostgreSQL e MySQL.
 */
class Module22_PdoRepositoryTest extends TestCase
{
    private const SCHEMA_DIR = __DIR__ . '/../Fixtures/pdo_repository';

    public function run(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            fwrite(STDOUT, "    [AVISO] pdo_sqlite ausente; o Módulo 22 roda só os testes de mapeamento.\n");
            $this->testMappingByAttributesEqualsTheBuilder();
            $this->testMappingValidation();
            return;
        }

        // RN-02: mesma bateria, mesmos resultados
        $memory = $this->runContractBattery('InMemory', fn(): IRepository => new InMemoryRepository());
        $sqlite = $this->sqlite();
        $sqliteSignature = $this->runContractBattery('SQLite', fn(): IRepository => $this->articleRepository($sqlite));
        $this->assertEquals($memory, $sqliteSignature, 'RN-02: o PdoRepository (SQLite) devolve o mesmo que o InMemoryRepository');

        // D2: mapeamento
        $this->testMappingByAttributesEqualsTheBuilder();
        $this->testMappingValidation();

        // D3, D5, D6, D8 (portáveis: rodam também no RN-03)
        $this->runPortableSuite($sqlite, 'sqlite');

        // D4, D7, D9, RN-04 (SQLite)
        $this->testHydrationSkipsTheConstructor($sqlite);
        $this->testNonTranslatableSpecificationsAreRefusedUnlessFallback($sqlite);
        $this->testQueryExposureFollowsSpecifiable($sqlite, 'sqlite');
        $this->testStatementsCountsAndLaziness();
        $this->testPagination($sqlite);
        $this->testPartitioningIsRefused($sqlite);
        $this->testSecurity();

        // RN-05
        $this->testRn05Benchmark();

        // README 17 (1.11.0)
        $this->testReadme17BlocksAreTheSameInBothLanguages();
        $this->testReadme17RunsAsWritten();

        // Semântica de nulo (1.11.0): memória = SQL = PdoRepository = TCriteria, árvore a árvore
        $this->testNullSemanticsParity($sqlite, 'sqlite');

        // RN-03
        foreach (['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL'] as $family => $label) {
            $pdo = $this->connectFromEnvironment($family);
            if ($pdo === null) {
                fwrite(STDOUT, "    [AVISO] ASPEC_PDO_" . strtoupper($family) . "_DSN não definido; RN-03 em {$label} pulado.\n");
                continue;
            }
            $this->loadSchema($pdo, $family);
            $signature = $this->runContractBattery($label, fn(): IRepository => $this->articleRepository($pdo));
            $this->assertEquals($memory, $signature, "RN-03: {$label} devolve o mesmo que o InMemoryRepository");
            $this->runPortableSuite($pdo, $family);
            $this->testQueryExposureFollowsSpecifiable($pdo, $family);
            $this->testNullSemanticsParity($pdo, $family);
            fwrite(STDOUT, "    [RN-03] {$label} " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ": bateria e suíte portável OK\n");
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // RN-02 / RN-03: bateria de contrato
    ///////////////////////////////////////////////////////////////////////////

    /**
     * A bateria do contrato IRepository sobre PdoArticle. A fábrica devolve um repositório vazio. Devolve a
     * assinatura dos resultados (títulos ordenados por consulta e o retrato de cada entidade), comparada
     * entre os repositórios.
     *
     * @param Closure(): IRepository $factory
     * @return array<string, mixed>
     */
    private function runContractBattery(string $label, Closure $factory): array
    {
        $repo = $factory();
        $all = Spec::alwaysTrue();
        $sig = [];

        // Vazio
        $this->assertEquals(0, $repo->count($all), "{$label}: vazio");
        $this->assertEquals([], $repo->findAll($all));
        $this->assertTrue($repo->findSingle($all) === null);
        $this->assertTrue($repo->iterate($all) instanceof Generator, "{$label}: iterate() é Generator");
        $this->assertEquals([], iterator_to_array($repo->iterate($all), false));

        $sp = new DateTimeZone('America/Sao_Paulo');
        $a1 = new PdoArticle('Alpha', PdoArticleStatus::Published, 10, '9.90', true, new DateTimeImmutable('2026-01-02 10:00:00Z'), ['x', 'y']);
        $a2 = new PdoArticle('Beta', PdoArticleStatus::Draft, 3, '0.00', false, null, [], 'n2');
        $a3 = new PdoArticle('Gamma', PdoArticleStatus::Archived, 25, '15.50', false, new DateTimeImmutable('2025-12-31 23:00:00', $sp), ['y']);
        $a4 = new PdoArticle('Delta', PdoArticleStatus::Published, 7, '9.90', true, new DateTimeImmutable('2026-03-01 00:00:00Z'), [], 'n4');
        $a5 = new PdoArticle('Epsilon 100%_off', PdoArticleStatus::Draft, 0, '1.00', false, null, [], null);

        $repo->put($a1);
        $repo->putAll([$a2, $a3, $a4, $a5]);
        $this->assertEquals(5, $repo->count($all), "{$label}: cinco gravadas");

        $sig['iterateOrder'] = array_map(fn(PdoArticle $a): string => $a->getTitle(), iterator_to_array($repo->iterate($all), false));
        $this->assertEquals(['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon 100%_off'], $sig['iterateOrder'], "{$label}: ordem de inserção");

        $queries = [
            'all' => [$all, ['Alpha', 'Beta', 'Delta', 'Epsilon 100%_off', 'Gamma']],
            'views>5' => [Spec::property('views', Spec::greaterThan(5)), ['Alpha', 'Delta', 'Gamma']],
            'views<=3' => [Spec::property('views', Spec::lessThanOrEqualTo(3)), ['Beta', 'Epsilon 100%_off']],
            'views>=7&&<25' => [Spec::allOf(Spec::property('views', Spec::greaterThanOrEqualTo(7)), Spec::property('views', Spec::lessThan(25))), ['Alpha', 'Delta']],
            'status=Published' => [Spec::property('status', Spec::equal(PdoArticleStatus::Published)), ['Alpha', 'Delta']],
            'status in' => [Spec::property('status', Spec::in(PdoArticleStatus::Draft, PdoArticleStatus::Archived)), ['Beta', 'Epsilon 100%_off', 'Gamma']],
            'status notIn' => [Spec::property('status', Spec::notIn(PdoArticleStatus::Published)), ['Beta', 'Epsilon 100%_off', 'Gamma']],
            // 1.11.0 mudou: o valor nulo vai à especificação interna, então isNull() dentro de property()
            // casa com as notas nulas (antes nunca casava) e in() com null aceita a nota nula
            'note isNull' => [Spec::property('note', Spec::isNull()), ['Alpha', 'Epsilon 100%_off', 'Gamma']],
            'note not null' => [Spec::property('note', Spec::isNotNull()), ['Beta', 'Delta']],
            'note null' => [Spec::not(Spec::property('note', Spec::isNotNull())), ['Alpha', 'Epsilon 100%_off', 'Gamma']],
            'not over null' => [Spec::not(Spec::property('note', Spec::equal('n2'))), ['Alpha', 'Delta', 'Epsilon 100%_off', 'Gamma']],
            'in with null' => [Spec::property('note', Spec::in(null, 'n4')), ['Alpha', 'Delta', 'Epsilon 100%_off', 'Gamma']],
            'after' => [Spec::property('publishedAt', Spec::after(new DateTimeImmutable('2026-01-01 12:00:00Z'))), ['Alpha', 'Delta']],
            'before (fuso)' => [Spec::property('publishedAt', Spec::before(new DateTimeImmutable('2026-01-02 00:00:00', $sp))), ['Gamma']],
            'between' => [Spec::property('publishedAt', Spec::between(new DateTimeImmutable('2026-01-01 00:00:00Z'), new DateTimeImmutable('2026-02-01 00:00:00Z'))), ['Alpha', 'Gamma']],
            'published' => [Spec::property('published', Spec::isTrue()), ['Alpha', 'Delta']],
            'startsWith' => [Spec::property('title', Spec::startsWith('Ga')), ['Gamma']],
            'contains' => [Spec::property('title', Spec::contains('psi')), ['Epsilon 100%_off']],
            'endsWith' => [Spec::property('title', Spec::endsWith('ta')), ['Beta', 'Delta']],
            'contains %_' => [Spec::property('title', Spec::contains('100%_')), ['Epsilon 100%_off']],
            'wildcard' => [Spec::property('title', Spec::wildcard('*lph*')), ['Alpha']],
            'or' => [Spec::anyOf(Spec::property('views', Spec::greaterThan(20)), Spec::property('status', Spec::equal(PdoArticleStatus::Published))), ['Alpha', 'Delta', 'Gamma']],
            'not' => [Spec::not(Spec::property('views', Spec::greaterThan(5))), ['Beta', 'Epsilon 100%_off']],
            'decimal&bool' => [Spec::allOf(Spec::property('price', Spec::equal('9.90')), Spec::property('published', Spec::isTrue())), ['Alpha', 'Delta']],
            'entityId' => [Spec::property('entityId', Spec::equal($a2->getEntityId())), ['Beta']],
            'unique entity' => [new UniqueEntitySpecification($a3), ['Gamma']],
            'unique id' => [new UniqueEntitySpecification($a3->getEntityId()), ['Gamma']],
            'unique other class' => [new UniqueEntitySpecification($a3->getEntityId(), PdoCounter::class), []],
            'all of type' => [new AllEntitiesSpecification(PdoArticle::class), ['Alpha', 'Beta', 'Delta', 'Epsilon 100%_off', 'Gamma']],
            'all IEntity' => [new AllEntitiesSpecification(IEntity::class), ['Alpha', 'Beta', 'Delta', 'Epsilon 100%_off', 'Gamma']],
            'all other type' => [new AllEntitiesSpecification(PdoCounter::class), []],
            'type and property' => [Spec::allOf(new AllEntitiesSpecification(PdoArticle::class), Spec::property('views', Spec::equal(25))), ['Gamma']],
            'false' => [Spec::alwaysFalse(), []],
        ];
        foreach ($queries as $name => [$spec, $expected]) {
            $titles = $this->titles($repo->findAll($spec));
            $this->assertEquals($expected, $titles, "{$label}: findAll [{$name}]");
            $this->assertEquals(count($expected), $repo->count($spec), "{$label}: count [{$name}]");
            $this->assertEquals($expected, $this->titles(iterator_to_array($repo->iterate($spec), false)), "{$label}: iterate [{$name}]");
            $sig['q:' . $name] = $titles;
        }

        // Aliases
        $spec = Spec::property('views', Spec::greaterThan(5));
        $this->assertEquals($this->titles($repo->findAll($spec)), $this->titles($repo->find($spec)));
        $this->assertEquals($this->titles($repo->findAll($spec)), $this->titles($repo->findAllEntitiesSpecifiedBy($spec)));
        $this->assertEquals($repo->count($spec), $repo->countAll($spec));
        $this->assertEquals($repo->count($spec), $repo->countAllEntitiesSpecifiedBy($spec));
        $this->assertTrue($repo->iterateAll($spec) instanceof Generator);

        // findSingle
        $this->assertEquals('Gamma', $repo->findSingle(Spec::property('status', Spec::equal(PdoArticleStatus::Archived)))?->getTitle());
        $this->assertTrue($repo->findSingle(Spec::property('views', Spec::greaterThan(100))) === null);
        $this->assertThrows(RuntimeException::class, fn() => $repo->findSingle(Spec::property('status', Spec::equal(PdoArticleStatus::Published))));
        $this->assertEquals('Gamma', $repo->findSingleEntitySpecifiedBy(new UniqueEntitySpecification($a3))?->getTitle());

        // Retrato de cada entidade lida de volta
        foreach ([$a1, $a2, $a3, $a4, $a5] as $article) {
            $read = $repo->findSingle(new UniqueEntitySpecification($article));
            $this->assertTrue($read instanceof PdoArticle, "{$label}: relida");
            $this->assertEquals($this->snapshot($article), $this->snapshot($read), "{$label}: mesmo estado de {$article->getTitle()}");
            $this->assertTrue($read->equals($article), "{$label}: mesma identidade");
            $sig['snap:' . $article->getTitle()] = array_slice($this->snapshot($read), 1); // sem o id (cada rodada cria entidades novas)
        }

        // contains
        $this->assertTrue($repo->contains($a1));
        $this->assertFalse($repo->contains(new PdoArticle('Never stored')));

        // update / updateWithDelta / put idempotente
        $a2->setViews(30);
        $repo->update($a2);
        $sig['afterUpdate'] = $this->titles($repo->findAll(Spec::property('views', Spec::greaterThan(20))));
        $this->assertEquals(['Beta', 'Gamma'], $sig['afterUpdate'], "{$label}: update()");

        $repo->updateWithDelta($a4, Spec::property('title', Spec::equal('Delta2')));
        $this->assertEquals('Delta2', $a4->getTitle(), "{$label}: o delta muda a entidade");
        $this->assertEquals(7, $repo->findSingle(Spec::property('title', Spec::equal('Delta2')))?->getViews(), "{$label}: updateWithDelta()");
        $this->assertEquals(0, $repo->count(Spec::property('title', Spec::equal('Delta'))));

        $repo->put($a1);
        $this->assertEquals(5, $repo->count($all), "{$label}: put() de entidade existente não duplica");

        // remove / removeAll
        $this->assertTrue($repo->remove($a5));
        $this->assertFalse($repo->remove($a5), "{$label}: remover de novo devolve false");
        $this->assertFalse($repo->contains($a5));
        $this->assertEquals(4, $repo->count($all));
        $this->assertEquals(1, $repo->removeAll(Spec::property('status', Spec::equal(PdoArticleStatus::Draft))), "{$label}: removeAll()");
        $this->assertEquals(0, $repo->removeBy(Spec::property('status', Spec::equal(PdoArticleStatus::Draft))));
        $this->assertEquals(3, $repo->count($all));
        $sig['afterRemove'] = $this->titles($repo->findAll($all));

        // putAll inválido e válido
        $this->assertThrows(InvalidArgumentException::class, fn() => $repo->putAll(['not an entity']));
        $this->assertEquals(3, $repo->count($all));
        $repo->putAll([$a2, $a5]);
        $this->assertEquals(5, $repo->count($all));
        $sig['final'] = $this->titles($repo->findAll($all));

        // Partição: classificação comum
        $this->assertFalse($repo->isNativelyPartitioned());
        $this->assertFalse($repo->isRecursivelyIndexing());

        return $sig;
    }

    ///////////////////////////////////////////////////////////////////////////
    // Suíte portável (SQLite, PostgreSQL, MySQL)
    ///////////////////////////////////////////////////////////////////////////

    private function runPortableSuite(PDO $pdo, string $family): void
    {
        $this->testTypeRoundTrip($pdo, $family);
        $this->testIdentityStrategies($pdo, $family);
        $this->testOptimisticLocking($pdo, $family);
        $this->testPutAllIsAtomic($pdo, $family);
        $this->testSharedTransactionPort($pdo, $family);
        $this->testDriverErrors($pdo, $family);
    }

    /** D3: conversão por coluna, ida e volta, e consulta pelos mesmos tipos. */
    private function testTypeRoundTrip(PDO $pdo, string $family): void
    {
        $pdo->exec('DELETE FROM aspec_typed');
        $repo = PdoRepository::forEntity($pdo, PdoTypedRecord::class);

        $t = new PdoTypedRecord('T1');
        $t->i = 42;
        $t->f = 3.25;
        $t->d = '1234.5678';
        $t->b = true;
        $t->day = new DateTimeImmutable('2026-02-28');
        $t->moment = new DateTimeImmutable('2026-06-01 12:34:56.123456Z');
        $t->momentMut = new DateTime('2026-01-01 00:00:01Z');
        $t->payload = ['a' => 1, 'n' => ['x' => true, 's' => 'ç'], 'f' => 1.5];
        $t->priority = PdoPriority::High;
        $t->color = PdoColor::Blue;
        $t->raw = 'raw-text';
        $repo->put($t);
        $repo->put(new PdoTypedRecord('T2'));

        $h = $repo->findById('T1');
        $this->assertTrue($h instanceof PdoTypedRecord, "{$family}: findById");
        $this->assertTrue($h !== $t, "{$family}: instância nova");
        $this->assertEquals(42, $h->i, "{$family}: int");
        $this->assertEquals(3.25, $h->f, "{$family}: float");
        $this->assertEquals('1234.5678', $h->d, "{$family}: decimal como texto");
        $this->assertEquals(true, $h->b, "{$family}: bool");
        $this->assertInstanceOf(DateTimeImmutable::class, $h->day);
        $this->assertEquals('2026-02-28', $h->day->format('Y-m-d'), "{$family}: date");
        $this->assertInstanceOf(DateTimeImmutable::class, $h->moment);
        $this->assertEquals('2026-06-01T12:34:56.123456+00:00', $h->moment->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'), "{$family}: mesmo instante, com microssegundos");
        $this->assertEquals('America/Sao_Paulo', $h->moment->getTimezone()->getName(), "{$family}: no fuso da coluna");
        $this->assertInstanceOf(DateTime::class, $h->momentMut);
        $this->assertEquals($t->momentMut->getTimestamp(), $h->momentMut->getTimestamp(), "{$family}: DateTime mutável");
        $this->assertTrue(is_array($h->payload));
        // JSONB (PostgreSQL) e JSON (MySQL) normalizam a ordem das chaves: compara-se o conteúdo
        $this->assertEquals($this->sortedKeys($t->payload), $this->sortedKeys($h->payload), "{$family}: JSON");
        $this->assertTrue($h->priority === PdoPriority::High, "{$family}: enum int");
        $this->assertTrue($h->color === PdoColor::Blue, "{$family}: enum puro pelo nome");
        $this->assertEquals('raw-text', $h->raw, "{$family}: raw");
        $this->assertTrue($h->getTimeOfCreation() instanceof DateTimeImmutable, 'timeOfCreation preenchido na hidratação');

        $empty = $repo->findById('T2');
        foreach (['i', 'f', 'd', 'b', 'day', 'moment', 'momentMut', 'payload', 'priority', 'color', 'raw'] as $p) {
            $this->assertTrue($empty->{$p} === null, "{$family}: {$p} nulo volta nulo");
        }

        // Consultas pelos mesmos tipos (parâmetros convertidos pela coluna)
        $one = fn($spec): array => array_map(fn(PdoTypedRecord $r): string => $r->code, $repo->findAll($spec));
        $this->assertEquals(['T1'], $one(Spec::property('moment', Spec::equal(new DateTimeImmutable('2026-06-01 09:34:56.123456', new DateTimeZone('America/Sao_Paulo'))))), "{$family}: instante em outro fuso");
        $this->assertEquals(['T1'], $one(Spec::property('moment', Spec::at(new DateTimeImmutable('2026-06-01 12:34:56.123456Z')))));
        $this->assertEquals(['T1'], $one(Spec::property('priority', Spec::equal(PdoPriority::High))));
        $this->assertEquals(['T1'], $one(Spec::property('color', Spec::equal(PdoColor::Blue))));
        $this->assertEquals(['T1'], $one(Spec::property('b', Spec::isTrue())));
        $this->assertEquals([], $one(Spec::property('b', Spec::isFalse())));
        $this->assertEquals(['T1'], $one(Spec::property('day', Spec::equal(new DateTimeImmutable('2026-02-28')))));
        $this->assertEquals(['T1'], $one(Spec::property('d', Spec::equal('1234.5678'))));
        $this->assertEquals(['T1'], $one(Spec::property('i', Spec::in(41, 42))));
        $this->assertEquals(['T2'], $one(Spec::not(Spec::property('i', Spec::isNotNull()))));
        // 1.11.0 mudou: isNull() dentro de property() casa com o valor nulo, em memória e no banco (antes: [])
        $this->assertEquals(['T2'], $one(Spec::property('i', Spec::isNull())), 'isNull() dentro de property() casa com o nulo, como em memória');
    }

    /** D5: auto, ulid, uuid7, assigned, max. */
    private function testIdentityStrategies(PDO $pdo, string $family): void
    {
        $pdo->exec('DELETE FROM aspec_counter');
        $pdo->exec('DELETE FROM aspec_counter_plain');
        $pdo->exec('DELETE FROM aspec_tag');

        // Auto: o banco gera; o id volta para a entidade com o tipo da coluna
        $auto = new PdoRepository($pdo, EntityMapping::for(PdoCounter::class)->table('aspec_counter')->id('id')->column('label'));
        $c1 = new PdoCounter('auto-1');
        $c2 = new PdoCounter('auto-2');
        $auto->put($c1);
        $auto->put($c2);
        $this->assertTrue(is_int($c1->getEntityId()) && $c1->getEntityId() > 0, "{$family}: auto gera id inteiro");
        $this->assertTrue($c2->getEntityId() > $c1->getEntityId(), "{$family}: auto crescente");
        $this->assertEquals('auto-2', $auto->findById($c2->getEntityId())?->getLabel());
        $preset = new PdoCounter('auto-preset', 1000);
        $auto->put($preset);
        $this->assertEquals(1000, $preset->getEntityId(), "{$family}: id já definido é inserido como veio");
        $this->assertEquals('auto-preset', $auto->findById(1000)?->getLabel());

        if ($family === 'pgsql') {
            // Com sequência explícita: lastInsertId(sequência) em vez de RETURNING
            $seq = new PdoRepository($pdo, EntityMapping::for(PdoCounter::class)->table('aspec_counter')->id('id', IdStrategy::Auto, sequence: 'aspec_counter_id_seq')->column('label'));
            $c3 = new PdoCounter('auto-seq');
            $seq->put($c3);
            $this->assertTrue($c3->getEntityId() > $c2->getEntityId(), 'pgsql: auto com sequência');
            $this->assertEquals('auto-seq', $seq->findById($c3->getEntityId())?->getLabel());
        }

        // Max: SELECT MAX+1 dentro da transação
        $max = new PdoRepository($pdo, EntityMapping::for(PdoCounter::class)->table('aspec_counter_plain')->id('id', IdStrategy::Max)->column('label'));
        $m1 = new PdoCounter('max-1');
        $max->put($m1);
        $this->assertEquals(1, $m1->getEntityId(), "{$family}: max em tabela vazia = 1");
        $max->put(new PdoCounter('max-10', 10));
        $m2 = new PdoCounter('max-11');
        $max->put($m2);
        $this->assertEquals(11, $m2->getEntityId(), "{$family}: max+1");

        // Assigned
        $assigned = new PdoRepository($pdo, EntityMapping::for(PdoCounter::class)->table('aspec_counter_plain')->id('id', IdStrategy::Assigned)->column('label'));
        $this->assertThrows(InvalidArgumentException::class, fn() => $assigned->put(new PdoCounter('no-id')));
        $this->assertEquals(0, $assigned->count(Spec::property('label', Spec::equal('no-id'))));
        $assigned->put(new PdoCounter('assigned-50', 50));
        $this->assertEquals('assigned-50', $assigned->findById(50)?->getLabel());

        // Ulid / Uuid7 sobre id nulo
        $ulid = new PdoRepository($pdo, EntityMapping::for(PdoTag::class)->table('aspec_tag')->id('id', IdStrategy::Ulid)->column('name'));
        $t1 = new PdoTag('ulid');
        $ulid->put($t1);
        $this->assertTrue(is_string($t1->getEntityId()) && UlidGenerator::isValid($t1->getEntityId()), "{$family}: ULID gerado");
        $uuid = new PdoRepository($pdo, EntityMapping::for(PdoTag::class)->table('aspec_tag')->id('id', IdStrategy::Uuid7)->column('name'));
        $t2 = new PdoTag('uuid7');
        $uuid->put($t2);
        $this->assertTrue(is_string($t2->getEntityId()) && UuidV7Generator::isValid($t2->getEntityId()), "{$family}: UUIDv7 gerado");
        $this->assertEquals('ulid', $uuid->findById($t1->getEntityId())?->getName());
        $this->assertEquals(2, $uuid->count(Spec::alwaysTrue()));
    }

    /** D6: #[Version] — atualização e remoção a partir de cópia velha lançam e não gravam. */
    private function testOptimisticLocking(PDO $pdo, string $family): void
    {
        $pdo->exec('DELETE FROM aspec_document');
        $repo = PdoRepository::forEntity($pdo, PdoVersionedDocument::class);

        $doc = new PdoVersionedDocument('v1');
        $repo->put($doc);
        $this->assertEquals(1, $doc->getVersion(), "{$family}: INSERT grava a versão 1");
        $id = $doc->getEntityId();

        $one = $repo->findById($id);
        $two = $repo->findById($id);
        $this->assertEquals(1, $two->getVersion());

        $one->setTitle('by one');
        $repo->update($one);
        $this->assertEquals(2, $one->getVersion(), "{$family}: UPDATE incrementa");

        $two->setTitle('by two');
        $e = $this->assertThrows(OptimisticLockException::class, fn() => $repo->update($two));
        $this->assertEquals(1, $e->getExpectedVersion());
        $this->assertEquals(2, $e->getActualVersion());
        $this->assertEquals(1, $two->getVersion(), 'a cópia velha não muda');
        $this->assertEquals('by one', $repo->findById($id)?->getTitle(), "{$family}: nada gravado");
        $this->assertThrows(OptimisticLockException::class, fn() => $repo->put($doc), 'o original (v1) também é velho');

        $this->assertThrows(OptimisticLockException::class, fn() => $repo->remove($two), 'remover cópia velha lança');
        $this->assertTrue($repo->contains($one));
        $this->assertTrue($repo->remove($one));
        $gone = $this->assertThrows(OptimisticLockException::class, fn() => $repo->update($one), 'versão sem linha não ressuscita');
        $this->assertTrue($gone->getActualVersion() === null);
        $this->assertEquals(0, $repo->count(Spec::alwaysTrue()));

        $fresh = new PdoVersionedDocument('fresh');
        $repo->put($fresh);
        $repo->put($fresh);
        $this->assertEquals(2, $fresh->getVersion(), "{$family}: put() repetido incrementa");
    }

    /** D6: putAll() numa transação — uma falha desfaz todas. */
    private function testPutAllIsAtomic(PDO $pdo, string $family): void
    {
        $pdo->exec('DELETE FROM aspec_counter');
        $repo = new PdoRepository($pdo, EntityMapping::for(PdoCounter::class)->table('aspec_counter')->id('id')->column('label'));
        $e = $this->assertThrows(PdoRepositoryException::class, fn() => $repo->putAll([
            new PdoCounter('atomic-1'),
            new PdoCounter('atomic-2'),
            new PdoCounter('atomic-1'),
        ]));
        $this->assertTrue($e->getSqlState() !== null, "{$family}: SQLSTATE presente");
        $this->assertEquals(0, $repo->count(Spec::alwaysTrue()), "{$family}: nada ficou gravado");
        $this->assertFalse($pdo->inTransaction());
    }

    /** D8: porta de transação compartilhada; os tipos da máquina de estados são subtipos dela. */
    private function testSharedTransactionPort(PDO $pdo, string $family): void
    {
        $pdo->exec('DELETE FROM aspec_counter');
        $machineTm = new MachinePdoTransactionManager($pdo);
        $this->assertInstanceOf(ITransactionManager::class, $machineTm);
        $this->assertInstanceOf(MachineTransactionManager::class, $machineTm);
        $this->assertInstanceOf(PdoTransactionManager::class, $machineTm);
        $this->assertInstanceOf(ITransactionManager::class, new InMemoryTransactionManager());
        $this->assertTrue($machineTm->getConnection() === $pdo);

        $mapping = EntityMapping::for(PdoCounter::class)->table('aspec_counter')->id('id')->column('label');
        $default = new PdoRepository($pdo, $mapping);
        $this->assertInstanceOf(PdoTransactionManager::class, $default->getTransactionManager());

        $repo = new PdoRepository($pdo, $mapping, $machineTm);
        $this->assertTrue($repo->getTransactionManager() === $machineTm);
        try {
            $machineTm->transactional(function () use ($repo, $machineTm): void {
                $repo->put(new PdoCounter('tx-1'));
                $repo->putAll([new PdoCounter('tx-2'), new PdoCounter('tx-3')]);
                $this->assertTrue($machineTm->inTransaction(), 'as escritas entram na transação de fora');
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $e) {
            $this->assertEquals('boom', $e->getMessage());
        }
        $this->assertEquals(0, $repo->count(Spec::alwaysTrue()), "{$family}: a transação de fora desfez tudo");

        $machineTm->transactional(function () use ($repo): void {
            $repo->put(new PdoCounter('tx-commit'));
        });
        $this->assertEquals(1, $repo->count(Spec::alwaysTrue()), "{$family}: commit da transação de fora");
        $this->assertFalse($pdo->inTransaction());
    }

    /** Erro do driver vira PdoRepositoryException com SQLSTATE, em qualquer modo de erro. */
    private function testDriverErrors(PDO $pdo, string $family): void
    {
        $missing = new PdoRepository($pdo, EntityMapping::for(PdoCounter::class)->table('aspec_missing')->id('id')->column('label'));
        $e = $this->assertThrows(PdoRepositoryException::class, fn() => $missing->count(Spec::alwaysTrue()));
        $this->assertInstanceOf(RepositoryException::class, $e);
        $this->assertTrue($e->getSqlState() !== null && strlen((string) $e->getSqlState()) === 5, "{$family}: SQLSTATE " . var_export($e->getSqlState(), true));

        $previous = $pdo->getAttribute(PDO::ATTR_ERRMODE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        try {
            $this->assertThrows(PdoRepositoryException::class, fn() => $missing->findAll(Spec::alwaysTrue()), "{$family}: ERRMODE_SILENT também lança");
        } finally {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, $previous);
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // D2: mapeamento
    ///////////////////////////////////////////////////////////////////////////

    private function testMappingByAttributesEqualsTheBuilder(): void
    {
        $byAttributes = EntityMapping::fromAttributes(PdoArticle::class);
        $byBuilder = EntityMapping::for(PdoArticle::class)
            ->table('aspec_article')
            ->id('entityId', IdStrategy::Ulid, 'id')
            ->column('title')
            ->column('status')
            ->column('views')
            ->column('price', type: ColumnType::Decimal)
            ->column('published')
            ->column('publishedAt', 'published_at')
            ->column('tags')
            ->column('note');
        $this->assertTrue($byAttributes->equals($byBuilder), 'D2: atributos e builder produzem o mesmo mapeamento');
        $this->assertTrue(EntityMapping::fromAttributes(PdoArticle::class) === $byAttributes, 'cache por classe');

        $columns = $byAttributes->getColumns();
        $this->assertEquals(ColumnType::Enum, $columns['status']->type);
        $this->assertEquals(PdoArticleStatus::class, $columns['status']->enumClass);
        $this->assertEquals(ColumnType::DateTime, $columns['publishedAt']->type);
        $this->assertEquals(ColumnType::Json, $columns['tags']->type);
        $this->assertEquals(ColumnType::Boolean, $columns['published']->type);
        $this->assertEquals(ColumnType::String, $columns['entityId']->type);
        $this->assertEquals('id', $byAttributes->getId()->column);
        $this->assertEquals(IdStrategy::Ulid, $byAttributes->getIdStrategy());
        $this->assertTrue($byAttributes->getVersion() === null);

        // Imutável: cada chamada devolve um mapeamento novo
        $start = EntityMapping::for(PdoCounter::class);
        $withTable = $start->table('aspec_counter');
        $this->assertTrue($start->getTable() === null && $withTable->getTable() === 'aspec_counter');
        $this->assertFalse($withTable->isComplete());
        $this->assertTrue($withTable->id('id')->isComplete());
        $this->assertEquals('lock_version', $withTable->version('lockVersion')->getVersion()?->column, 'snake_case por padrão');

        $typed = EntityMapping::fromAttributes(PdoTypedRecord::class)->getColumns();
        $this->assertEquals(DateTime::class, $typed['momentMut']->dateClass);
        $this->assertEquals('America/Sao_Paulo', $typed['moment']->timezone);
        $this->assertEquals(ColumnType::Date, $typed['day']->type);
        $this->assertEquals(ColumnType::Enum, $typed['color']->type);
        $this->assertEquals(ColumnType::Raw, $typed['raw']->type);
        $this->assertEquals(ColumnType::Integer, EntityMapping::fromAttributes(PdoVersionedDocument::class)->getVersion()?->type);
    }

    private function testMappingValidation(): void
    {
        $base = EntityMapping::for(PdoCounter::class);
        $fail = fn(Closure $f, string $why) => $this->assertThrows(EntityMappingException::class, $f, $why);

        $fail(fn() => $base->table('aspec_counter; DROP TABLE x'), 'RN-04: tabela não identificador');
        $fail(fn() => $base->table('"aspec"'), 'tabela com aspas');
        $fail(fn() => $base->table('a.b.c'), 'só um nível de schema');
        $this->assertEquals('main.aspec_counter', $base->table('main.aspec_counter')->getTable());
        $fail(fn() => $base->column('label', 'label"--'), 'RN-04: coluna não identificador');
        $fail(fn() => $base->column('missing'), 'propriedade inexistente');
        $fail(fn() => EntityMapping::for(PdoArticle::class)->column('constructed'), 'propriedade estática');
        $fail(fn() => $base->column('label', 'x')->id('id', column: 'x'), 'duas propriedades na mesma coluna');
        $fail(fn() => $base->id('id')->id('label'), 'segundo identificador');
        $fail(fn() => EntityMapping::for(PdoTag::class)->id('id', IdStrategy::Max), 'Max exige id inteiro');
        $fail(fn() => $base->id('id', IdStrategy::Ulid), 'Ulid exige id texto');
        $fail(fn() => $base->version('label'), 'versão exige inteiro');
        $fail(fn() => $base->id('id')->version('id'), 'id e versão na mesma propriedade');
        $fail(fn() => $base->column('label', timezone: 'Mars/Olympus'), 'fuso desconhecido');
        $fail(fn() => EntityMapping::for(AbstractEntity::class), 'classe abstrata');
        $fail(fn() => EntityMapping::for(\stdClass::class), 'não é IEntity');
        $fail(fn() => EntityMapping::for('No\\Such\\Klass'), 'classe inexistente');
        $fail(fn() => EntityMapping::fromAttributes(PdoCounter::class), 'sem #[Table]');
        $bag = new class extends AbstractEntity {
            public ?\ArrayObject $bag = null;

            public function getEntityId(): mixed
            {
                return 1;
            }
        };
        $fail(fn() => EntityMapping::for(get_class($bag))->column('bag'), 'tipo não inferível');
        $this->assertEquals(ColumnType::Json, EntityMapping::for(get_class($bag))->column('bag', type: ColumnType::Json)->getColumn('bag')?->type);

        if (extension_loaded('pdo_sqlite')) {
            $pdo = new PDO('sqlite::memory:');
            $fail(fn() => new PdoRepository($pdo, $base->table('aspec_counter')), 'repositório exige id');
            $fail(fn() => new PdoRepository($pdo, $base->id('id')), 'repositório exige tabela');
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // D3, D4, D7, D9, RN-04 (SQLite)
    ///////////////////////////////////////////////////////////////////////////

    private function testHydrationSkipsTheConstructor(PDO $pdo): void
    {
        $repo = $this->articleRepository($pdo);
        $repo->putAll([new PdoArticle('H1'), new PdoArticle('H2')]);
        $before = PdoArticle::$constructed;
        $found = $repo->findAll(Spec::alwaysTrue());
        $this->assertCount(2, $found);
        $this->assertEquals($before, PdoArticle::$constructed, 'D3: a hidratação não chama o construtor');

        $pdo->exec('DELETE FROM aspec_counter');
        $counters = new PdoRepository($pdo, EntityMapping::for(PdoCounter::class)->table('aspec_counter')->id('id')->column('label'));
        $counters->put(new PdoCounter('private'));
        $before = PdoCounter::$constructed;
        $read = $counters->findSingle(Spec::property('label', Spec::equal('private')));
        $this->assertEquals('private', $read?->getLabel(), 'propriedade privada mapeada é lida e escrita');
        $this->assertEquals($before, PdoCounter::$constructed);
        $this->assertTrue($read->getLockVersion() === null, 'propriedade não mapeada fica com o valor padrão');
    }

    /** D4: especificação não traduzível lança a exceção do visitor; withInMemoryFallback() é explícito. */
    private function testNonTranslatableSpecificationsAreRefusedUnlessFallback(PDO $pdo): void
    {
        $repo = $this->articleRepository($pdo);
        $repo->putAll([
            new PdoArticle('Alpha', views: 10),
            new PdoArticle('Beta', views: 3),
            new PdoArticle('Gamma', views: 25),
            new PdoArticle('Zero', views: 0),
        ]);
        $closure = new PredicateSpecification(fn(PdoArticle $a): bool => $a->getViews() > 5);
        $this->assertFalse($repo->hasInMemoryFallback(), 'desligado por padrão');

        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $repo->count($closure));
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $repo->findAll($closure));
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $repo->iterate($closure), 'iterate() falha na chamada, não no primeiro pull');
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $repo->findSingle($closure));
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $repo->removeAll($closure));
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $repo->findPage($closure, 10));
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $repo->count(Spec::property('missing', Spec::equal(1))), 'propriedade não mapeada');
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $repo->count(Spec::allOf(Spec::property('views', Spec::equal(1)), $closure)), 'uma folha opaca basta');
        $this->assertThrows(UnsupportedSqlOperationException::class, fn() => $repo->count(Spec::property('title', Spec::regex('/^A/'))), 'REGEX sem suporte no SQLite');
        $this->assertEquals(4, $repo->count(Spec::alwaysTrue()), 'nada foi removido');

        $this->assertTrue($repo->withInMemoryFallback() === $repo);
        $this->assertEquals(2, $repo->count($closure));
        $this->assertEquals(['Alpha', 'Gamma'], $this->titles($repo->findAll($closure)));
        $this->assertEquals(['Alpha', 'Gamma'], $this->titles(iterator_to_array($repo->iterate($closure), false)));
        $this->assertThrows(RuntimeException::class, fn() => $repo->findSingle($closure));
        $this->assertEquals(['Gamma'], $this->titles($repo->findPage($closure, 1, 1, ['views' => 'ASC'])));
        $this->assertEquals(1, $repo->count(Spec::property('title', Spec::regex('/^A/'))));
        $this->assertEquals(1, $repo->removeAll(new PredicateSpecification(fn(PdoArticle $a): bool => $a->getViews() === 0)));
        $this->assertEquals(3, $repo->count(Spec::alwaysTrue()));
        $repo->withInMemoryFallback(false);
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $repo->count($closure));
    }

    /** RN-04 + #[Specifiable]: coluna privada sem marca é gravada mas nunca consultável por nome (como em memória). */
    private function testQueryExposureFollowsSpecifiable(PDO $pdo, string $family): void
    {
        $pdo->exec('DELETE FROM aspec_secret');
        $repo = PdoRepository::forEntity($pdo, PdoSecretHolder::class);
        $repo->put(new PdoSecretHolder(1, 'hunter2', 'visible'));

        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $repo->count(Spec::property('secret', Spec::startsWith('h'))), "{$family}: privada sem marca recusada");
        $this->assertThrows(InvalidArgumentException::class, fn() => $repo->findPage(Spec::alwaysTrue(), 10, 0, ['secret' => 'ASC']), "{$family}: nem para ordenar");
        $this->assertEquals(1, $repo->count(Spec::property('marked', Spec::equal('visible'))), "{$family}: #[Specifiable] consultável");
        $this->assertEquals(1, $repo->count(Spec::property('entityId', Spec::equal(1))), "{$family}: entityId é o id");

        $memory = new InMemoryRepository();
        $memory->put(new PdoSecretHolder(1, 'hunter2', 'visible'));
        $this->assertThrows(InvalidArgumentException::class, fn() => $memory->count(Spec::property('secret', Spec::startsWith('h'))), 'em memória a privada sem marca é erro de avaliação');
        $this->assertThrows(InvalidArgumentException::class, fn() => $repo->withInMemoryFallback()->count(Spec::property('secret', Spec::startsWith('h'))), "{$family}: no fallback, o mesmo erro que em memória");
        $this->assertEquals('hunter2', $repo->findById(1)?->revealForTest(), "{$family}: o valor foi persistido e hidratado");
    }

    /** D7: COUNT(*) sem hidratar; findSingle com uma consulta e LIMIT 2; iterate preguiçoso; só colunas mapeadas. */
    private function testStatementsCountsAndLaziness(): void
    {
        $pdo = new PdoRecordingPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->loadSchema($pdo, 'sqlite');
        $repo = new PdoCountingRepository($pdo, EntityMapping::fromAttributes(PdoArticle::class));
        for ($i = 0; $i < 20; $i++) {
            $repo->put(new PdoArticle('Row ' . $i, views: $i));
        }

        $pdo->reset();
        $this->assertEquals(10, $repo->count(Spec::property('views', Spec::greaterThanOrEqualTo(10))));
        $this->assertCount(1, $pdo->statements);
        $this->assertEquals('SELECT COUNT(*) FROM "aspec_article" WHERE ("views" IS NOT NULL AND "views" >= :p1)', $pdo->statements[0]);
        $this->assertEquals(0, $repo->hydrations, 'count() não hidrata');

        $pdo->reset();
        $this->assertEquals('Row 7', $repo->findSingle(Spec::property('views', Spec::equal(7)))?->getTitle());
        $this->assertCount(1, $pdo->statements, 'findSingle(): uma consulta');
        $this->assertTrue(str_ends_with($pdo->statements[0], 'LIMIT 2'), 'findSingle(): LIMIT 2');
        $this->assertTrue(str_starts_with($pdo->statements[0], 'SELECT "id", "title", "status", "views", "price", "published", "published_at", "tags", "note" FROM "aspec_article"'), 'só as colunas mapeadas');
        $this->assertEquals(1, $repo->hydrations);

        $pdo->reset();
        $repo->hydrations = 0;
        $iterator = $repo->iterate(Spec::alwaysTrue());
        $this->assertCount(0, $pdo->statements, 'iterate(): nada roda antes do primeiro pull');
        $this->assertEquals('Row 0', $iterator->current()->getTitle());
        $this->assertCount(1, $pdo->statements);
        $this->assertEquals(1, $repo->hydrations, 'uma linha lida, uma entidade montada');
        $iterator->next();
        $this->assertEquals(2, $repo->hydrations);

        $pdo->reset();
        $this->assertCount(20, $repo->findAll(Spec::alwaysTrue()));
        $this->assertCount(1, $pdo->statements, 'findAll(): uma consulta');

        $pdo->reset();
        $this->assertEquals(5, $repo->removeAll(Spec::property('views', Spec::lessThan(5))));
        $this->assertEquals(['DELETE FROM "aspec_article" WHERE ("views" IS NOT NULL AND "views" < :p1)'], $pdo->statements, 'removeAll(): um DELETE compilado');
    }

    private function testPagination(PDO $pdo): void
    {
        $repo = $this->articleRepository($pdo);
        $repo->putAll([
            new PdoArticle('A', views: 10),
            new PdoArticle('B', views: 3),
            new PdoArticle('C', views: 25),
            new PdoArticle('D', views: 7),
            new PdoArticle('E', views: 0),
        ]);
        $all = Spec::alwaysTrue();
        $this->assertEquals(['C', 'A'], array_map(fn($a) => $a->getTitle(), $repo->findPage($all, 2, 0, ['views' => 'DESC'])));
        $this->assertEquals(['D', 'B'], array_map(fn($a) => $a->getTitle(), $repo->findPage($all, 2, 2, ['views' => 'desc'])));
        $this->assertEquals(['E'], array_map(fn($a) => $a->getTitle(), $repo->findPage($all, 2, 4, ['views' => 'DESC'])));
        $this->assertEquals(['A', 'B'], array_map(fn($a) => $a->getTitle(), $repo->findPage($all, 2)), 'padrão: pelo id (ordem de criação do ULID)');
        $this->assertEquals(['D'], array_map(fn($a) => $a->getTitle(), $repo->findPage(Spec::property('views', Spec::lessThan(10)), 1, 1, ['title' => 'DESC'])));
        $this->assertEquals([], $repo->findPage($all, 5, 10));
        $this->assertThrows(InvalidArgumentException::class, fn() => $repo->findPage($all, 0));
        $this->assertThrows(InvalidArgumentException::class, fn() => $repo->findPage($all, 1, -1));
        $this->assertThrows(InvalidArgumentException::class, fn() => $repo->findPage($all, 1, 0, ['views' => 'ASC; DROP TABLE aspec_article']));
        $this->assertThrows(InvalidArgumentException::class, fn() => $repo->findPage($all, 1, 0, ['nope' => 'ASC']));
        $this->assertThrows(InvalidArgumentException::class, fn() => $repo->findPage($all, 1, 0, ['views' => 'ASC', 0 => 'ASC']));
    }

    /** D9: sem partições. */
    private function testPartitioningIsRefused(PDO $pdo): void
    {
        $repo = $this->articleRepository($pdo);
        $this->assertFalse($repo->isNativelyPartitioned());
        $e = $this->assertThrows(PartitioningNotSupportedException::class, fn() => $repo->makePartition());
        $this->assertInstanceOf(PartitionCreationException::class, $e);
        $this->assertInstanceOf(RepositoryException::class, $e);
        $this->assertTrue(str_contains($e->getMessage(), 'aspec_article'));
        $this->assertThrows(PartitionCreationException::class, fn() => $repo->makePartition(Spec::alwaysTrue()));
    }

    /** RN-04: valor só por parâmetro; o texto do SQL nunca leva valor nem nome vindo da especificação. */
    private function testSecurity(): void
    {
        $pdo = new PdoRecordingPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->loadSchema($pdo, 'sqlite');
        $repo = PdoRepository::forEntity($pdo, PdoArticle::class);

        $payload = "x'); DROP TABLE aspec_article; --";
        $repo->put(new PdoArticle($payload, note: "' OR '1'='1"));
        $repo->put(new PdoArticle('innocent'));

        $this->assertEquals($payload, $repo->findSingle(Spec::property('title', Spec::equal($payload)))?->getTitle(), 'o payload é só um valor');
        $this->assertEquals(1, $repo->count(Spec::property('note', Spec::equal("' OR '1'='1"))));
        $this->assertEquals(1, $repo->count(Spec::property('title', Spec::startsWith("x');"))));
        foreach ($pdo->statements as $sql) {
            $this->assertFalse(str_contains($sql, 'DROP'), 'nenhum valor no texto do SQL: ' . $sql);
            $this->assertFalse(str_contains($sql, "'1'='1"), 'nenhum valor no texto do SQL: ' . $sql);
        }
        $this->assertEquals(2, $repo->count(Spec::alwaysTrue()), 'a tabela segue lá');
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $repo->count(Spec::property('title" OR 1=1 --', Spec::equal('x'))), 'nome de propriedade não vira coluna');
    }

    ///////////////////////////////////////////////////////////////////////////
    // RN-05
    ///////////////////////////////////////////////////////////////////////////

    private function testRn05Benchmark(): void
    {
        $pdo = new PdoRecordingPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->loadSchema($pdo, 'sqlite');
        $repo = new PdoCountingRepository($pdo, EntityMapping::fromAttributes(PdoArticle::class));

        $rows = 10000;
        $entities = [];
        for ($i = 0; $i < $rows; $i++) {
            $entities[] = new PdoArticle('Bench ' . $i, PdoArticleStatus::Published, $i, '1.00', $i % 2 === 0, new DateTimeImmutable('2026-01-01 00:00:00Z'), ['t' . ($i % 7)]);
        }
        $middleId = $entities[intdiv($rows, 2)]->getEntityId();
        $t = microtime(true);
        $repo->putAll($entities);
        $putAllMs = (microtime(true) - $t) * 1000;
        unset($entities);
        gc_collect_cycles();

        // iterate: memória constante
        $base = memory_get_usage();
        $peak = 0;
        $n = 0;
        $repo->hydrations = 0;
        $t = microtime(true);
        foreach ($repo->iterate(Spec::alwaysTrue()) as $entity) {
            $n++;
            if ($n % 50 === 0) {
                $peak = max($peak, memory_get_usage() - $base);
            }
        }
        $iterateMs = (microtime(true) - $t) * 1000;
        $this->assertEquals($rows, $n);
        $this->assertEquals($rows, $repo->hydrations);
        $this->assertTrue($peak < 512 * 1024, sprintf('RN-05: iterate em memória constante (pico +%d bytes)', $peak));

        // count: sem hidratar
        $repo->hydrations = 0;
        $pdo->reset();
        $t = microtime(true);
        $this->assertEquals(intdiv($rows, 2), $repo->count(Spec::property('published', Spec::isTrue())));
        $countMs = (microtime(true) - $t) * 1000;
        $this->assertEquals(0, $repo->hydrations, 'RN-05: count() não monta objetos');
        $this->assertCount(1, $pdo->statements);

        // findSingle: uma consulta
        $pdo->reset();
        $t = microtime(true);
        $this->assertEquals('Bench ' . intdiv($rows, 2), $repo->findSingle(new UniqueEntitySpecification($middleId))?->getTitle());
        $singleMs = (microtime(true) - $t) * 1000;
        $this->assertCount(1, $pdo->statements, 'RN-05: findSingle() com uma consulta');

        // Contraste: findAll materializa tudo
        gc_collect_cycles();
        $base = memory_get_usage();
        $t = microtime(true);
        $all = $repo->findAll(Spec::alwaysTrue());
        $findAllMs = (microtime(true) - $t) * 1000;
        $findAllBytes = memory_get_usage() - $base;
        $this->assertCount($rows, $all);
        $this->assertTrue($findAllBytes > 20 * max($peak, 1), 'findAll() cresce com a tabela, iterate() não');
        unset($all);

        fwrite(STDOUT, sprintf(
            "    [RN-05] SQLite, %d linhas: putAll %.0f ms; iterate %.0f ms com pico +%.1f KB; findAll %.0f ms com +%.1f MB; count %.2f ms (0 hidratações, 1 consulta); findSingle %.2f ms (1 consulta, LIMIT 2)\n",
            $rows,
            $putAllMs,
            $iterateMs,
            $peak / 1024,
            $findAllMs,
            $findAllBytes / 1048576,
            $countMs,
            $singleMs
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

    private function loadSchema(PDO $pdo, string $family): void
    {
        $sql = (string) file_get_contents(self::SCHEMA_DIR . "/schema.{$family}.sql");
        $sql = (string) preg_replace('/^--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $pdo->exec($statement);
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

    /** Repositório de PdoArticle sobre a tabela limpa. */
    private function articleRepository(PDO $pdo): PdoRepository
    {
        $pdo->exec('DELETE FROM aspec_article');

        return PdoRepository::forEntity($pdo, PdoArticle::class);
    }

    /**
     * @param iterable<PdoArticle> $articles
     * @return list<string>
     */
    private function titles(iterable $articles): array
    {
        $titles = [];
        foreach ($articles as $article) {
            $titles[] = $article->getTitle();
        }
        sort($titles);

        return $titles;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function sortedKeys(array $value): array
    {
        ksort($value);
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->sortedKeys($v);
            }
        }

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
    // README (1.11.0): exemplo 17 transcrito (EN/pt-BR); cada comentário "// valor" do README vira
    // uma asserção. Única troca: a classe Product é a ReadmeProduct (tests/Support), escrita igual.
    ///////////////////////////////////////////////////////////////////////////

    /** Os três blocos do exemplo 17 são iguais nos dois idiomas, fora os comentários. */
    private function testReadme17BlocksAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        $english = ReadmeBlocks::of($root . '/README.md', 17);
        $portuguese = ReadmeBlocks::of($root . '/README.pt-BR.md', 17);
        $this->assertCount(3, $english, 'três blocos PHP no exemplo 17 (EN)');
        $this->assertCount(3, $portuguese, 'três blocos PHP no exemplo 17 (pt-BR)');
        foreach ($english as $i => $block) {
            $this->assertEquals(ReadmeBlocks::codeTokens($block), ReadmeBlocks::codeTokens($portuguese[$i]), "README 17: bloco {$i} igual fora os comentários");
        }
    }

    /** README 17: mapeamento por atributos, put/find/iterate/count/findPage, bloqueio otimista, regras e builder. */
    private function testReadme17RunsAsWritten(): void
    {
        // --- bloco 1 ---
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE product (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(80) NOT NULL,
    unit_price TEXT NOT NULL, active INTEGER NOT NULL, cost TEXT NOT NULL, version INTEGER NOT NULL)');

        $products = PdoRepository::forEntity($pdo, ReadmeProduct::class);

        $keyboard = new ReadmeProduct('Keyboard', '149.90', cost: '90.00');
        $products->put($keyboard);
        $this->assertEquals(1, $keyboard->getEntityId());
        $this->assertEquals(1, $keyboard->getVersion());
        $products->putAll([new ReadmeProduct('Mouse', '59.90'), new ReadmeProduct('Cable', '19.90', active: false)]);

        $active = Spec::property('active', Spec::isTrue());
        $this->assertEquals(2, $products->count($active));
        $this->assertEquals('59.90', $products->findSingle(Spec::property('name', Spec::equalTo('Mouse')))->price);
        $names = [];
        foreach ($products->iterate($active) as $product) {
            $names[] = $product->name;
        }
        $this->assertEquals(['Keyboard', 'Mouse'], $names);
        $this->assertEquals(['Keyboard'], array_map(fn(ReadmeProduct $p): string => $p->name,
            $products->findPage($active, limit: 1, offset: 1, orderBy: ['name' => 'DESC'])));
        $this->assertEquals('("active" IS NOT NULL AND "active" = 1)', $products->toWhereClause($active)->toSql());

        // Prosa: o custo privado foi gravado pelo mapeamento
        $this->assertEquals('90.00', (string) $pdo->query('SELECT cost FROM product WHERE id = 1')->fetchColumn());

        // --- bloco 2 ---
        $mine   = $products->findById(1);
        $theirs = $products->findById(1);
        $this->assertEquals(1, $mine->getVersion());
        $theirs->price = '139.90';
        $products->update($theirs);
        $this->assertEquals(2, $theirs->getVersion());

        $mine->price = '129.90';
        $caught = false;
        try {
            $products->update($mine);
        } catch (OptimisticLockException $e) {
            $caught = true;
        }
        $this->assertTrue($caught, 'README 17: cópia desatualizada lança OptimisticLockException');
        $this->assertEquals('139.90', $products->findById(1)->price);

        // --- bloco 3 ---
        $cheap = new PredicateSpecification(fn(ReadmeProduct $p): bool => (float) $p->price < 100);
        $caught = false;
        try {
            $products->find($cheap);
        } catch (NonTranslatableSpecificationException $e) {
            $caught = true;
        }
        $this->assertTrue($caught, 'README 17: especificação não traduzível é recusada');
        $this->assertEquals(2, count(PdoRepository::forEntity($pdo, ReadmeProduct::class)->withInMemoryFallback()->find($cheap)));

        $caught = false;
        try {
            $products->find(Spec::property('cost', Spec::greaterThan('50')));
        } catch (NonTranslatableSpecificationException $e) {
            $caught = true;
        }
        $this->assertTrue($caught, 'README 17: propriedade mapeada privada sem getter é recusada');

        $mapping = EntityMapping::for(ReadmeProduct::class)->table('product')->id('id', IdStrategy::Auto)->version('version')
            ->column('name')->column('price', 'unit_price', ColumnType::Decimal)->column('active')->column('cost');
        $this->assertTrue($mapping->equals(EntityMapping::fromAttributes(ReadmeProduct::class)));
        $products = new PdoRepository($pdo, $mapping);
        $this->assertEquals(3, $products->count(Spec::alwaysTrue()));

        // Prosa: o LIKE do SQLite ignora maiúsculas ASCII, a memória não
        $this->assertEquals(1, $products->count(Spec::property('name', Spec::startsWith('mo'))));
        $this->assertFalse(Spec::property('name', Spec::startsWith('mo'))->isSatisfiedBy(new ReadmeProduct('Mouse', '1.00')));
        // Prosa: makePartition() é recusado
        $this->assertThrows(PartitioningNotSupportedException::class, fn() => $products->makePartition());

        // Prosa "Valores nulos" (1.11.0): nulo é valor; comparação não casa, a negação dela casa; um and/or/not
        // dentro da propriedade recusa o nulo; cada comparação sai com a guarda IS NOT NULL
        $noNote = (object) ['note' => null, 'views' => null];
        $this->assertTrue(Spec::property('note', Spec::isNull())->isSatisfiedBy($noNote));
        $this->assertFalse(Spec::property('note', Spec::equalTo('x'))->isSatisfiedBy($noNote));
        $this->assertFalse(Spec::property('note', Spec::in('a', 'b'))->isSatisfiedBy($noNote));
        $this->assertFalse(Spec::property('views', Spec::greaterThan(10))->isSatisfiedBy($noNote));
        $this->assertTrue(Spec::not(Spec::property('views', Spec::greaterThan(10)))->isSatisfiedBy($noNote));
        $this->assertTrue(Spec::anyOf(Spec::property('note', Spec::isNull()), Spec::property('note', Spec::equalTo('n2')))->isSatisfiedBy($noNote));
        $this->assertFalse(Spec::property('note', Spec::anyOf(Spec::isNull(), Spec::equalTo('n2')))->isSatisfiedBy($noNote));
        $this->assertEquals('("views" IS NOT NULL AND "views" > :p1)', Spec::toSql(Spec::property('views', Spec::greaterThan(10)), 'sqlite')->toSql());
        $this->assertEquals('(views IS NULL OR views <= 10)', Spec::toCriteria(Spec::not(Spec::property('views', Spec::greaterThan(10))))->dump());
    }

    ///////////////////////////////////////////////////////////////////////////
    // Semântica de nulo (1.11.0): a mesma especificação dá a mesma resposta em memória, no SQL do
    // visitor, no PdoRepository e no TCriteria, linha a linha, também quando as colunas são NULL.
    ///////////////////////////////////////////////////////////////////////////

    /** Valores das colunas inteiras e da coluna de texto; todas as combinações viram linhas. */
    private const PARITY_INTS = [null, 1, 5, 9];
    private const PARITY_STRINGS = [null, 'apple', 'banana', 'cherry', 'kiwi'];

    /**
     * Prova de paridade da semântica de nulo (1.11.0). Árvores aleatórias com semente fixa sobre três
     * colunas anuláveis (n e m inteiras, s texto): folhas gt/gte/lt/lte/equalTo(v)/equalTo(null)/
     * notEqual/in/in com null/between (allOf de gte e lte)/isNull/isNotNull/startsWith/endsWith/
     * contains sob property(), às vezes compostas dentro da própria property (not/allOf/anyOf/notIn), e
     * combinadas por and/or/not/nor, com e sem because()/withCode(). Para as 80 linhas (NULL em cada
     * coluna), linha a linha:
     *   (a) isSatisfiedBy() em memória, com evaluate() coerente (mesmo veredito, nunca erro), o
     *       predicado compilado pelo visitor ALinq e o simplify() da árvore;
     *   (b) o banco executando `SELECT id ... WHERE <Spec::toSql()>` com os parâmetros ligados;
     *   (c) PdoRepository::find();
     *   (d) o banco executando o dump() do TCriteria (stubs do Adianti).
     * As quatro respostas precisam coincidir em toda árvore e toda linha. Antes da 1.11.0 a memória
     * recusava todo valor nulo e o SQL seguia a lógica de três valores: (a) e (b) divergiam.
     *
     * Por que no Módulo 22: a perna (c) precisa do PdoRepository, e o módulo já tem a infraestrutura
     * dos três bancos (SQLite em memória; PostgreSQL e MySQL descartáveis via run_portability.sh).
     */
    private function testNullSemanticsParity(PDO $pdo, string $family, int $trees = 400): void
    {
        $pdo->exec('DELETE FROM aspec_null_parity');
        $repository = PdoRepository::forEntity($pdo, PdoNullParityRow::class);
        $rows = [];
        $id = 0;
        foreach (self::PARITY_INTS as $n) {
            foreach (self::PARITY_INTS as $m) {
                foreach (self::PARITY_STRINGS as $s) {
                    $rows[] = new PdoNullParityRow(++$id, $n, $m, $s);
                }
            }
        }
        $repository->putAll($rows);
        $this->assertEquals(count($rows), $repository->count(Spec::alwaysTrue()), "{$family}: linhas da paridade gravadas");

        mt_srand(20261011);
        $this->parityAnnotation = 0;
        $violations = [];
        $withNullRow = 0;
        $nullDecided = 0;
        $notOverComparison = 0;

        for ($i = 0; $i < $trees; $i++) {
            $tree = $this->parityTree(3);
            $label = sprintf('#%d %s', $i, (string) $tree);

            // (a) memória
            $memory = [];
            $linq = ALinqSpecificationVisitor::createPredicate($tree);
            $simplified = Spec::simplify($tree);
            foreach ($rows as $row) {
                $satisfied = $tree->isSatisfiedBy($row);
                $result = $tree->evaluate($row);
                if ($result->isError || $result->isSatisfied !== $satisfied) {
                    $violations[] = "{$family} evaluate() ≠ isSatisfiedBy() na linha {$row->id}: {$label}";
                }
                if ($linq($row) !== $satisfied) {
                    $violations[] = "{$family} ALinq ≠ memória na linha {$row->id}: {$label}";
                }
                if ($simplified->isSatisfiedBy($row) !== $satisfied) {
                    $violations[] = "{$family} simplify() ≠ memória na linha {$row->id}: {$label}";
                }
                if ($satisfied) {
                    $memory[] = $row->id;
                }
            }

            // (b) SQL do visitor
            $where = Spec::toSql($tree, $family);
            $statement = $pdo->prepare('SELECT id FROM aspec_null_parity WHERE ' . $where->toSql() . ' ORDER BY id');
            foreach ($where->getParameters() as $name => $value) {
                $statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $statement->execute();
            $sql = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));

            // (c) PdoRepository
            $found = array_map(fn(PdoNullParityRow $r): int => $r->id, $repository->find($tree));
            sort($found);

            // (d) TCriteria
            $criteria = array_map('intval', $pdo->query('SELECT id FROM aspec_null_parity WHERE ' . Spec::toCriteria($tree)->dump() . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));

            foreach (['SQL' => $sql, 'PdoRepository' => $found, 'TCriteria' => $criteria] as $leg => $ids) {
                if ($ids !== $memory) {
                    $violations[] = sprintf(
                        '%s %s ≠ memória: %s | só memória [%s] só %s [%s] | %s',
                        $family,
                        $leg,
                        $label,
                        implode(',', array_diff($memory, $ids)),
                        $leg,
                        implode(',', array_diff($ids, $memory)),
                        $leg === 'TCriteria' ? Spec::toCriteria($tree)->dump() : $where->toSql()
                    );
                }
            }

            // Não vacuidade: a árvore aceitou linhas com NULL, uma linha com NULL decidiu o resultado
            foreach ($memory as $rowId) {
                $row = $rows[$rowId - 1];
                if ($row->n === null || $row->m === null || $row->s === null) {
                    $withNullRow++;
                    break;
                }
            }
            if (preg_match('/NOT \(\(?[`"]?\w+[`"]? IS NOT NULL AND/', $where->toSql()) === 1) {
                $notOverComparison++;
            }
            if (str_contains($label, 'IsNullSpecification') && $memory !== []) {
                $nullDecided++;
            }
        }

        $kinds = [];
        foreach ($violations as $violation) {
            $kind = preg_replace('/(?: na linha \d+)?:.*$/su', '', $violation);
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
        }
        $this->assertEquals([], $violations, json_encode($kinds, JSON_UNESCAPED_UNICODE) . PHP_EOL . implode(PHP_EOL, array_slice($violations, 0, 5)));
        $this->assertTrue($withNullRow > $trees / 4, "{$family}: só {$withNullRow} árvores aceitaram uma linha com NULL");
        $this->assertTrue($notOverComparison > $trees / 20, "{$family}: só {$notOverComparison} árvores com NOT sobre comparação guardada");
        $this->assertTrue($nullDecided > $trees / 20, "{$family}: só {$nullDecided} árvores com isNull() que casaram");
        fwrite(STDOUT, sprintf(
            "    [NULOS] %s: %d árvores × %d linhas, memória = SQL = PdoRepository = TCriteria (%d aceitaram linha com NULL, %d com NOT sobre comparação, %d com isNull() casando)\n",
            $family,
            $trees,
            count($rows),
            $withNullRow,
            $notOverComparison,
            $nullDecided
        ));
    }

    private int $parityAnnotation = 0;

    /** Árvore de linha: property() combinadas por and/or/not/nor. */
    private function parityTree(int $depth): ISpecification
    {
        if ($depth === 0 || mt_rand(0, 9) < 3) {
            return $this->parityAnnotate($this->parityProperty(), 20);
        }

        $d = $depth - 1;
        $tree = match (mt_rand(0, 6)) {
            0, 1 => Spec::not($this->parityTree($d)),
            2 => $this->parityTree($d)->and($this->parityTree($d)),
            3 => $this->parityTree($d)->or($this->parityTree($d)),
            4 => Spec::nor($this->parityTree($d), $this->parityTree($d)),
            5 => Spec::allOf($this->parityTree($d), $this->parityTree($d), $this->parityTree($d)),
            default => Spec::anyOf($this->parityTree($d), $this->parityTree($d)),
        };

        return $this->parityAnnotate($tree, 15);
    }

    /** Uma property() sobre n, m ou s, com folha ou composição de valores dentro. */
    private function parityProperty(): ISpecification
    {
        $column = ['n', 'm', 's'][mt_rand(0, 2)];
        $leaf = fn(): ISpecification => $this->parityAnnotate($column === 's' ? $this->parityStringLeaf() : $this->parityIntLeaf(), 15);
        $inner = match (mt_rand(0, 9)) {
            0 => Spec::not($leaf()),
            1 => Spec::allOf($leaf(), $leaf()),
            2 => Spec::anyOf($leaf(), $leaf()),
            default => $leaf(),
        };

        return Spec::property($column, $this->parityAnnotate($inner, 10));
    }

    private function parityIntLeaf(): ISpecification
    {
        $v = fn(): int => [0, 1, 5, 9, 10][mt_rand(0, 4)];

        return match (mt_rand(0, 13)) {
            0 => Spec::greaterThan($v()),
            1 => Spec::greaterThanOrEqualTo($v()),
            2 => Spec::lessThan($v()),
            3 => Spec::lessThanOrEqualTo($v()),
            4, 5 => Spec::equalTo($v()),
            6 => Spec::equalTo(null),
            7 => Spec::notEqual($v()),
            8 => Spec::in($v(), $v()),
            9 => Spec::in($v(), null),
            10 => Spec::allOf(Spec::greaterThanOrEqualTo(1), Spec::lessThanOrEqualTo($v())),
            11 => Spec::notIn($v(), $v()),
            12 => Spec::isNotNull(),
            default => Spec::isNull(),
        };
    }

    private function parityStringLeaf(): ISpecification
    {
        return match (mt_rand(0, 13)) {
            0 => Spec::startsWith(['a', 'b', 'ch', 'k'][mt_rand(0, 3)]),
            1 => Spec::endsWith(['a', 'e', 'i', 'y'][mt_rand(0, 3)]),
            2, 3 => Spec::contains(['an', 'e', 'i', 'pp'][mt_rand(0, 3)]),
            4, 5 => Spec::equalTo(['apple', 'kiwi', 'zzz'][mt_rand(0, 2)]),
            6 => Spec::equalTo(null),
            7 => Spec::notEqual('apple'),
            8 => Spec::in('apple', 'kiwi'),
            9 => Spec::in('banana', null),
            10 => Spec::greaterThan(['b', 'c'][mt_rand(0, 1)]),
            11 => Spec::lessThan('c'),
            12 => Spec::isNotNull(),
            default => Spec::isNull(),
        };
    }

    /** because() e/ou withCode() com probabilidade (por cento), valores únicos. */
    private function parityAnnotate(ISpecification $node, int $percent): ISpecification
    {
        if (mt_rand(1, 100) > $percent) {
            return $node;
        }
        $n = ++$this->parityAnnotation;

        return match (mt_rand(0, 2)) {
            0 => $node->because("M{$n}"),
            1 => $node->withCode("C{$n}"),
            default => $node->because("M{$n}")->withCode("C{$n}"),
        };
    }
}
