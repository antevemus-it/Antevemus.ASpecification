<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Unit;

use Adianti\Database\TFilter;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Sql\ISqlAccentInsensitiveLike;
use Antevemus\ASpecification\Contracts\Sql\ISqlDialect;
use Antevemus\ASpecification\Criteria\Exceptions\NonTranslatableCriteriaException;
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Linq\ALinqBridge;
use Antevemus\ASpecification\Linq\ALinqSpecificationVisitor;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Column;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Id;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\Table;
use Antevemus\ASpecification\Repositories\Pdo\PdoRepository;
use Antevemus\ASpecification\Spec;
use Antevemus\ASpecification\Specifications\Exceptions\MissingExtensionException;
use Antevemus\ASpecification\Specifications\String\ContainsSpecification;
use Antevemus\ASpecification\Specifications\String\EndsWithSpecification;
use Antevemus\ASpecification\Specifications\String\EqualIgnoreCaseAndAccentsStringSpecification;
use Antevemus\ASpecification\Specifications\String\StartsWithSpecification;
use Antevemus\ASpecification\Specifications\String\TextComparison;
use Antevemus\ASpecification\Sql\Dialects\AnsiSqlDialect;
use Antevemus\ASpecification\Sql\Dialects\MySqlDialect;
use Antevemus\ASpecification\Sql\Dialects\PostgreSqlDialect;
use Antevemus\ASpecification\Sql\Dialects\UnicodeNonspacingMarks;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;
use Antevemus\ASpecification\Tests\TestCase;
use InvalidArgumentException;
use PDO;

use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use function Antevemus\ASpecification\DSL\contains as dslContains;
use function Antevemus\ASpecification\DSL\endsWith as dslEndsWith;
use function Antevemus\ASpecification\DSL\startsWith as dslStartsWith;

/**
 * Module28AccentRow - row of the accent-insensitive repository test (an assigned id and a title).
 *
 * @version 2.1.0
 */
#[Table('aspec_accent_row')]
final class Module28AccentRow extends AbstractEntity
{
    #[Id(strategy: IdStrategy::Assigned)]
    #[Column('id')]
    public int $id;

    #[Column('title')]
    public ?string $title;

    public function __construct(int $id, ?string $title)
    {
        parent::__construct();
        $this->id = $id;
        $this->title = $title;
    }

    public function getEntityId(): int
    {
        return $this->id;
    }
}

/**
 * Module28_AccentInsensitiveTextTest - Comparação de texto sem acento (forward 035, marco 22, 2.1.0).
 *
 * Cobertura:
 * - TextComparison: `true`/`false` continuam CaseSensitive/IgnoreCase (RN-01), inclusive por argumento
 *   nomeado, no Spec, no DSL, na fábrica e nas classes; as respostas e o SQL de 1.14 não mudam
 * - Sem ext-intl (ambiente local): construir qualquer folha IgnoreCaseAndAccents lança
 *   MissingExtensionException citando a extensão (D3); o resto da suíte segue
 * - Com ext-intl: a dobra (NFD, sem \p{Mn}, NFC, minúsculas) e uma bateria pt-BR/es/fr/de com os
 *   limites ß, æ, œ (oráculo escrito à mão), em memória e no ALinq (D2, D6)
 * - SQL (D4): o texto exato no PostgreSQL (expressão embutida e função configurável) e no MySQL/MariaDB
 *   (collation configurável), o ESCAPE do LIKE; recusa em SQLite, SQL Server, Oracle e nos demais
 *   dialetos e num dialeto externo que não implementa ISqlAccentInsensitiveLike
 * - TCriteria (D5): recusa, negada ou não
 * - PdoRepository no SQLite: recusa sem fallback, resposta da memória com fallback
 * - UnicodeNonspacingMarks cobre todo \p{Mn} do PCRE em execução (guarda de deriva)
 * - Exemplo do README ("configuracao" × "Configuração", RN-04)
 * - Paridade em bancos reais (RN-02) quando ASPEC_PDO_PGSQL_DSN / ASPEC_PDO_MYSQL_DSN /
 *   ASPEC_PDO_MARIADB_DSN apontam para eles (tests/Fixtures/pdo_repository/run_portability.sh):
 *   memória = SQL = PdoRepository, coluna a coluna, com os limites documentados como controle positivo
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class Module28_AccentInsensitiveTextTest extends TestCase
{
    /** Valores da bateria (o índice + 1 é o id da linha); null testa a semântica de nulo. */
    public const VALUES = [
        'Configuração', 'configuracao', 'CONFIGURAÇÃO', 'Configurações', 'São Paulo', 'SAO PAULO',
        'Pêssego', 'Avô', 'avó', 'ÓCULOS', 'Señor', 'NIÑO', 'Año', 'ano', 'Élève', 'Crème brûlée',
        'Noël', 'garçon', 'Œuvre', 'oeuvre', 'Müller', 'Muller', 'Mueller', 'Straße', 'Strasse',
        'GROẞE', 'Größe', 'Cæsar', 'Caesar', '50% off', 'a_b', 'axb', null,
    ];

    /** Limites que dependem do banco (controle positivo por família). */
    public const LIMIT_VALUES = ['Søren', 'Soren', 'Łódź', 'Lodz', 'Ðe', 'De', 'Йошкар-Ола', 'иошкар', 'が', 'ガ', '한국어', '하늘'];

    public function run(): void
    {
        $this->testTextComparisonMapsTheBooleans();
        $this->testExistingAnswersAndSqlAreUnchanged();

        if (!TextComparison::isAccentFoldingAvailable()) {
            $this->testRefusedWithoutIntl();
            fwrite(STDOUT, "    [AVISO] ext-intl ausente: a recusa (D3) foi verificada; a bateria sem acento roda num PHP com intl (run_portability.sh).\n");

            return;
        }

        $this->testFolding();
        $this->testMemoryBattery();
        $this->testFactoryDslAndClassesAgree();
        $this->testALinqFollowsMemory();
        $this->testSqlTranslation();
        $this->testUnmeasuredDialectsRefuse();
        $this->testCriteriaRefuses();
        $this->testNonspacingMarksCoverPcre();
        $this->testReadmeExample();
        $this->testReadme20BlocksAreTheSameInBothLanguages();

        if (extension_loaded('pdo_sqlite')) {
            $this->testSqliteRepositoryRefusesUnlessFallback();
        }

        foreach (['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB'] as $family => $label) {
            $pdo = $this->connectFromEnvironment($family);
            if ($pdo === null) {
                fwrite(STDOUT, "    [AVISO] ASPEC_PDO_" . strtoupper($family) . "_DSN não definido; paridade sem acento em {$label} pulada.\n");
                continue;
            }
            $queries = $this->testDatabaseParity($pdo, $family);
            fwrite(STDOUT, "    [2.1.0] {$label} " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ": paridade sem acento OK ({$queries} consultas)\n");
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // RN-01: o que já existia
    ///////////////////////////////////////////////////////////////////////////

    private function testTextComparisonMapsTheBooleans(): void
    {
        $this->assertTrue(TextComparison::of(true) === TextComparison::CaseSensitive);
        $this->assertTrue(TextComparison::of(false) === TextComparison::IgnoreCase);
        $this->assertTrue(TextComparison::of(TextComparison::IgnoreCaseAndAccents) === TextComparison::IgnoreCaseAndAccents);
        $this->assertTrue(TextComparison::CaseSensitive->isCaseSensitive());
        $this->assertFalse(TextComparison::IgnoreCase->isCaseSensitive());
        $this->assertFalse(TextComparison::IgnoreCaseAndAccents->isCaseSensitive());
        $this->assertFalse(TextComparison::IgnoreCase->ignoresAccents());
        $this->assertTrue(TextComparison::IgnoreCaseAndAccents->ignoresAccents());
        $this->assertEquals('ignore_case_and_accents', TextComparison::IgnoreCaseAndAccents->value);
        $this->assertEquals(extension_loaded('intl'), TextComparison::isAccentFoldingAvailable());
    }

    private function testExistingAnswersAndSqlAreUnchanged(): void
    {
        // Mesmas respostas de 1.14: true = sensível, false = só maiúsculas (acento conta)
        $this->assertFalse(Spec::contains('jose', false)->isSatisfiedBy('José'));
        $this->assertTrue(Spec::contains('JOSÉ', false)->isSatisfiedBy('josé'));
        $this->assertFalse(Spec::contains('jos')->isSatisfiedBy('JOSÉ'));
        $this->assertTrue(Spec::startsWith('Caf', caseSensitive: true)->isSatisfiedBy('Café'));
        $this->assertFalse(Spec::startsWith('caf', caseSensitive: true)->isSatisfiedBy('Café'));
        $this->assertTrue(Spec::endsWith('É', caseSensitive: false)->isSatisfiedBy('café'));
        $this->assertTrue(dslContains('ab', false)->isSatisfiedBy('xABy'));
        $this->assertTrue(Spec::getFactory()->startsWith('ab', caseSensitive: false)->isSatisfiedBy('ABc'));
        $this->assertTrue(Spec::equalIgnoreCase('ÁGUA')->isSatisfiedBy('água'));
        $this->assertFalse(Spec::equalIgnoreCase('agua')->isSatisfiedBy('água'));

        $legacy = new ContainsSpecification('ab', false);
        $this->assertFalse($legacy->isCaseSensitive());
        $this->assertFalse($legacy->ignoresAccents());
        $this->assertTrue($legacy->getComparison() === TextComparison::IgnoreCase);
        $this->assertEquals('/ab/iu', $legacy->getPattern());
        $this->assertEquals('/^ab/', (new StartsWithSpecification('ab'))->getPattern());
        $this->assertTrue((new StartsWithSpecification('ab'))->getComparison() === TextComparison::CaseSensitive);

        // O SQL de 1.14 continua o mesmo para true/false
        $sql = fn(ISpecification $spec, string $dialect): string => Spec::toSql(Spec::property('t', $spec), $dialect)->getSql();
        $this->assertEquals('("t" IS NOT NULL AND "t" ILIKE :p1)', $sql(Spec::contains('a', false), 'pgsql'));
        $this->assertEquals('("t" IS NOT NULL AND "t" LIKE :p1)', $sql(Spec::startsWith('a'), 'pgsql'));
        $this->assertEquals('(`t` IS NOT NULL AND LOWER(CONVERT(`t` USING utf8mb4)) COLLATE utf8mb4_bin LIKE LOWER(:p1))', $sql(Spec::endsWith('a', false), 'mysql'));
        $this->assertEquals('("t" IS NOT NULL AND "t" GLOB :p1)', $sql(Spec::contains('a'), 'sqlite'));
        $this->assertEquals('("t" IS NOT NULL AND LOWER("t") LIKE LOWER(:p1) ESCAPE \'!\')', $sql(Spec::equalIgnoreCase('a_b'), 'sqlite'));
        $this->assertEquals('(UPPER(title) LIKE UPPER(\'%ab%\'))', Spec::toCriteria(Spec::property('title', Spec::contains('ab', false)))->dump());
    }

    private function testRefusedWithoutIntl(): void
    {
        $builders = [
            'Spec::contains' => fn() => Spec::contains('configuracao', TextComparison::IgnoreCaseAndAccents),
            'Spec::startsWith' => fn() => Spec::startsWith('configura', TextComparison::IgnoreCaseAndAccents),
            'Spec::endsWith' => fn() => Spec::endsWith('cao', caseSensitive: TextComparison::IgnoreCaseAndAccents),
            'Spec::equalIgnoreCaseAndAccents' => fn() => Spec::equalIgnoreCaseAndAccents('sao paulo'),
            'Spec::equalIgnoringCaseAndAccents' => fn() => Spec::equalIgnoringCaseAndAccents('sao paulo'),
            'DSL contains' => fn() => dslContains('a', TextComparison::IgnoreCaseAndAccents),
            'factory endsWith' => fn() => Spec::getFactory()->endsWith('a', TextComparison::IgnoreCaseAndAccents),
            'new EndsWithSpecification' => fn() => new EndsWithSpecification('a', TextComparison::IgnoreCaseAndAccents),
            'new EqualIgnoreCaseAndAccentsStringSpecification' => fn() => new EqualIgnoreCaseAndAccentsStringSpecification('a'),
            'TextComparison::foldCaseAndAccents' => fn() => TextComparison::foldCaseAndAccents('a'),
        ];
        foreach ($builders as $label => $build) {
            $e = $this->assertThrows(MissingExtensionException::class, $build, "{$label}: sem ext-intl a opção nova é recusada");
            $this->assertEquals('intl', $e->extension, $label);
            $this->assertTrue(str_contains($e->getMessage(), 'ext-intl'), "{$label}: a mensagem cita a extensão");
        }
        // As opções antigas seguem funcionando sem a extensão
        $this->assertTrue(Spec::contains('ab', TextComparison::IgnoreCase)->isSatisfiedBy('xABy'));
        $this->assertTrue(Spec::contains('ab', TextComparison::CaseSensitive)->isSatisfiedBy('xaby'));
    }

    ///////////////////////////////////////////////////////////////////////////
    // D2/D6: memória e ALinq
    ///////////////////////////////////////////////////////////////////////////

    private function testFolding(): void
    {
        $cases = [
            'CONFIGURAÇÃO' => 'configuracao', 'São Paulo' => 'sao paulo', 'NIÑO' => 'nino', 'Crème brûlée' => 'creme brulee',
            'Noël' => 'noel', 'Müller' => 'muller', 'Straße' => 'straße', 'GROẞE' => 'große', 'Cæsar' => 'cæsar',
            'Œuvre' => 'œuvre', 'Søren' => 'søren', 'Łódź' => 'łodz', 'İstanbul' => 'istanbul', 'Tiếng Việt' => 'tieng viet',
            'ΑΘΉΝΑ' => 'αθηνα', 'Йошкар' => 'иошкар', 'が' => 'か', '한국어' => '한국어',
            // decomposto (e + U+0301) e composto dobram igual
            "Cafe\u{0301}" => 'cafe', 'Café' => 'cafe',
        ];
        foreach ($cases as $text => $folded) {
            $this->assertEquals($folded, TextComparison::foldCaseAndAccents($text), "dobra de {$text}");
        }
        $this->assertEquals(null, TextComparison::foldCaseAndAccents("\xC3\x28"), 'UTF-8 inválido não tem dobra');
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::contains("\xC3\x28", TextComparison::IgnoreCaseAndAccents));
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::equalIgnoreCaseAndAccents("\xFF"));
        $this->assertFalse(Spec::contains('a', TextComparison::IgnoreCaseAndAccents)->isSatisfiedBy("a\xFF"), 'candidato inválido não satisfaz');
        $this->assertFalse(Spec::contains('1', TextComparison::IgnoreCaseAndAccents)->isSatisfiedBy(1), 'não-string não satisfaz');
        $this->assertFalse(Spec::equalIgnoreCaseAndAccents('a')->isSatisfiedBy(null));
        // Hangul: a recomposição impede que '하' (parte de '한') seja achado em '한국'
        $this->assertTrue(Spec::contains('한국', TextComparison::IgnoreCaseAndAccents)->isSatisfiedBy('한국어'));
        $this->assertFalse(Spec::contains('하', TextComparison::IgnoreCaseAndAccents)->isSatisfiedBy('한국어'));
        // regex especial no literal é literal
        $this->assertTrue(Spec::contains('a.b/(c)', TextComparison::IgnoreCaseAndAccents)->isSatisfiedBy('XÁ.B/(Ç)'));
        $this->assertFalse(Spec::contains('a.b', TextComparison::IgnoreCaseAndAccents)->isSatisfiedBy('axb'));
    }

    /**
     * The battery: label => [specification, ids expected in memory (1-based, written by hand)].
     *
     * @return array<string, array{0: ISpecification, 1: list<int>}>
     */
    private function battery(): array
    {
        $ai = TextComparison::IgnoreCaseAndAccents;

        return [
            "contains('configuracao')" => [Spec::contains('configuracao', $ai), [1, 2, 3]],
            "contains('CONFIGURACAO')" => [Spec::contains('CONFIGURACAO', $ai), [1, 2, 3]],
            "startsWith('configura')" => [Spec::startsWith('configura', $ai), [1, 2, 3, 4]],
            "endsWith('ÇÕES')" => [Spec::endsWith('ÇÕES', $ai), [4]],
            "endsWith('cao')" => [Spec::endsWith('cao', $ai), [1, 2, 3]],
            "equal('sao paulo')" => [Spec::equalIgnoreCaseAndAccents('sao paulo'), [5, 6]],
            "equal('CONFIGURACAO')" => [Spec::equalIgnoreCaseAndAccents('CONFIGURACAO'), [1, 2, 3]],
            "contains('pessego')" => [Spec::contains('pessego', $ai), [7]],
            "equal('avo')" => [Spec::equalIgnoreCaseAndAccents('avo'), [8, 9]],
            "startsWith('oculos')" => [Spec::startsWith('oculos', $ai), [10]],
            "contains('senor')" => [Spec::contains('senor', $ai), [11]],
            "contains('nino')" => [Spec::contains('nino', $ai), [12]],
            "equal('ano')" => [Spec::equalIgnoreCaseAndAccents('ano'), [13, 14]],
            "contains('ELEVE')" => [Spec::contains('ELEVE', $ai), [15]],
            "contains('creme brulee')" => [Spec::contains('creme brulee', $ai), [16]],
            "endsWith('noel')" => [Spec::endsWith('noel', $ai), [17]],
            "contains('garcon')" => [Spec::contains('garcon', $ai), [18]],
            "contains('ç')" => [Spec::contains('ç', $ai), [1, 2, 3, 4, 10, 16, 18, 28, 29]],
            "contains('œuvre')" => [Spec::contains('œuvre', $ai), [19]],
            "contains('oeuvre')" => [Spec::contains('oeuvre', $ai), [20]],
            "contains('muller')" => [Spec::contains('muller', $ai), [21, 22]],
            "contains('mueller')" => [Spec::contains('mueller', $ai), [23]],
            "contains('strasse')" => [Spec::contains('strasse', $ai), [25]],
            "contains('straße')" => [Spec::contains('straße', $ai), [24]],
            "contains('ß')" => [Spec::contains('ß', $ai), [24, 26, 27]],
            "contains('ss')" => [Spec::contains('ss', $ai), [7, 25]],
            "equal('große')" => [Spec::equalIgnoreCaseAndAccents('große'), [26, 27]],
            "contains('grosse')" => [Spec::contains('grosse', $ai), []],
            "contains('caesar')" => [Spec::contains('caesar', $ai), [29]],
            "contains('CÆSAR')" => [Spec::contains('CÆSAR', $ai), [28]],
            "contains('ae')" => [Spec::contains('ae', $ai), [29]],
            "contains('%')" => [Spec::contains('%', $ai), [30]],
            "equal('50% OFF')" => [Spec::equalIgnoreCaseAndAccents('50% OFF'), [30]],
            "startsWith('A_')" => [Spec::startsWith('A_', $ai), [31]],
            "not(contains('configuracao'))" => [Spec::not(Spec::contains('configuracao', $ai)), [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32]],
            "not(equal('ano'))" => [Spec::not(Spec::equalIgnoreCaseAndAccents('ano')), [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32]],
            "contains('ão') or startsWith('NI')" => [Spec::contains('ão', $ai)->or(Spec::startsWith('NI', $ai)), [1, 2, 3, 5, 6, 12]],
        ];
    }

    /**
     * Limit queries over LIMIT_VALUES: label => [specification, expected in memory].
     *
     * @return array<string, array{0: ISpecification, 1: list<int>}>
     */
    private function limitBattery(): array
    {
        $ai = TextComparison::IgnoreCaseAndAccents;

        return [
            "contains('soren')" => [Spec::contains('soren', $ai), [2]],
            "contains('ø')" => [Spec::contains('ø', $ai), [1]],
            "contains('lodz')" => [Spec::contains('lodz', $ai), [4]],
            "contains('ł')" => [Spec::contains('ł', $ai), [3]],
            "equal('de')" => [Spec::equalIgnoreCaseAndAccents('de'), [6]],
            "contains('иошкар')" => [Spec::contains('иошкар', $ai), [7, 8]],
            "equal('か')" => [Spec::equalIgnoreCaseAndAccents('か'), [9]],
            "contains('한국')" => [Spec::contains('한국', $ai), [11]],
            "contains('하')" => [Spec::contains('하', $ai), [12]],
        ];
    }

    /**
     * @param list<string|null> $values
     * @return list<int>
     */
    private function memoryIds(ISpecification $spec, array $values): array
    {
        $ids = [];
        foreach ($values as $i => $value) {
            if (Spec::property('t', $spec)->isSatisfiedBy((object) ['t' => $value])) {
                $ids[] = $i + 1;
            }
        }

        return $ids;
    }

    private function testMemoryBattery(): void
    {
        foreach ($this->battery() as $label => [$spec, $expected]) {
            $this->assertEquals($expected, $this->memoryIds($spec, self::VALUES), "memória: {$label}");
        }
        foreach ($this->limitBattery() as $label => [$spec, $expected]) {
            $this->assertEquals($expected, $this->memoryIds($spec, self::LIMIT_VALUES), "memória (limites): {$label}");
        }
    }

    private function testFactoryDslAndClassesAgree(): void
    {
        $ai = TextComparison::IgnoreCaseAndAccents;
        $routes = [
            'Spec' => [Spec::contains('cao', $ai), Spec::startsWith('sao', $ai), Spec::endsWith('PAULO', $ai)],
            'named' => [Spec::contains('cao', caseSensitive: $ai), Spec::startsWith(prefix: 'sao', caseSensitive: $ai), Spec::endsWith('PAULO', caseSensitive: $ai)],
            'DSL' => [dslContains('cao', $ai), dslStartsWith('sao', $ai), dslEndsWith('PAULO', $ai)],
            'factory' => [Spec::getFactory()->contains('cao', $ai), Spec::getFactory()->startsWith('sao', $ai), Spec::getFactory()->endsWith('PAULO', $ai)],
            'string()' => [Spec::getFactory()->string()->contains('cao', $ai), Spec::getFactory()->string()->startsWith('sao', $ai), Spec::getFactory()->string()->endsWith('PAULO', $ai)],
            'class' => [new ContainsSpecification('cao', $ai), new StartsWithSpecification('sao', $ai), new EndsWithSpecification('PAULO', $ai)],
        ];
        foreach ($routes as $route => [$contains, $starts, $ends]) {
            $this->assertTrue($contains->isSatisfiedBy('Configuração'), "{$route} contains");
            $this->assertTrue($starts->isSatisfiedBy('São Paulo'), "{$route} startsWith");
            $this->assertTrue($ends->isSatisfiedBy('São Paulo'), "{$route} endsWith");
            $this->assertFalse($starts->isSatisfiedBy('Paulo São'), "{$route} startsWith ancora");
            $this->assertTrue($contains->ignoresAccents() && $contains->getComparison() === $ai, $route);
            $this->assertFalse($contains->isCaseSensitive(), $route);
            $this->assertEquals('cao', $contains->getLiteral(), "{$route}: o literal fica como escrito");
        }
        $this->assertEquals('/configuracao$/u', Spec::endsWith('CONFIGURAÇÃO', $ai)->getPattern(), 'o padrão é escrito sobre o literal dobrado');
        foreach ([Spec::equalIgnoreCaseAndAccents('SÃO PAULO'), Spec::equalIgnoringCaseAndAccents('SÃO PAULO'), Spec::getFactory()->equalIgnoringCaseAndAccents('SÃO PAULO'), Spec::getFactory()->string()->equalIgnoringCaseAndAccents('SÃO PAULO')] as $i => $equal) {
            $this->assertInstanceOf(EqualIgnoreCaseAndAccentsStringSpecification::class, $equal);
            $this->assertTrue($equal->isSatisfiedBy('sao paulo'), "equal #{$i}");
            $this->assertFalse($equal->isSatisfiedBy('sao paulo '), "equal #{$i}: espaço conta");
            $this->assertEquals('SÃO PAULO', $equal->getValue());
        }
    }

    private function testALinqFollowsMemory(): void
    {
        foreach ($this->battery() as $label => [$spec, $expected]) {
            $predicate = ALinqSpecificationVisitor::createPredicate(Spec::property('t', $spec));
            $ids = [];
            foreach (self::VALUES as $i => $value) {
                if ($predicate((object) ['t' => $value])) {
                    $ids[] = $i + 1;
                }
            }
            $this->assertEquals($expected, $ids, "ALinq: {$label}");
        }

        // Pela coleção, quando a ALinq (PHP 8.4+) está disponível
        if (ALinqBridge::isAvailable() && PHP_VERSION_ID >= 80400) {
            $rows = array_map(fn(?string $t): object => (object) ['t' => $t], self::VALUES);
            $found = ALinqBridge::filter($rows, Spec::property('t', Spec::contains('configuracao', TextComparison::IgnoreCaseAndAccents)))
                ->select(fn(object $r): ?string => $r->t)->toArray();
            $this->assertEquals(['Configuração', 'configuracao', 'CONFIGURAÇÃO'], array_values($found));
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // D4: SQL
    ///////////////////////////////////////////////////////////////////////////

    private function testSqlTranslation(): void
    {
        $ai = TextComparison::IgnoreCaseAndAccents;
        $fold = fn(string $x): string => PostgreSqlDialect::builtInAccentFolding($x);
        $marks = "E'" . str_replace('\\', '\\\\', UnicodeNonspacingMarks::bracketExpression()) . "'";
        $this->assertEquals("lower(normalize(regexp_replace(normalize(\"t\", NFD), {$marks}, '', 'g'), NFC))", $fold('"t"'));
        $this->assertTrue(str_starts_with($marks, "E'[\\\\u0300-\\\\u036F\\\\u0483-\\\\u0487"), 'escapes dobrados num literal E');

        $pg = Spec::toSql(Spec::property('t', Spec::contains('configuracao', $ai)), 'pgsql');
        $this->assertEquals('("t" IS NOT NULL AND ' . $fold('"t"') . ' LIKE ' . $fold(':p1') . ')', $pg->getSql());
        $this->assertEquals([':p1' => '%configuracao%'], $pg->getParameters(), 'o padrão vai como escrito; o banco dobra os dois lados');

        $pgEscaped = Spec::toSql(Spec::property('t', Spec::startsWith('50%_', $ai)), 'pgsql');
        $this->assertEquals('("t" IS NOT NULL AND ' . $fold('"t"') . ' LIKE ' . $fold(':p1') . " ESCAPE '!')", $pgEscaped->getSql());
        $this->assertEquals([':p1' => '50!%!_%'], $pgEscaped->getParameters());

        $pgEqual = Spec::toSql(Spec::property('t', Spec::equalIgnoreCaseAndAccents('a_b')), new PostgreSqlDialect('public.f_fold'));
        $this->assertEquals("(\"t\" IS NOT NULL AND public.f_fold(lower(\"t\")) LIKE public.f_fold(lower(:p1)) ESCAPE '!')", $pgEqual->getSql());
        $this->assertEquals([':p1' => 'a!_b'], $pgEqual->getParameters());
        $this->assertEquals('unaccent', (new PostgreSqlDialect('unaccent'))->getAccentFoldingFunction());
        $this->assertEquals(null, (new PostgreSqlDialect())->getAccentFoldingFunction());
        foreach (['unaccent(x)', 'a b', 'x;drop', '1fn', 'a.b.c', ''] as $bad) {
            $this->assertThrows(InvalidArgumentException::class, fn() => new PostgreSqlDialect($bad), "função recusada: {$bad}");
        }

        $my = Spec::toSql(Spec::property('t', Spec::endsWith('cao', $ai)), 'mysql');
        $this->assertEquals('(`t` IS NOT NULL AND CONVERT(`t` USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE :p1)', $my->getSql());
        $this->assertEquals([':p1' => '%cao'], $my->getParameters());
        $maria = Spec::toSql(Spec::property('t', Spec::not(Spec::equalIgnoreCaseAndAccents('50%'))), new MySqlDialect('utf8mb4_uca1400_ai_ci'));
        $this->assertEquals("(`t` IS NOT NULL AND NOT (CONVERT(`t` USING utf8mb4) COLLATE utf8mb4_uca1400_ai_ci LIKE :p1 ESCAPE '!'))", $maria->getSql());
        $this->assertEquals('utf8mb4_0900_ai_ci', (new MySqlDialect())->getAccentInsensitiveCollation());
        foreach (['latin1_general_ci', 'utf8mb4_bin; DROP', 'utf8mb4_', ''] as $bad) {
            $this->assertThrows(InvalidArgumentException::class, fn() => new MySqlDialect($bad), "collation recusada: {$bad}");
        }
        $this->assertTrue(new MySqlDialect() instanceof ISqlAccentInsensitiveLike);
    }

    private function testUnmeasuredDialectsRefuse(): void
    {
        $leaves = [
            'contains' => Spec::contains('configuracao', TextComparison::IgnoreCaseAndAccents),
            'equal' => Spec::equalIgnoreCaseAndAccents('sao paulo'),
            'not(startsWith)' => Spec::not(Spec::startsWith('sao', TextComparison::IgnoreCaseAndAccents)),
        ];
        foreach (['sqlite', 'sqlsrv', 'oracle', 'firebird', 'db2', 'informix', 'duckdb', 'ansi'] as $dialect) {
            foreach ($leaves as $label => $leaf) {
                $e = $this->assertThrows(
                    NonTranslatableSpecificationException::class,
                    fn() => Spec::toSql(Spec::property('t', $leaf), $dialect),
                    "{$dialect}: {$label} é recusado"
                );
                $this->assertTrue(str_contains($e->getMessage(), 'ignores case and accents'), "{$dialect}: mensagem");
            }
        }

        // Um dialeto externo que só implementa ISqlDialect (2.0) também recusa
        $external = new class implements ISqlDialect {
            public function getFamily(): string { return 'external'; }
            public function escapeIdentifier(string $identifier): string { return '"' . $identifier . '"'; }
            public function formatBoolean(bool $value): string { return $value ? '1' : '0'; }
            public function getTrueCondition(): string { return '1 = 1'; }
            public function getFalseCondition(): string { return '1 = 0'; }
            public function formatLike(string $column, string $paramPlaceholder, bool $caseSensitive = true): string { return "{$column} LIKE {$paramPlaceholder}"; }
            public function formatRegex(string $column, string $paramPlaceholder, bool $caseSensitive = true): string { return "{$column} ~ {$paramPlaceholder}"; }
            public function supportsRegex(): bool { return true; }
        };
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => Spec::toSql(Spec::property('t', $leaves['contains']), $external));
        $this->assertEquals('("t" IS NOT NULL AND "t" LIKE :p1)', Spec::toSql(Spec::property('t', Spec::contains('a')), $external)->getSql(), 'o resto do dialeto externo segue igual');
        $this->assertFalse((new AnsiSqlDialect()) instanceof ISqlDialect && (new AnsiSqlDialect())->formatAccentInsensitiveLike('"t"', ':p1') !== null);
    }

    private function testCriteriaRefuses(): void
    {
        if (!class_exists(TFilter::class)) {
            return;
        }
        $ai = TextComparison::IgnoreCaseAndAccents;
        $trees = [
            'contains' => Spec::property('title', Spec::contains('configuracao', $ai)),
            'equal' => Spec::property('title', Spec::equalIgnoreCaseAndAccents('sao paulo')),
            'not(contains)' => Spec::property('title', Spec::not(Spec::contains('configuracao', $ai))),
            'not(property(endsWith))' => Spec::not(Spec::property('title', Spec::endsWith('cao', $ai))),
            'not(equal)' => Spec::not(Spec::property('title', Spec::equalIgnoreCaseAndAccents('a'))),
            'and' => Spec::property('id', Spec::greaterThan(1))->and(Spec::property('title', Spec::startsWith('sao', $ai))),
        ];
        foreach ($trees as $label => $tree) {
            $e = $this->assertThrows(NonTranslatableCriteriaException::class, fn() => Spec::toCriteria($tree), "TCriteria: {$label}");
            $this->assertTrue(str_contains($e->getMessage(), 'ignores case and accents'), "TCriteria: {$label} (mensagem)");
        }
    }

    private function testNonspacingMarksCoverPcre(): void
    {
        $inRanges = static function (int $codePoint): bool {
            foreach (UnicodeNonspacingMarks::RANGES as [$first, $last]) {
                if ($codePoint >= $first && $codePoint <= $last) {
                    return true;
                }
            }

            return false;
        };
        $missing = [];
        $strangers = [];
        foreach ([[0x0000, 0xFFFF], [0x10000, 0x1FFFF], [0xE0000, 0xE01EF]] as [$from, $to]) {
            for ($cp = $from; $cp <= $to; $cp++) {
                if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                    continue;
                }
                $character = mb_chr($cp, 'UTF-8');
                $isMark = preg_match('/^\p{Mn}$/u', $character) === 1;
                $listed = $inRanges($cp);
                if ($isMark && !$listed) {
                    $missing[] = sprintf('U+%04X', $cp);
                } elseif ($listed && !$isMark && preg_match('/^\p{Cn}$/u', $character) !== 1) {
                    $strangers[] = sprintf('U+%04X', $cp);
                }
            }
        }
        $this->assertEquals([], $missing, 'todo \p{Mn} do PCRE ' . PCRE_VERSION . ' está em UnicodeNonspacingMarks (regenere a lista)');
        $this->assertEquals([], $strangers, 'a lista só tem marcas (ou pontos ainda não atribuídos nesta versão do Unicode)');
        $previous = -1;
        foreach (UnicodeNonspacingMarks::RANGES as [$first, $last]) {
            $this->assertTrue($first > $previous && $last >= $first, 'faixas ordenadas e disjuntas');
            $previous = $last;
        }
    }

    /**
     * RN-04: o exemplo do README ("configuracao" acha "Configuração"), como está escrito.
     */
    /** README 20 (2.1.0): o bloco PHP é igual nos dois idiomas, fora os comentários. */
    private function testReadme20BlocksAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        $english = ReadmeBlocks::of($root . '/README.md', 20);
        $portuguese = ReadmeBlocks::of($root . '/README.pt-BR.md', 20);
        $this->assertCount(1, $english, 'um bloco PHP no exemplo 20 (EN)');
        $this->assertCount(1, $portuguese, 'um bloco PHP no exemplo 20 (pt-BR)');
        $this->assertEquals(ReadmeBlocks::codeTokens($english[0]), ReadmeBlocks::codeTokens($portuguese[0]), 'README 20: bloco igual fora os comentários');
        $this->assertTrue(str_contains($english[0], "Spec::contains('configuracao', TextComparison::IgnoreCaseAndAccents)"), 'README 20: o bloco é o exemplo executado abaixo');
    }

    private function testReadmeExample(): void
    {
        // --- README (2.1.0): Accent-insensitive text ---
        $search = Spec::property('title', Spec::contains('configuracao', TextComparison::IgnoreCaseAndAccents));

        $a = $search->isSatisfiedBy((object) ['title' => 'Configuração do servidor']); // true
        $b = $search->isSatisfiedBy((object) ['title' => 'CONFIGURAÇÃO']);             // true
        $c = Spec::contains('configuracao', false)->isSatisfiedBy('Configuração');     // false: IgnoreCase compares accents
        $d = Spec::contains('strasse', TextComparison::IgnoreCaseAndAccents)
            ->isSatisfiedBy('Straße');                                                  // false: ß is a letter, not s + mark
        $e = Spec::equalIgnoreCaseAndAccents('sao paulo')->isSatisfiedBy('SÃO PAULO');  // true

        $mysql = Spec::toSql($search, 'mysql')->getSql();
        // (`title` IS NOT NULL AND CONVERT(`title` USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE :p1)
        try {
            Spec::toSql($search, 'sqlite');
            $refused = false;
        } catch (NonTranslatableSpecificationException) {
            $refused = true;                                                            // SQLite cannot answer it
        }
        // --- fim do exemplo ---

        $this->assertTrue($a);
        $this->assertTrue($b);
        $this->assertFalse($c);
        $this->assertFalse($d);
        $this->assertTrue($e);
        $this->assertEquals('(`title` IS NOT NULL AND CONVERT(`title` USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE :p1)', $mysql);
        $this->assertTrue($refused);
    }

    ///////////////////////////////////////////////////////////////////////////
    // PdoRepository e bancos reais
    ///////////////////////////////////////////////////////////////////////////

    private function testSqliteRepositoryRefusesUnlessFallback(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE aspec_accent_row (id INTEGER PRIMARY KEY, title TEXT NULL)');
        $repository = PdoRepository::forEntity($pdo, Module28AccentRow::class);
        $repository->putAll(array_map(fn(int $i, ?string $t): Module28AccentRow => new Module28AccentRow($i + 1, $t), array_keys(self::VALUES), self::VALUES));
        $spec = Spec::property('title', Spec::contains('configuracao', TextComparison::IgnoreCaseAndAccents));

        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $repository->findAll($spec), 'SQLite recusa');
        $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $repository->count($spec));
        $repository->withInMemoryFallback();
        $this->assertEquals([1, 2, 3], array_map(fn(Module28AccentRow $r): int => $r->id, $repository->findAll($spec)), 'com fallback, a resposta da memória');
        $this->assertEquals(3, $repository->count($spec));
    }

    private function connectFromEnvironment(string $family): ?PDO
    {
        $prefix = 'ASPEC_PDO_' . strtoupper($family);
        $dsn = getenv($prefix . '_DSN');
        $driver = $family === 'mariadb' ? 'mysql' : $family;
        if ($dsn === false || $dsn === '' || !extension_loaded('pdo_' . $driver)) {
            return null;
        }
        $user = getenv($prefix . '_USER');
        $password = getenv($prefix . '_PASSWORD');

        return new PDO($dsn, $user === false ? null : $user, $password === false ? null : $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    /**
     * RN-02: each query selects in the database the rows memory accepts, on every column the family
     * offers; the documented limits are checked as positive controls. Returns the number of queries run.
     */
    private function testDatabaseParity(PDO $pdo, string $family): int
    {
        $isPg = $family === 'pgsql';
        $columns = $isPg
            ? ['df' => 'TEXT', 'vc' => 'VARCHAR(40)', 'cc' => 'VARCHAR(40) COLLATE "C"']
            : [
                'bn' => 'VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin',
                'df' => 'VARCHAR(40) CHARACTER SET utf8mb4',
                'uc' => 'VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                'l1' => 'VARCHAR(40) CHARACTER SET latin1',
            ];
        $dialects = $isPg ? ['built-in' => new PostgreSqlDialect()] : ['utf8mb4_0900_ai_ci' => new MySqlDialect()];
        if ($family === 'mariadb') {
            $dialects['utf8mb4_uca1400_ai_ci'] = new MySqlDialect('utf8mb4_uca1400_ai_ci');
        }
        if ($isPg) {
            // D7: um invólucro IMMUTABLE da expressão embutida aceita um índice de expressão
            $pdo->exec('DROP FUNCTION IF EXISTS aspec_fold(text) CASCADE');
            $pdo->exec('CREATE FUNCTION aspec_fold(t text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE RETURN ' . PostgreSqlDialect::builtInAccentFolding('t'));
            $dialects['aspec_fold()'] = new PostgreSqlDialect('aspec_fold');
        }

        $queries = 0;
        foreach (['main' => [self::VALUES, $this->battery()], 'limits' => [self::LIMIT_VALUES, $this->limitBattery()]] as $set => [$values, $battery]) {
            $pdo->exec('DROP TABLE IF EXISTS aspec_accent_parity');
            $definition = implode(', ', array_map(fn(string $c, string $t): string => "{$c} {$t} NULL", array_keys($columns), $columns));
            $pdo->exec("CREATE TABLE aspec_accent_parity (id INT PRIMARY KEY, {$definition})");
            if ($isPg) {
                $pdo->exec('CREATE INDEX aspec_accent_parity_fold ON aspec_accent_parity (aspec_fold(lower(df)))');
            }
            $insert = $pdo->prepare('INSERT INTO aspec_accent_parity VALUES (?' . str_repeat(', ?', count($columns)) . ')');
            foreach ($values as $i => $value) {
                $row = [$i + 1];
                foreach ($columns as $type) {
                    $row[] = str_contains($type, 'latin1') && $value !== null && mb_convert_encoding(mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8'), 'UTF-8', 'ISO-8859-1') !== $value
                        ? null
                        : $value;
                }
                $insert->execute($row);
            }

            $mismatches = [];
            $limited = [];
            foreach ($battery as $label => [$spec]) {
                foreach (array_keys($columns) as $column) {
                    $stored = $this->storedValues($values, $columns[$column]);
                    $expected = $this->memoryIds($spec, $stored);
                    foreach ($dialects as $dialectLabel => $dialect) {
                        $clause = Spec::toSql(Spec::property($column, $spec), $dialect);
                        $statement = $pdo->prepare("SELECT id FROM aspec_accent_parity WHERE {$clause->getSql()} ORDER BY id");
                        $statement->execute($clause->getParameters());
                        $actual = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
                        $queries++;
                        $line = "{$family}/{$dialectLabel} {$label} on {$column}: memória [" . implode(',', $expected) . '] SQL [' . implode(',', $actual) . ']';
                        if ($this->isDocumentedLimit($family, $dialectLabel, $set, $label, $column)) {
                            $limited[$line] = $actual !== $expected;
                        } elseif ($actual !== $expected) {
                            $mismatches[] = $line;
                        }
                    }
                }
            }
            $pdo->exec('DROP TABLE aspec_accent_parity');
            $this->assertEquals([], $mismatches, "RN-02: sem acento, o SQL de {$family} seleciona o que a memória aceita:\n  " . implode("\n  ", $mismatches));
            // Controle positivo: onde o limite está documentado, ele é observado
            $unobserved = array_keys(array_filter($limited, fn(bool $observed): bool => !$observed));
            $this->assertEquals([], $unobserved, "limites documentados de {$family} ({$set}) que não se observaram:\n  " . implode("\n  ", $unobserved));
        }

        if ($isPg) {
            $queries += $this->testUnaccentOptionIsTheDocumentedOne($pdo);
            $pdo->exec('DROP FUNCTION IF EXISTS aspec_fold(text) CASCADE');
        }
        $queries += $this->testRepositoryParity($pdo, $family, $dialects[array_key_first($dialects)]);

        return $queries;
    }

    /**
     * @param list<string|null> $values
     * @return list<string|null> What a column of the type stores (a latin1 column stores NULL for text it cannot hold)
     */
    private function storedValues(array $values, string $type): array
    {
        if (!str_contains($type, 'latin1')) {
            return $values;
        }

        return array_map(
            fn(?string $v): ?string => $v !== null && mb_convert_encoding(mb_convert_encoding($v, 'ISO-8859-1', 'UTF-8'), 'UTF-8', 'ISO-8859-1') !== $v ? null : $v,
            $values
        );
    }

    /**
     * The documented limits: on MySQL/MariaDB the Unicode collation algorithm is not the decomposition
     * (letters with a stroke and ð match their base letter, й does not match и, katakana matches
     * hiragana); on a PostgreSQL "C" column lower() folds ASCII letters only (1.14.0).
     */
    private function isDocumentedLimit(string $family, string $dialect, string $set, string $label, string $column): bool
    {
        if ($family === 'pgsql') {
            // the column side is lowered under "C" (ASCII only); the pattern side and a wrapper
            // function lower under the database default, so only an upper-case letter without
            // decomposition stored in the "C" column stays apart
            return $dialect === 'built-in' && $column === 'cc'
                && in_array($label, ["contains('œuvre')", "equal('große')", "contains('ß')", "contains('ł')", "contains('иошкар')"], true);
        }

        return $set === 'limits' && in_array($label, ["contains('soren')", "contains('ø')", "contains('lodz')", "contains('ł')", "equal('de')", "contains('иошкар')", "equal('か')"], true)
            && !($column === 'l1' && in_array($label, ["contains('lodz')", "contains('иошкар')", "equal('か')"], true));
    }

    /**
     * PostgreSQL with `unaccent` as the folding function (D4's option): the accents of the battery fold,
     * and, as documented, ß/æ/œ are expanded (`strasse` finds `Straße`), which memory does not do.
     */
    private function testUnaccentOptionIsTheDocumentedOne(PDO $pdo): int
    {
        $pdo->exec('CREATE EXTENSION IF NOT EXISTS unaccent');
        $pdo->exec('DROP TABLE IF EXISTS aspec_accent_parity');
        $pdo->exec('CREATE TABLE aspec_accent_parity (id INT PRIMARY KEY, df TEXT NULL)');
        $insert = $pdo->prepare('INSERT INTO aspec_accent_parity VALUES (?, ?)');
        foreach (self::VALUES as $i => $value) {
            $insert->execute([$i + 1, $value]);
        }
        $dialect = new PostgreSqlDialect('unaccent');
        $expanding = ["contains('strasse')", "contains('straße')", "contains('ß')", "contains('ss')", "contains('grosse')", "equal('große')", "contains('caesar')", "contains('CÆSAR')", "contains('ae')", "contains('œuvre')", "contains('oeuvre')"];
        $queries = 0;
        foreach ($this->battery() as $label => [$spec, $expected]) {
            $clause = Spec::toSql(Spec::property('df', $spec), $dialect);
            $statement = $pdo->prepare("SELECT id FROM aspec_accent_parity WHERE {$clause->getSql()} ORDER BY id");
            $statement->execute($clause->getParameters());
            $actual = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
            $queries++;
            if (in_array($label, $expanding, true)) {
                continue;
            }
            $this->assertEquals($expected, $actual, "unaccent: {$label}");
        }
        $strasse = Spec::toSql(Spec::property('df', Spec::contains('strasse', TextComparison::IgnoreCaseAndAccents)), $dialect);
        $statement = $pdo->prepare("SELECT id FROM aspec_accent_parity WHERE {$strasse->getSql()} ORDER BY id");
        $statement->execute($strasse->getParameters());
        $this->assertEquals([24, 25], array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)), 'unaccent expande ß (documentado; a memória acha só [25])');
        $pdo->exec('DROP TABLE aspec_accent_parity');

        return $queries + 1;
    }

    private function testRepositoryParity(PDO $pdo, string $family, ISqlDialect $dialect): int
    {
        $pdo->exec('DROP TABLE IF EXISTS aspec_accent_row');
        $pdo->exec('CREATE TABLE aspec_accent_row (id INT PRIMARY KEY, title ' . ($family === 'pgsql' ? 'TEXT' : 'VARCHAR(40) CHARACTER SET utf8mb4') . ' NULL)');
        $repository = PdoRepository::forEntity($pdo, Module28AccentRow::class, dialect: $dialect);
        $repository->putAll(array_map(fn(int $i, ?string $t): Module28AccentRow => new Module28AccentRow($i + 1, $t), array_keys(self::VALUES), self::VALUES));
        $queries = 0;
        foreach ($this->battery() as $label => [$spec, $expected]) {
            $found = array_map(fn(Module28AccentRow $r): int => $r->id, $repository->findAll(Spec::property('title', $spec)));
            sort($found);
            $this->assertEquals($expected, $found, "PdoRepository {$family}: {$label}");
            $queries++;
        }
        $pdo->exec('DROP TABLE aspec_accent_row');

        return $queries;
    }
}
