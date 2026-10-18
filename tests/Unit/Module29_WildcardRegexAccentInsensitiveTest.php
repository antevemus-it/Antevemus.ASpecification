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
use Antevemus\ASpecification\Specifications\String\RegexSpecification;
use Antevemus\ASpecification\Specifications\String\TextComparison;
use Antevemus\ASpecification\Specifications\String\WildcardExpressionMatcherIgnoreCaseStringSpecification;
use Antevemus\ASpecification\Specifications\String\WildcardSpecification;
use Antevemus\ASpecification\Sql\Dialects\AnsiSqlDialect;
use Antevemus\ASpecification\Sql\Dialects\MySqlDialect;
use Antevemus\ASpecification\Sql\Dialects\PostgreSqlDialect;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;
use Antevemus\ASpecification\Sql\Exceptions\UnsupportedSqlOperationException;
use Antevemus\ASpecification\Tests\Support\ReadmeBlocks;
use Antevemus\ASpecification\Tests\TestCase;
use InvalidArgumentException;
use PDO;

use function Antevemus\ASpecification\DSL\matches as dslMatches;
use function Antevemus\ASpecification\DSL\matchesWildcardExpression as dslMatchesWildcardExpression;
use function Antevemus\ASpecification\DSL\matchesWildcardExpressionIgnoringCase as dslMatchesWildcardExpressionIgnoringCase;

/**
 * Module29AccentRow - row of the repository test of Module 29 (an assigned id and a title).
 *
 * @version 2.3.0
 */
#[Table('aspec_accent_row29')]
final class Module29AccentRow extends AbstractEntity
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
 * Module29_WildcardRegexAccentInsensitiveTest - Wildcard e regex sem acento (forward 039, marco 22b, 2.3.0).
 *
 * Cobertura:
 * - RN-01: wildcard(), wildcardIgnoreCase() e regex() sem o argumento novo respondem e geram SQL/TCriteria
 *   como na 2.2.0; `wildcard($p, false)` = wildcardIgnoreCase(); `regex($p, IgnoreCase)` acrescenta `i`
 * - Sem ext-intl (ambiente local): toda entrada com IgnoreCaseAndAccents (Spec, DSL, fábricas, classes,
 *   TextComparison::foldAccents) lança MissingExtensionException (RN-03)
 * - Com ext-intl: foldAccents (sem baixar a caixa), a bateria de wildcard e de regex (pt-BR, es, fr, de,
 *   limites ß/æ/œ/Hangul) com oráculo escrito à mão, em memória e no ALinq (D2, D3, D5)
 * - SQL (D4): texto exato no PostgreSQL (expressão embutida e função configurável; `\b`/`\B` viram
 *   `\y`/`\Y`) e no MySQL/MariaDB (wildcard por collation); regex recusado no MySQL/MariaDB e todo
 *   IgnoreCaseAndAccents recusado no SQLite e nos demais dialetos; modificadores além de i/u recusados
 * - TCriteria (D5): recusa, negada ou não
 * - Álgebra (D6): folha opaca, equals estrutural sobre o padrão dobrado
 * - RN-04: a busca da documentação ("*configura*" e "^configura" acham "Configuração")
 * - Bug #73: `regex('/\bconfigura\w+/')` sem comparação acha no PostgreSQL as linhas da memória (antes,
 *   `\b` ia como backspace e não achava nenhuma); não precisa de ext-intl
 * - Paridade em bancos reais (RN-02) quando ASPEC_PDO_PGSQL_DSN / ASPEC_PDO_MYSQL_DSN /
 *   ASPEC_PDO_MARIADB_DSN apontam para eles (run_portability.sh): memória = SQL = PdoRepository, coluna a
 *   coluna, com os limites documentados como controle positivo
 *
 * @version    2.3.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Unit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class Module29_WildcardRegexAccentInsensitiveTest extends TestCase
{
    /** Valores da bateria (o índice + 1 é o id da linha); null testa a semântica de nulo. */
    public const VALUES = [
        'Configuração', 'configuracao', 'CONFIGURAÇÃO', 'Configurações', 'configurável 2', 'Coração', 'coracao',
        'CORAÇÃO', 'Corazón', 'São Paulo', 'SAO PAULO', 'Señor', 'NIÑO', 'Año', 'ano', 'Élève', 'Crème brûlée',
        'Noël', 'garçon', 'Müller', 'Mueller', 'Straße', 'Strasse', 'GROẞE', 'Größe', 'Cæsar', 'Caesar', 'Œuvre',
        'oeuvre', '50% off', 'a_b', 'axb', 'Ano 2026', 'x y', null,
    ];

    /** Limites que dependem do banco (controle positivo por família). */
    public const LIMIT_VALUES = ['Søren', 'Soren', 'Łódź', 'Lodz', 'Ðe', 'De', 'Йошкар-Ола', 'иошкар', 'が', 'ガ', '한국어', '하늘', '١٢٣'];

    public function run(): void
    {
        $this->testExistingAnswersAndSqlAreUnchanged();
        $this->testNewAccessorsDescribeTheOldLeaves();
        $this->testIgnoreCaseWithoutIntl();
        $this->testBug73PlainRegexWordBoundariesOnPostgreSql();

        if (!TextComparison::isAccentFoldingAvailable()) {
            $this->testRefusedWithoutIntl();
            fwrite(STDOUT, "    [AVISO] ext-intl ausente: a recusa (RN-03) foi verificada; a bateria sem acento roda num PHP com intl (run_portability.sh).\n");

            return;
        }

        $this->testFoldAccents();
        $this->testConstruction();
        $this->testMemoryBattery();
        $this->testFactoryDslAndClassesAgree();
        $this->testALinqFollowsMemory();
        $this->testAlgebraTreatsTheFoldedLeavesAsOpaque();
        $this->testSqlTranslation();
        $this->testUnmeasuredDialectsRefuse();
        $this->testCriteriaRefuses();
        $this->testDocumentationExample();
        $this->testReadme20_1BlocksAreTheSameInBothLanguages();

        if (extension_loaded('pdo_sqlite')) {
            $this->testSqliteRepositoryRefusesUnlessFallback();
        }

        foreach (['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB'] as $family => $label) {
            $pdo = $this->connectFromEnvironment($family);
            if ($pdo === null) {
                fwrite(STDOUT, "    [AVISO] ASPEC_PDO_" . strtoupper($family) . "_DSN não definido; paridade de wildcard/regex sem acento em {$label} pulada.\n");
                continue;
            }
            $queries = $this->testDatabaseParity($pdo, $family);
            fwrite(STDOUT, "    [2.3.0] {$label} " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ": paridade de wildcard/regex sem acento OK ({$queries} consultas)\n");
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // RN-01: o que já existia; IgnoreCase sem intl
    ///////////////////////////////////////////////////////////////////////////

    private function testExistingAnswersAndSqlAreUnchanged(): void
    {
        // Respostas da 2.2.0
        $this->assertTrue(Spec::wildcard('Ca*')->isSatisfiedBy('Café'));
        $this->assertFalse(Spec::wildcard('ca*')->isSatisfiedBy('Café'), 'wildcard() continua sensível à caixa');
        $this->assertTrue(Spec::wildcardIgnoreCase('ÁGUA*')->isSatisfiedBy('água mineral'));
        $this->assertFalse(Spec::wildcardIgnoreCase('agua*')->isSatisfiedBy('água mineral'), 'wildcardIgnoreCase() compara acento');
        $this->assertTrue(Spec::wildcardExpressionMatcherIgnoreCase('*.TXT')->isSatisfiedBy('a.txt'));
        $this->assertTrue(Spec::regex('/^ab/')->isSatisfiedBy('abc'));
        $this->assertFalse(Spec::regex('/^ab/')->isSatisfiedBy('ABC'));
        $this->assertTrue(Spec::matches('/^ab/i')->isSatisfiedBy('ABC'));
        $this->assertFalse(Spec::regex('/^agua/i')->isSatisfiedBy('água'));
        $this->assertTrue(dslMatches('/\d/')->isSatisfiedBy('a1'));
        $this->assertTrue(dslMatchesWildcardExpression('a?c')->isSatisfiedBy('abc'));
        $this->assertFalse(dslMatchesWildcardExpression('A?C')->isSatisfiedBy('abc'));
        $this->assertTrue(dslMatchesWildcardExpressionIgnoringCase('A?C')->isSatisfiedBy('abc'));
        $this->assertEquals('/^ab/i', (new RegexSpecification('/^ab/i'))->getPattern());
        $this->assertEquals('^ab', (new RegexSpecification('/^ab/i'))->getBody());
        $this->assertEquals('i', (new RegexSpecification('/^ab/i'))->getModifiers());
        $this->assertEquals('a*', (new WildcardSpecification('a*'))->getPattern());
        $this->assertFalse(new WildcardExpressionMatcherIgnoreCaseStringSpecification('A*') instanceof WildcardSpecification, 'a classe antiga não passa a ser um WildcardSpecification (visitor antigo)');

        // O SQL e o TCriteria da 2.2.0
        $sql = fn(ISpecification $spec, string $dialect): string => Spec::toSql(Spec::property('t', $spec), $dialect)->getSql();
        $this->assertEquals('("t" IS NOT NULL AND "t" LIKE :p1)', $sql(Spec::wildcard('caf?'), 'pgsql'));
        $this->assertEquals('("t" IS NOT NULL AND "t" ILIKE :p1)', $sql(Spec::wildcardIgnoreCase('CAF?'), 'pgsql'));
        $this->assertEquals('("t" IS NOT NULL AND "t" ~ :p1)', $sql(Spec::regex('/^a/'), 'pgsql'));
        $this->assertEquals('("t" IS NOT NULL AND "t" ~* :p1)', $sql(Spec::regex('/^a/i'), 'pgsql'));
        $this->assertEquals('(`t` IS NOT NULL AND CONVERT(`t` USING utf8mb4) COLLATE utf8mb4_bin LIKE :p1)', $sql(Spec::wildcard('a*'), 'mysql'));
        $this->assertEquals('(`t` IS NOT NULL AND `t` REGEXP CONCAT(\'(?-i)\', :p1))', $sql(Spec::regex('/^a/'), 'mysql'));
        $this->assertEquals('("t" IS NOT NULL AND "t" GLOB :p1)', $sql(Spec::wildcard('a*'), 'sqlite'));
        $this->assertEquals([':p1' => '^a'], Spec::toSql(Spec::property('t', Spec::regex('/^a/')), 'pgsql')->getParameters());
        if (class_exists(TFilter::class)) {
            $this->assertEquals("(title LIKE 'a%')", Spec::toCriteria(Spec::property('title', Spec::wildcard('a*')))->dump());
            $this->assertEquals("(UPPER(title) LIKE UPPER('a%'))", Spec::toCriteria(Spec::property('title', Spec::wildcardIgnoreCase('a*')))->dump());
            $this->assertEquals("(title REGEXP '(?-i)^a')", Spec::toCriteria(Spec::property('title', Spec::regex('/^a/')))->dump());
        }
    }

    /** The accessors of 2.3.0 on the leaves built as before. */
    private function testNewAccessorsDescribeTheOldLeaves(): void
    {
        $plain = new WildcardSpecification('a*');
        $this->assertTrue($plain->getComparison() === TextComparison::CaseSensitive);
        $this->assertTrue($plain->isCaseSensitive());
        $this->assertFalse($plain->ignoresAccents());
        $this->assertTrue((new WildcardExpressionMatcherIgnoreCaseStringSpecification('A*'))->getComparison() === TextComparison::IgnoreCase);
        $regex = new RegexSpecification('/^ab/i');
        $this->assertEquals(null, $regex->getComparison());
        $this->assertFalse($regex->ignoresAccents());
        $this->assertFalse(Spec::contains('a')->ignoresAccents(), 'LiteralPattern segue respondendo ignoresAccents()');
        $this->assertTrue(Spec::contains('a', false)->getComparison() === TextComparison::IgnoreCase, 'LiteralPattern segue respondendo getComparison() não nulo');
    }

    /**
     * The new IgnoreCase forms need no extension: `wildcard($p, false)` answers as wildcardIgnoreCase()
     * and translates the same; `regex($p, IgnoreCase)` adds `i` and translates as `/.../i`.
     */
    private function testIgnoreCaseWithoutIntl(): void
    {
        $values = ['ÁGUA mineral', 'água', 'agua', 'Abc', 'abc', 'xabc', 'a.txt', 'A.TXT', ''];
        foreach (['ÁGUA*', 'a*', '*.TXT', '?bc', 'A?C'] as $glob) {
            $legacy = Spec::wildcardIgnoreCase($glob);
            foreach ([Spec::wildcard($glob, false), Spec::wildcard($glob, TextComparison::IgnoreCase), Spec::wildcard($glob, caseSensitive: false), dslMatchesWildcardExpression($glob, false), Spec::getFactory()->matchesWildcard($glob, false), Spec::getFactory()->like($glob, TextComparison::IgnoreCase), Spec::getFactory()->matchesWildcardExpression($glob, false), Spec::getFactory()->string()->matchesWildcard($glob, false), new WildcardSpecification($glob, false)] as $i => $spec) {
                foreach ($values as $value) {
                    $this->assertEquals($legacy->isSatisfiedBy($value), $spec->isSatisfiedBy($value), "wildcard('{$glob}', IgnoreCase) #{$i} = wildcardIgnoreCase() em '{$value}'");
                }
            }
            foreach ($values as $value) {
                $this->assertEquals(Spec::wildcard($glob)->isSatisfiedBy($value), Spec::wildcard($glob, TextComparison::CaseSensitive)->isSatisfiedBy($value), "CaseSensitive = true: {$glob}");
            }
            $this->assertEquals(
                Spec::toSql(Spec::property('t', $legacy), 'pgsql')->getSql(),
                Spec::toSql(Spec::property('t', Spec::wildcard($glob, false)), 'pgsql')->getSql(),
                "SQL de wildcard('{$glob}', false) = wildcardIgnoreCase()"
            );
            $this->assertEquals(
                Spec::toSql(Spec::property('t', $legacy), 'mysql')->getParameters(),
                Spec::toSql(Spec::property('t', Spec::wildcard($glob, false)), 'mysql')->getParameters()
            );
        }
        $this->assertTrue(Spec::wildcard('a*', false)->getComparison() === TextComparison::IgnoreCase);
        $this->assertFalse(Spec::wildcard('a*', false)->isCaseSensitive());
        $this->assertFalse(Spec::wildcard('a*', false)->ignoresAccents());
        $this->assertFalse(Spec::wildcard('agua*', false)->isSatisfiedBy('água'), 'IgnoreCase compara acento');
        // Valores absolutos (wildcardIgnoreCase() delega para a mesma regra desde 2.3.0)
        $this->assertTrue(Spec::wildcard('água*', false)->isSatisfiedBy('ÁGUA MINERAL'), 'caixa Unicode no candidato');
        $this->assertTrue(Spec::wildcard('ÁGUA*', false)->isSatisfiedBy('água mineral'), 'caixa Unicode no padrão');
        $this->assertTrue(Spec::wildcardIgnoreCase('água*')->isSatisfiedBy('ÁGUA MINERAL'));
        $this->assertFalse(Spec::wildcard('a*', false)->isSatisfiedBy(1), 'não-string não satisfaz');

        $regex = Spec::regex('/^ab/', TextComparison::IgnoreCase);
        $this->assertEquals('/^ab/i', $regex->getPattern());
        $this->assertTrue($regex->getComparison() === TextComparison::IgnoreCase);
        $this->assertFalse($regex->ignoresAccents());
        $this->assertTrue($regex->isSatisfiedBy('ABC'));
        $this->assertFalse($regex->isSatisfiedBy(12));
        $this->assertEquals('#^ab#mi', (new RegexSpecification('#^ab#m', TextComparison::IgnoreCase))->getPattern(), 'o delimitador e os modificadores são mantidos');
        $this->assertEquals('{^ab}i', (new RegexSpecification('{^ab}', TextComparison::IgnoreCase))->getPattern(), 'delimitador de par');
        $this->assertEquals('/^ab/i', (new RegexSpecification('/^ab/i', TextComparison::IgnoreCase))->getPattern(), 'i não se repete');
        $this->assertEquals('/^ab/', (new RegexSpecification('/^ab/', TextComparison::CaseSensitive))->getPattern(), 'CaseSensitive não muda o padrão');
        $this->assertTrue((new RegexSpecification('/^ab/i', TextComparison::CaseSensitive))->isSatisfiedBy('ABC'), 'CaseSensitive: os modificadores do padrão mandam');
        $this->assertFalse(Spec::regex('/^agua/', TextComparison::IgnoreCase)->isSatisfiedBy('Água'), 'IgnoreCase compara acento');
        foreach ([Spec::matches('/^ab/', TextComparison::IgnoreCase), dslMatches('/^ab/', TextComparison::IgnoreCase), Spec::getFactory()->matchesRegex('/^ab/', TextComparison::IgnoreCase), Spec::getFactory()->matchesRegularExpression('/^ab/', comparison: TextComparison::IgnoreCase), Spec::getFactory()->string()->matches('/^ab/', TextComparison::IgnoreCase), Spec::regex(pattern: '/^ab/', comparison: TextComparison::IgnoreCase)] as $i => $route) {
            $this->assertTrue($route->isSatisfiedBy('ABc'), "rota de regex IgnoreCase #{$i}");
            $this->assertEquals('/^ab/i', $route->getPattern(), "rota #{$i}");
        }
        $this->assertThrows(InvalidArgumentException::class, fn() => new RegexSpecification('^ab', TextComparison::IgnoreCase), 'padrão sem delimitador é recusado com comparação');
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::regex('', TextComparison::IgnoreCase), 'padrão vazio');
        $this->assertEquals('("t" IS NOT NULL AND "t" ~* :p1)', Spec::toSql(Spec::property('t', $regex), 'pgsql')->getSql());
        $this->assertEquals([':p1' => '^ab'], Spec::toSql(Spec::property('t', $regex), 'pgsql')->getParameters());
        $this->assertEquals('(`t` IS NOT NULL AND `t` REGEXP CONCAT(\'(?i)\', :p1))', Spec::toSql(Spec::property('t', $regex), 'mysql')->getSql());
        if (class_exists(TFilter::class)) {
            $this->assertEquals("(title REGEXP '(?i)^ab')", Spec::toCriteria(Spec::property('title', $regex))->dump());
            $this->assertEquals("(UPPER(title) LIKE UPPER('a%'))", Spec::toCriteria(Spec::property('title', Spec::wildcard('a*', false)))->dump());
            $this->assertEquals("(UPPER(title) NOT LIKE UPPER('a%'))", Spec::toCriteria(Spec::property('title', Spec::not(Spec::wildcard('a*', false))))->dump());
            $this->assertEquals("(title NOT LIKE 'a%')", Spec::toCriteria(Spec::property('title', Spec::not(Spec::wildcard('a*'))))->dump());
        }
    }

    /**
     * Bug #73 (2.3.0): `\b`/`\B` de um regex SEM comparação vão ao PostgreSQL como `\y`/`\Y`; antes iam como
     * escritos (backspace no ARE) e `\bconfigura\w+` não achava nenhuma linha. Roda sem ext-intl, quando
     * ASPEC_PDO_PGSQL_DSN aponta para um PostgreSQL (run_portability.sh).
     */
    private function testBug73PlainRegexWordBoundariesOnPostgreSql(): void
    {
        $pdo = $this->connectFromEnvironment('pgsql');
        if ($pdo === null) {
            return;
        }
        // 'configurável' não casa: depois de 'configur' vem 'á', não 'a'
        $battery = [
            'regex(/\bconfigura\w+/)' => [Spec::regex('/\bconfigura\w+/'), [2]],
            'regex(/\Bfigura/)' => [Spec::regex('/\Bfigura/'), [1, 2, 4]],
            'regex(/\bconfigura\w+/iu)' => [Spec::regex('/\bconfigura\w+/iu'), [1, 2, 3, 4]],
            'not(regex(/\bconfigura\w+/))' => [Spec::not(Spec::regex('/\bconfigura\w+/')), array_values(array_diff(range(1, count(self::VALUES) - 1), [2]))],
        ];
        $pdo->exec('DROP TABLE IF EXISTS aspec_bug73');
        $pdo->exec('CREATE TABLE aspec_bug73 (id INT PRIMARY KEY, df TEXT NULL, vc VARCHAR(40) NULL)');
        $insert = $pdo->prepare('INSERT INTO aspec_bug73 VALUES (?, ?, ?)');
        foreach (self::VALUES as $i => $value) {
            $insert->execute([$i + 1, $value, $value]);
        }
        $queries = 0;
        foreach ($battery as $label => [$spec, $expected]) {
            $this->assertEquals($expected, $this->memoryIds($spec, self::VALUES), "memória: {$label}");
            foreach (['df', 'vc'] as $column) {
                $clause = Spec::toSql(Spec::property($column, $spec), 'pgsql');
                $statement = $pdo->prepare("SELECT id FROM aspec_bug73 WHERE {$clause->getSql()} ORDER BY id");
                $statement->execute($clause->getParameters());
                $this->assertEquals($expected, array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)), "bug #73, PostgreSQL {$column}: {$label}");
                $queries++;
            }
        }
        $pdo->exec('DROP TABLE aspec_bug73');
        fwrite(STDOUT, "    [bug #73] PostgreSQL " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . ": \\b/\\B do regex sem comparação OK ({$queries} consultas)\n");
    }

    private function testRefusedWithoutIntl(): void
    {
        $ai = TextComparison::IgnoreCaseAndAccents;
        $builders = [
            'Spec::wildcard' => fn() => Spec::wildcard('*configura*', $ai),
            'Spec::wildcard (nomeado)' => fn() => Spec::wildcard('*configura*', caseSensitive: $ai),
            'Spec::regex' => fn() => Spec::regex('/^configura/', $ai),
            'Spec::matches' => fn() => Spec::matches('/^configura/', $ai),
            'DSL matches' => fn() => dslMatches('/a/', $ai),
            'DSL matchesWildcardExpression' => fn() => dslMatchesWildcardExpression('a*', $ai),
            'factory matchesRegex' => fn() => Spec::getFactory()->matchesRegex('/a/', $ai),
            'factory matchesRegularExpression' => fn() => Spec::getFactory()->matchesRegularExpression('/a/', $ai),
            'factory matchesWildcard' => fn() => Spec::getFactory()->matchesWildcard('a*', $ai),
            'factory like' => fn() => Spec::getFactory()->like('a*', $ai),
            'factory matchesWildcardExpression' => fn() => Spec::getFactory()->matchesWildcardExpression('a*', $ai),
            'string() matches' => fn() => Spec::getFactory()->string()->matches('/a/', $ai),
            'new WildcardSpecification' => fn() => new WildcardSpecification('a*', $ai),
            'new RegexSpecification' => fn() => new RegexSpecification('/a/', $ai),
            'new RegexSpecification sem delimitador' => fn() => new RegexSpecification('a', $ai),
            'TextComparison::foldAccents' => fn() => TextComparison::foldAccents('a'),
        ];
        foreach ($builders as $label => $build) {
            $e = $this->assertThrows(MissingExtensionException::class, $build, "{$label}: sem ext-intl a opção nova é recusada");
            $this->assertEquals('intl', $e->extension, $label);
            $this->assertTrue(str_contains($e->getMessage(), 'ext-intl'), "{$label}: a mensagem cita a extensão");
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // D2/D3/D5: memória e ALinq
    ///////////////////////////////////////////////////////////////////////////

    private function testFoldAccents(): void
    {
        $cases = [
            'CONFIGURAÇÃO' => 'CONFIGURACAO', 'São Paulo' => 'Sao Paulo', 'NIÑO' => 'NINO', 'Crème brûlée' => 'Creme brulee',
            'Straße' => 'Straße', 'GROẞE' => 'GROẞE', 'Cæsar' => 'Cæsar', 'Œuvre' => 'Œuvre', 'Łódź' => 'Łodz', 'Йошкар' => 'Иошкар',
            'が' => 'か', '한국어' => '한국어', "Cafe\u{0301}" => 'Cafe',
            // o corpo de um regex: os escapes (ASCII) ficam, a caixa também
            '^cora[cç][aã]o\D\W\S\B$' => '^cora[cc][aa]o\D\W\S\B$', '\bCONFIGURAÇÃO\w+' => '\bCONFIGURACAO\w+',
        ];
        foreach ($cases as $text => $folded) {
            $this->assertEquals($folded, TextComparison::foldAccents($text), "foldAccents de {$text}");
            $this->assertEquals(mb_strtolower($folded, 'UTF-8'), TextComparison::foldCaseAndAccents($text), "foldCaseAndAccents de {$text} = minúsculas de foldAccents");
        }
        $this->assertEquals(null, TextComparison::foldAccents("\xC3\x28"), 'UTF-8 inválido não tem dobra');
        $this->assertEquals(null, TextComparison::foldCaseAndAccents("\xC3\x28"));
    }

    private function testConstruction(): void
    {
        $ai = TextComparison::IgnoreCaseAndAccents;
        $wildcard = Spec::wildcard('*CONFIGURAÇÃO*', $ai);
        $this->assertTrue($wildcard instanceof WildcardSpecification);
        $this->assertEquals('*CONFIGURAÇÃO*', $wildcard->getPattern(), 'o padrão fica como escrito');
        $this->assertTrue($wildcard->getComparison() === $ai && $wildcard->ignoresAccents() && !$wildcard->isCaseSensitive());

        $regex = Spec::regex('#^cora[cç]ÃO\b#m', $ai);
        $this->assertTrue($regex instanceof RegexSpecification);
        $this->assertEquals('#^cora[cc]AO\b#miu', $regex->getPattern(), 'corpo sem acento, caixa mantida, i e u acrescentados');
        $this->assertEquals('^cora[cc]AO\b', $regex->getBody());
        $this->assertEquals('miu', $regex->getModifiers());
        $this->assertTrue($regex->getComparison() === $ai && $regex->ignoresAccents());
        $this->assertEquals('/a/ui', Spec::regex('/a/ui', $ai)->getPattern(), 'i e u não se repetem');
        $this->assertEquals('/a/xiu', Spec::regex('/a/x', $ai)->getPattern(), 'os modificadores escritos ficam');
        $this->assertEquals('/\D/iu', Spec::regex('/\D/', $ai)->getPattern(), '\D não vira \d');

        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::wildcard("\xC3\x28*", $ai), 'wildcard fora de UTF-8');
        $this->assertThrows(InvalidArgumentException::class, fn() => Spec::regex("/\xC3\x28/", $ai), 'regex fora de UTF-8');
        $this->assertThrows(InvalidArgumentException::class, fn() => new RegexSpecification('cao', $ai), 'regex sem delimitador');
        $this->assertFalse(Spec::wildcard('a*', $ai)->isSatisfiedBy("a\xFF"), 'candidato inválido não satisfaz (wildcard)');
        $this->assertFalse(Spec::regex('/a/', $ai)->isSatisfiedBy("a\xFF"), 'candidato inválido não satisfaz (regex)');
        $this->assertFalse(Spec::wildcard('*', $ai)->isSatisfiedBy(null));
        $this->assertFalse(Spec::regex('/./', $ai)->isSatisfiedBy(1));
    }

    /**
     * Wildcard battery: label => [specification, ids expected in memory (1-based, written by hand)].
     *
     * @return array<string, array{0: ISpecification, 1: list<int>}>
     */
    private function wildcardBattery(): array
    {
        $ai = TextComparison::IgnoreCaseAndAccents;
        $w = fn(string $p): ISpecification => Spec::wildcard($p, $ai);
        $all = array_keys(array_filter(self::VALUES, fn(?string $v): bool => $v !== null));
        $ids = fn(array $exclude): array => array_values(array_diff(array_map(fn(int $i): int => $i + 1, $all), $exclude));

        return [
            "wildcard('*configura*')" => [$w('*configura*'), [1, 2, 3, 4, 5]],
            "wildcard('CONFIGURA*')" => [$w('CONFIGURA*'), [1, 2, 3, 4, 5]],
            "wildcard('*ÇÃO')" => [$w('*ÇÃO'), [1, 2, 3, 6, 7, 8]],
            "wildcard('*cao')" => [$w('*cao'), [1, 2, 3, 6, 7, 8]],
            "wildcard('cora?ao')" => [$w('cora?ao'), [6, 7, 8]],
            "wildcard('S?O PAULO')" => [$w('S?O PAULO'), [10, 11]],
            "wildcard('*nino*')" => [$w('*nino*'), [13]],
            "wildcard('a?o')" => [$w('a?o'), [14, 15]],
            "wildcard('*eleve*')" => [$w('*eleve*'), [16]],
            "wildcard('creme br?lee')" => [$w('creme br?lee'), [17]],
            "wildcard('noel')" => [$w('noel'), [18]],
            "wildcard('gar?on')" => [$w('gar?on'), [19]],
            "wildcard('*ç*')" => [$w('*ç*'), [1, 2, 3, 4, 5, 6, 7, 8, 9, 17, 19, 26, 27]],
            "wildcard('m?ller')" => [$w('m?ller'), [20]],
            "wildcard('stra?e')" => [$w('stra?e'), [22]],
            "wildcard('*ß*')" => [$w('*ß*'), [22, 24, 25]],
            "wildcard('gro?e')" => [$w('gro?e'), [24, 25]],
            "wildcard('*grosse*')" => [$w('*grosse*'), []],
            "wildcard('c?sar')" => [$w('c?sar'), [26]],
            "wildcard('*ae*')" => [$w('*ae*'), [27]],
            "wildcard('*œuvre*')" => [$w('*œuvre*'), [28]],
            "wildcard('50% *')" => [$w('50% *'), [30]],
            "wildcard('a_b')" => [$w('a_b'), [31]],
            "wildcard('A?B')" => [$w('A?B'), [31, 32]],
            "wildcard('*?* *')" => [$w('*?* *'), [5, 10, 11, 17, 30, 33, 34]],
            "not(wildcard('*configura*'))" => [Spec::not($w('*configura*')), $ids([1, 2, 3, 4, 5])],
            "wildcard('*cao') or wildcard('a?o')" => [$w('*cao')->or($w('a?o')), [1, 2, 3, 6, 7, 8, 14, 15]],
        ];
    }

    /**
     * Regex battery: label => [specification, ids expected in memory (written by hand)].
     *
     * @return array<string, array{0: ISpecification, 1: list<int>}>
     */
    private function regexBattery(): array
    {
        $ai = TextComparison::IgnoreCaseAndAccents;
        $r = fn(string $p): ISpecification => Spec::regex($p, $ai);
        $all = array_keys(array_filter(self::VALUES, fn(?string $v): bool => $v !== null));
        $ids = fn(array $exclude): array => array_values(array_diff(array_map(fn(int $i): int => $i + 1, $all), $exclude));
        $words = [1, 2, 3, 4, 6, 7, 8, 9, 12, 13, 14, 15, 16, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 31, 32];

        return [
            'regex(/^cora[cç][aã]o$/)' => [$r('/^cora[cç][aã]o$/'), [6, 7, 8]],
            'regex(/\bconfigura\w+/)' => [$r('/\bconfigura\w+/'), [1, 2, 3, 4, 5]],
            'regex(/^configura/)' => [$r('/^configura/'), [1, 2, 3, 4, 5]],
            'regex(/ÇÃO$/)' => [$r('/ÇÃO$/'), [1, 2, 3, 6, 7, 8]],
            'regex(/^s[aã]o\s+paulo$/)' => [$r('/^s[aã]o\s+paulo$/'), [10, 11]],
            'regex(/^(ano|año)$/)' => [$r('/^(ano|año)$/'), [14, 15]],
            'regex(/ñ/)' => [$r('/ñ/'), [1, 2, 3, 4, 5, 9, 12, 13, 14, 15, 18, 19, 33]],
            'regex(/ü/)' => [$r('/ü/'), [1, 2, 3, 4, 5, 10, 11, 17, 20, 21, 28, 29]],
            'regex(/stra(ss|ß)e/)' => [$r('/stra(ss|ß)e/'), [22, 23]],
            'regex(/^gro.e$/)' => [$r('/^gro.e$/'), [24, 25]],
            'regex(/c(ae|æ)sar/)' => [$r('/c(ae|æ)sar/'), [26, 27]],
            'regex(/^CÆSAR$/)' => [$r('/^CÆSAR$/'), [26]],
            'regex(/^\w+$/)' => [$r('/^\w+$/'), $words],
            'regex(/\W/)' => [$r('/\W/'), [5, 10, 11, 17, 30, 33, 34]],
            'regex(/\d/)' => [$r('/\d/'), [5, 30, 33]],
            'regex(/^\D+$/)' => [$r('/^\D+$/'), $ids([5, 30, 33])],
            'regex(/o\b/)' => [$r('/o\b/'), [1, 2, 3, 6, 7, 8, 10, 11, 13, 14, 15, 33]],
            'regex(/\Bo/)' => [$r('/\Bo/'), [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 18, 19, 24, 25, 33]],
            'regex(/[ç]/)' => [$r('/[ç]/'), [1, 2, 3, 4, 5, 6, 7, 8, 9, 17, 19, 26, 27]],
            'regex(/^.{3}$/)' => [$r('/^.{3}$/'), [14, 15, 31, 32, 34]],
            'regex(/ß/)' => [$r('/ß/'), [22, 24, 25]],
            'regex(/A_B/)' => [$r('/A_B/'), [31]],
            'not(regex(/^configura/))' => [Spec::not($r('/^configura/')), $ids([1, 2, 3, 4, 5])],
            'regex(/^configura/) and not(regex(/ões$/))' => [$r('/^configura/')->and(Spec::not($r('/ões$/'))), [1, 2, 3, 5]],
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
        $w = fn(string $p): ISpecification => Spec::wildcard($p, $ai);
        $r = fn(string $p): ISpecification => Spec::regex($p, $ai);

        return [
            "wildcard('*soren*')" => [$w('*soren*'), [2]],
            "wildcard('s?ren')" => [$w('s?ren'), [1, 2]],
            "wildcard('*lodz*')" => [$w('*lodz*'), [4]],
            "wildcard('l?dz')" => [$w('l?dz'), [4]],
            "wildcard('de')" => [$w('de'), [6]],
            "wildcard('*иошкар*')" => [$w('*иошкар*'), [7, 8]],
            "wildcard('か')" => [$w('か'), [9]],
            "wildcard('한국*')" => [$w('한국*'), [11]],
            "wildcard('하*')" => [$w('하*'), [12]],
            "wildcard('?국어')" => [$w('?국어'), [11]],
            'regex(/soren/)' => [$r('/soren/'), [2]],
            'regex(/ø/)' => [$r('/ø/'), [1]],
            'regex(/lodz/)' => [$r('/lodz/'), [4]],
            'regex(/ł/)' => [$r('/ł/'), [3]],
            'regex(/^de$/)' => [$r('/^de$/'), [6]],
            'regex(/иошкар/)' => [$r('/иошкар/'), [7, 8]],
            'regex(/^か$/)' => [$r('/^か$/'), [9]],
            'regex(/한국/)' => [$r('/한국/'), [11]],
            'regex(/하/)' => [$r('/하/'), [12]],
            'regex(/\d/)' => [$r('/\d/'), [13]],
            'regex(/^\D+$/)' => [$r('/^\D+$/'), [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]],
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
        foreach ($this->wildcardBattery() + $this->regexBattery() as $label => [$spec, $expected]) {
            $this->assertEquals($expected, $this->memoryIds($spec, self::VALUES), "memória: {$label}");
        }
        foreach ($this->limitBattery() as $label => [$spec, $expected]) {
            $this->assertEquals($expected, $this->memoryIds($spec, self::LIMIT_VALUES), "memória (limites): {$label}");
        }
        // Controle: sem a dobra, a mesma busca não acha as formas acentuadas
        $this->assertEquals([2], $this->memoryIds(Spec::wildcard('*configuracao*', false), self::VALUES), 'IgnoreCase compara acento');
        $this->assertEquals([7], $this->memoryIds(Spec::regex('/^coracao$/', TextComparison::IgnoreCase), self::VALUES), 'IgnoreCase compara acento');
    }

    private function testFactoryDslAndClassesAgree(): void
    {
        $ai = TextComparison::IgnoreCaseAndAccents;
        $wildcards = [
            'Spec::wildcard' => Spec::wildcard('*configuracao*', $ai),
            'Spec::wildcard (nomeado)' => Spec::wildcard(pattern: '*configuracao*', caseSensitive: $ai),
            'DSL matchesWildcardExpression' => dslMatchesWildcardExpression('*configuracao*', $ai),
            'factory matchesWildcard' => Spec::getFactory()->matchesWildcard('*configuracao*', $ai),
            'factory like' => Spec::getFactory()->like('*configuracao*', $ai),
            'factory matchesWildcardExpression' => Spec::getFactory()->matchesWildcardExpression('*configuracao*', $ai),
            'string() matchesWildcard' => Spec::getFactory()->string()->matchesWildcard('*configuracao*', $ai),
            'string() like' => Spec::getFactory()->string()->like('*configuracao*', caseSensitive: $ai),
            'class' => new WildcardSpecification('*configuracao*', $ai),
        ];
        foreach ($wildcards as $route => $spec) {
            $this->assertTrue($spec->isSatisfiedBy('Configuração do servidor'), "{$route}");
            $this->assertTrue($spec->isSatisfiedBy('CONFIGURACAO'), "{$route}");
            $this->assertFalse($spec->isSatisfiedBy('Configurações'), "{$route}: o glob ancora nas pontas");
            $this->assertTrue($spec->equals($wildcards['class']), "{$route}: mesma folha");
        }
        $regexes = [
            'Spec::regex' => Spec::regex('/^configura[cç][aã]o/', $ai),
            'Spec::regex (nomeado)' => Spec::regex(pattern: '/^configura[cç][aã]o/', comparison: $ai),
            'Spec::matches' => Spec::matches('/^configura[cç][aã]o/', $ai),
            'DSL matches' => dslMatches('/^configura[cç][aã]o/', $ai),
            'factory matchesRegex' => Spec::getFactory()->matchesRegex('/^configura[cç][aã]o/', $ai),
            'factory matchesRegularExpression' => Spec::getFactory()->matchesRegularExpression('/^configura[cç][aã]o/', $ai),
            'factory matches' => Spec::getFactory()->matches('/^configura[cç][aã]o/', $ai),
            'string() matchesRegex' => Spec::getFactory()->string()->matchesRegex('/^configura[cç][aã]o/', $ai),
            'class' => new RegexSpecification('/^configura[cç][aã]o/', $ai),
        ];
        foreach ($regexes as $route => $spec) {
            $this->assertTrue($spec->isSatisfiedBy('Configuração do servidor'), "{$route}");
            $this->assertTrue($spec->isSatisfiedBy('CONFIGURACAO'), "{$route}");
            $this->assertFalse($spec->isSatisfiedBy('a Configuração'), "{$route}: ^ ancora");
            $this->assertTrue($spec->equals($regexes['class']), "{$route}: mesma folha");
        }
        // Sem acento no padrão ou com acento: a mesma folha (o corpo é dobrado)
        $this->assertTrue(Spec::regex('/^configuracao/', $ai)->equals(Spec::regex('/^configuração/', $ai)));
        $this->assertTrue(Spec::regex('/^configuração/', $ai)->isSatisfiedBy('Configuracao'));
        $this->assertTrue(Spec::wildcard('*configuração*', $ai)->isSatisfiedBy('configuracao'));
    }

    private function testALinqFollowsMemory(): void
    {
        foreach ($this->wildcardBattery() + $this->regexBattery() as $label => [$spec, $expected]) {
            $predicate = ALinqSpecificationVisitor::createPredicate(Spec::property('t', $spec));
            $ids = [];
            foreach (self::VALUES as $i => $value) {
                if ($predicate((object) ['t' => $value])) {
                    $ids[] = $i + 1;
                }
            }
            $this->assertEquals($expected, $ids, "ALinq: {$label}");
        }
        // IgnoreCase do regex pelo atalho do visitor (o padrão já leva o i)
        $predicate = ALinqSpecificationVisitor::createPredicate(Spec::regex('/^AB/', TextComparison::IgnoreCase));
        $this->assertTrue($predicate('abc'));
        $this->assertFalse($predicate('xab'));

        // Pela coleção, quando a ALinq (PHP 8.4+) está disponível
        if (ALinqBridge::isAvailable() && PHP_VERSION_ID >= 80400) {
            $rows = array_map(fn(?string $t): object => (object) ['t' => $t], self::VALUES);
            foreach ([Spec::wildcard('*coracao*', TextComparison::IgnoreCaseAndAccents), Spec::regex('/^cora[cç][aã]o$/', TextComparison::IgnoreCaseAndAccents)] as $spec) {
                $found = ALinqBridge::filter($rows, Spec::property('t', $spec))->select(fn(object $r): ?string => $r->t)->toArray();
                $this->assertEquals(['Coração', 'coracao', 'CORAÇÃO'], array_values($found));
            }
        }
    }

    /** D6: folha opaca para a álgebra; equals estrutural sobre o padrão dobrado. */
    private function testAlgebraTreatsTheFoldedLeavesAsOpaque(): void
    {
        $ai = TextComparison::IgnoreCaseAndAccents;
        $this->assertFalse(Spec::wildcard('a*')->equals(Spec::wildcard('a*', false)), 'comparações diferentes, folhas diferentes');
        $this->assertFalse(Spec::wildcard('a*', false)->equals(Spec::wildcard('a*', $ai)));
        $this->assertFalse(Spec::wildcard('a*', false)->equals(Spec::wildcardIgnoreCase('a*')), 'classes diferentes');
        $this->assertTrue(Spec::wildcard('A*', $ai)->equals(Spec::wildcard('A*', $ai)));
        $this->assertFalse(Spec::regex('/a/')->equals(Spec::regex('/a/', TextComparison::IgnoreCase)));
        $this->assertFalse(Spec::regex('/a/', TextComparison::IgnoreCase)->equals(Spec::regex('/a/', $ai)));
        $this->assertFalse(Spec::regex('/a/', $ai)->isGeneralizationOf(Spec::regex('/ab/', $ai)), 'sem subsunção entre padrões');
        $this->assertFalse(Spec::wildcard('a*', $ai)->isGeneralizationOf(Spec::wildcard('ab*', $ai)));
        $this->assertFalse(Spec::wildcard('a*', $ai)->isDisjointWith(Spec::wildcard('b*', $ai)), 'disjunção não é prometida');
        $simplified = Spec::simplify(Spec::wildcard('*cao', $ai)->and(Spec::wildcard('*cao', $ai)));
        $this->assertTrue($simplified->equals(Spec::wildcard('*cao', $ai)), 'simplify deduplica a folha igual');
        $this->assertTrue(Spec::simplify(Spec::regex('/cao$/', $ai)->or(Spec::regex('/ção$/', $ai)))->equals(Spec::regex('/cao$/', $ai)), 'corpos que dobram igual são a mesma folha');
        foreach (['Coração', 'x', ''] as $candidate) {
            $tree = Spec::regex('/cao$/', $ai)->and(Spec::not(Spec::wildcard('*ç*', $ai)));
            $this->assertEquals($tree->isSatisfiedBy($candidate), Spec::simplify($tree)->isSatisfiedBy($candidate), "simplify preserva a resposta ({$candidate})");
        }
    }

    ///////////////////////////////////////////////////////////////////////////
    // D4: SQL
    ///////////////////////////////////////////////////////////////////////////

    private function testSqlTranslation(): void
    {
        $ai = TextComparison::IgnoreCaseAndAccents;
        $fold = fn(string $x): string => PostgreSqlDialect::builtInAccentFolding($x);

        // Wildcard: o mesmo LIKE sem acento da 2.1.0, com o glob como padrão
        $pg = Spec::toSql(Spec::property('t', Spec::wildcard('*configuração*', $ai)), 'pgsql');
        $this->assertEquals('("t" IS NOT NULL AND ' . $fold('"t"') . ' LIKE ' . $fold(':p1') . ')', $pg->getSql());
        $this->assertEquals([':p1' => '%configuração%'], $pg->getParameters(), 'o padrão vai como escrito; o banco dobra os dois lados');
        $pgEscaped = Spec::toSql(Spec::property('t', Spec::wildcard('50%_?', $ai)), 'pgsql');
        $this->assertEquals('("t" IS NOT NULL AND ' . $fold('"t"') . ' LIKE ' . $fold(':p1') . " ESCAPE '!')", $pgEscaped->getSql());
        $this->assertEquals([':p1' => '50!%!__'], $pgEscaped->getParameters());
        $my = Spec::toSql(Spec::property('t', Spec::wildcard('*cao', $ai)), 'mysql');
        $this->assertEquals('(`t` IS NOT NULL AND CONVERT(`t` USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE :p1)', $my->getSql());
        $this->assertEquals([':p1' => '%cao'], $my->getParameters());
        $maria = Spec::toSql(Spec::property('t', Spec::not(Spec::wildcard('a_?', $ai))), new MySqlDialect('utf8mb4_uca1400_ai_ci'));
        $this->assertEquals("(`t` IS NOT NULL AND NOT (CONVERT(`t` USING utf8mb4) COLLATE utf8mb4_uca1400_ai_ci LIKE :p1 ESCAPE '!'))", $maria->getSql());
        $this->assertEquals([':p1' => 'a!__'], $maria->getParameters());
        $this->assertEquals(
            '("t" IS NOT NULL AND public.f_fold(lower("t")) LIKE public.f_fold(lower(:p1)))',
            Spec::toSql(Spec::property('t', Spec::wildcard('a*', $ai)), new PostgreSqlDialect('public.f_fold'))->getSql()
        );

        // Regex no PostgreSQL: coluna dobrada, corpo dobrado no PHP, \b e \B como \y e \Y
        $regex = Spec::toSql(Spec::property('t', Spec::regex('/\bconfiguração\w+/', $ai)), 'pgsql');
        $this->assertEquals('("t" IS NOT NULL AND ' . $fold('"t"') . ' ~* :p1)', $regex->getSql());
        $this->assertEquals([':p1' => '\yconfiguracao\w+'], $regex->getParameters());
        $rewrites = [
            '/^CORA[CÇ][AÃ]O$/' => '^CORA[CC][AA]O$', '/o\b/' => 'o\y', '/\Bo/' => '\Yo', '/[\b]x\b/' => '[\b]x\y',
            '/[]\b]\B/' => '[]\b]\Y', '/[^]a]\b/' => '[^]a]\y', '/[[:alpha:]\b]\b/' => '[[:alpha:]\b]\y', '/\Q\b\E\b/' => '\Q\b\E\y',
            '/\\\\b/' => '\\\\b', '/\D\W\S/' => '\D\W\S', '/a\\\\/' => 'a\\\\', '/\Qa\b/' => '\Qa\b',
        ];
        foreach ($rewrites as $pattern => $bound) {
            $this->assertEquals([':p1' => $bound], Spec::toSql(Spec::property('t', Spec::regex($pattern, $ai)), 'pgsql')->getParameters(), "corpo de {$pattern} no PostgreSQL");
        }
        $wrapped = Spec::toSql(Spec::property('t', Spec::not(Spec::regex('/^a/', $ai))), new PostgreSqlDialect('aspec_fold'));
        $this->assertEquals('("t" IS NOT NULL AND NOT (aspec_fold(lower("t")) ~* :p1))', $wrapped->getSql());
        $this->assertEquals([':p1' => '^a'], $wrapped->getParameters());
        $two = Spec::toSql(Spec::property('t', Spec::regex('/a/', $ai)->and(Spec::wildcard('*b*', $ai))), 'pgsql');
        $this->assertEquals([':p1' => 'a', ':p2' => '%b%'], $two->getParameters(), 'parâmetros em sequência');

        // Modificadores além de i/u: recusados como no regex sem comparação
        foreach (['/^a/m', '/^a/s', '/^a/x'] as $pattern) {
            $this->assertThrows(UnsupportedSqlOperationException::class, fn() => Spec::toSql(Spec::property('t', Spec::regex($pattern, $ai)), 'pgsql'), "modificador de {$pattern}");
        }
        $this->assertTrue(new PostgreSqlDialect() instanceof ISqlAccentInsensitiveLike);
        $this->assertEquals(null, (new AnsiSqlDialect())->accentInsensitiveRegex('"t"', ':p1', 'a'));
        $this->assertEquals(null, (new MySqlDialect())->accentInsensitiveRegex('`t`', ':p1', 'a'), 'MySQL/MariaDB: regex recusado (medido)');
    }

    private function testUnmeasuredDialectsRefuse(): void
    {
        $ai = TextComparison::IgnoreCaseAndAccents;
        $regexes = [
            'regex' => Spec::regex('/^configura/', $ai),
            'not(regex)' => Spec::not(Spec::regex('/cao$/', $ai)),
        ];
        $wildcards = [
            'wildcard' => Spec::wildcard('*configura*', $ai),
            'not(wildcard)' => Spec::not(Spec::wildcard('sao*', $ai)),
        ];
        foreach (['mysql', 'sqlite', 'sqlsrv', 'oracle', 'firebird', 'db2', 'informix', 'duckdb', 'ansi'] as $dialect) {
            $leaves = $dialect === 'mysql' ? $regexes : $regexes + $wildcards;
            foreach ($leaves as $label => $leaf) {
                $e = $this->assertThrows(
                    NonTranslatableSpecificationException::class,
                    fn() => Spec::toSql(Spec::property('t', $leaf), $dialect),
                    "{$dialect}: {$label} é recusado"
                );
                $this->assertTrue(str_contains($e->getMessage(), 'ignores case and accents'), "{$dialect}: mensagem");
            }
        }
        $e = $this->assertThrows(NonTranslatableSpecificationException::class, fn() => Spec::toSql(Spec::property('t', $regexes['regex']), 'mysql'));
        $this->assertTrue(str_contains($e->getMessage(), 'regular expression'), 'a mensagem do regex diz que é o regex');

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
        foreach ($regexes + $wildcards as $label => $leaf) {
            $this->assertThrows(NonTranslatableSpecificationException::class, fn() => Spec::toSql(Spec::property('t', $leaf), $external), "externo: {$label}");
        }
        $this->assertEquals('("t" IS NOT NULL AND "t" ~ :p1)', Spec::toSql(Spec::property('t', Spec::regex('/a/')), $external)->getSql(), 'o resto do dialeto externo segue igual');
    }

    private function testCriteriaRefuses(): void
    {
        if (!class_exists(TFilter::class)) {
            return;
        }
        $ai = TextComparison::IgnoreCaseAndAccents;
        $trees = [
            'wildcard' => Spec::property('title', Spec::wildcard('*configura*', $ai)),
            'regex' => Spec::property('title', Spec::regex('/^configura/', $ai)),
            'not(wildcard)' => Spec::property('title', Spec::not(Spec::wildcard('*configura*', $ai))),
            'not(regex)' => Spec::property('title', Spec::not(Spec::regex('/cao$/', $ai))),
            'not(property(wildcard))' => Spec::not(Spec::property('title', Spec::wildcard('sao*', $ai))),
            'not(property(regex))' => Spec::not(Spec::property('title', Spec::regex('/^sao/', $ai))),
            'and' => Spec::property('id', Spec::greaterThan(1))->and(Spec::property('title', Spec::regex('/^sao/', $ai))),
        ];
        foreach ($trees as $label => $tree) {
            $e = $this->assertThrows(NonTranslatableCriteriaException::class, fn() => Spec::toCriteria($tree), "TCriteria: {$label}");
            $this->assertTrue(str_contains($e->getMessage(), 'ignores case and accents'), "TCriteria: {$label} (mensagem)");
        }
    }

    /**
     * RN-04: a busca da documentação do 035 continua: "*configura*" e "^configura" acham "Configuração".
     */
    private function testDocumentationExample(): void
    {
        // --- Documentação (2.3.0): wildcard and regex ignoring accents ---
        $byGlob = Spec::property('title', Spec::wildcard('*configura*', TextComparison::IgnoreCaseAndAccents));
        $byRegex = Spec::property('title', Spec::regex('/^configura/', TextComparison::IgnoreCaseAndAccents));

        $a = $byGlob->isSatisfiedBy((object) ['title' => 'Configuração do servidor']);    // true
        $b = $byRegex->isSatisfiedBy((object) ['title' => 'CONFIGURAÇÃO']);               // true
        $c = Spec::wildcard('*configuracao*', false)->isSatisfiedBy('Configuração');      // false: IgnoreCase compares accents
        $d = Spec::regex('/\D+/', TextComparison::IgnoreCaseAndAccents)->isSatisfiedBy('2026'); // false: \D stays "not a digit"

        $postgres = Spec::toSql($byRegex, 'pgsql');                                       // folds the column; binds '^configura'
        $mysql = Spec::toSql($byGlob, 'mysql')->getSql();
        // (`title` IS NOT NULL AND CONVERT(`title` USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE :p1)
        try {
            Spec::toSql($byRegex, 'mysql');
            $refused = false;
        } catch (NonTranslatableSpecificationException) {
            $refused = true;                                                              // MySQL's regex ignores the collation
        }
        // --- fim do exemplo ---

        $this->assertTrue($a);
        $this->assertTrue($b);
        $this->assertFalse($c);
        $this->assertFalse($d);
        $this->assertEquals([':p1' => '^configura'], $postgres->getParameters());
        $this->assertEquals('(`title` IS NOT NULL AND CONVERT(`title` USING utf8mb4) COLLATE utf8mb4_0900_ai_ci LIKE :p1)', $mysql);
        $this->assertTrue($refused);
    }

    /** README 20.1 (2.3.0): o bloco PHP é igual nos dois idiomas, fora os comentários. */
    private function testReadme20_1BlocksAreTheSameInBothLanguages(): void
    {
        $root = dirname(__DIR__, 2);
        $english = ReadmeBlocks::of($root . '/README.md', '20.1');
        $portuguese = ReadmeBlocks::of($root . '/README.pt-BR.md', '20.1');
        $this->assertCount(1, $english, 'um bloco PHP no exemplo 20.1 (EN)');
        $this->assertCount(1, $portuguese, 'um bloco PHP no exemplo 20.1 (pt-BR)');
        $this->assertEquals(ReadmeBlocks::codeTokens($english[0]), ReadmeBlocks::codeTokens($portuguese[0]), 'README 20.1: bloco igual fora os comentários');
        $this->assertTrue(str_contains($english[0], "Spec::wildcard('*configura*', TextComparison::IgnoreCaseAndAccents)"), 'README 20.1: o bloco é o exemplo executado acima');
    }

    ///////////////////////////////////////////////////////////////////////////
    // PdoRepository e bancos reais
    ///////////////////////////////////////////////////////////////////////////

    private function testSqliteRepositoryRefusesUnlessFallback(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE aspec_accent_row29 (id INTEGER PRIMARY KEY, title TEXT NULL)');
        $repository = PdoRepository::forEntity($pdo, Module29AccentRow::class);
        $repository->putAll(array_map(fn(int $i, ?string $t): Module29AccentRow => new Module29AccentRow($i + 1, $t), array_keys(self::VALUES), self::VALUES));
        foreach ([Spec::wildcard('*coracao*', TextComparison::IgnoreCaseAndAccents), Spec::regex('/^cora[cç][aã]o$/', TextComparison::IgnoreCaseAndAccents)] as $leaf) {
            $spec = Spec::property('title', $leaf);
            $repository->withInMemoryFallback(false);
            $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $repository->findAll($spec), 'SQLite recusa');
            $repository->withInMemoryFallback();
            $this->assertEquals([6, 7, 8], array_map(fn(Module29AccentRow $r): int => $r->id, $repository->findAll($spec)), 'com fallback, a resposta da memória');
        }
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
     * offers; the regular expression is refused on MySQL/MariaDB; the documented limits are checked as
     * positive controls. Returns the number of queries run.
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
            $pdo->exec('DROP FUNCTION IF EXISTS aspec_fold29(text) CASCADE');
            $pdo->exec('CREATE FUNCTION aspec_fold29(t text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE RETURN ' . PostgreSqlDialect::builtInAccentFolding('t'));
            $dialects['aspec_fold29()'] = new PostgreSqlDialect('aspec_fold29');
        }

        $queries = 0;
        $sets = [
            'main' => [self::VALUES, $this->wildcardBattery() + $this->regexBattery()],
            'limits' => [self::LIMIT_VALUES, $this->limitBattery()],
        ];
        foreach ($sets as $set => [$values, $battery]) {
            $pdo->exec('DROP TABLE IF EXISTS aspec_accent_parity29');
            $definition = implode(', ', array_map(fn(string $c, string $t): string => "{$c} {$t} NULL", array_keys($columns), $columns));
            $pdo->exec("CREATE TABLE aspec_accent_parity29 (id INT PRIMARY KEY, {$definition})");
            $insert = $pdo->prepare('INSERT INTO aspec_accent_parity29 VALUES (?' . str_repeat(', ?', count($columns)) . ')');
            foreach ($values as $i => $value) {
                $row = [$i + 1];
                foreach ($columns as $type) {
                    $row[] = $this->storedValues([$value], $type)[0];
                }
                $insert->execute($row);
            }

            $mismatches = [];
            $limited = [];
            foreach ($battery as $label => [$spec]) {
                $isRegex = str_contains($label, 'regex(');
                foreach (array_keys($columns) as $column) {
                    $expected = $this->memoryIds($spec, $this->storedValues($values, $columns[$column]));
                    foreach ($dialects as $dialectLabel => $dialect) {
                        if ($isRegex && !$isPg) {
                            // D4: MySQL/MariaDB recusam o regex sem acento (medido: o motor de regex ignora a collation)
                            $this->assertThrows(NonTranslatableSpecificationException::class, fn() => Spec::toSql(Spec::property($column, $spec), $dialect), "{$family}: {$label} recusado");
                            continue;
                        }
                        $clause = Spec::toSql(Spec::property($column, $spec), $dialect);
                        $statement = $pdo->prepare("SELECT id FROM aspec_accent_parity29 WHERE {$clause->getSql()} ORDER BY id");
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
            $pdo->exec('DROP TABLE aspec_accent_parity29');
            $this->assertEquals([], $mismatches, "RN-02: sem acento, o SQL de {$family} seleciona o que a memória aceita:\n  " . implode("\n  ", $mismatches));
            $unobserved = array_keys(array_filter($limited, fn(bool $observed): bool => !$observed));
            $this->assertEquals([], $unobserved, "limites documentados de {$family} ({$set}) que não se observaram:\n  " . implode("\n  ", $unobserved));
        }

        if ($isPg) {
            $pdo->exec('DROP FUNCTION IF EXISTS aspec_fold29(text) CASCADE');
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
     * The documented limits. MySQL/MariaDB (the LIKE of 2.1.0): the Unicode collation algorithm is not the
     * decomposition (letters with a stroke and ð match their base letter, й does not match и, katakana
     * matches hiragana). PostgreSQL: on a "C" column lower() folds ASCII letters only (1.14.0) and the
     * class shorthands take only ASCII letters; `\d` is the ASCII digits only on every column.
     */
    private function isDocumentedLimit(string $family, string $dialect, string $set, string $label, string $column): bool
    {
        if ($family === 'pgsql') {
            if ($set === 'limits' && in_array($label, ['regex(/\d/)', 'regex(/^\D+$/)'], true)) {
                return true;
            }

            return $column === 'cc' && in_array($label, self::PG_C_COLLATION_LIMITS[$dialect] ?? [], true);
        }

        return $set === 'limits' && in_array($label, ["wildcard('*soren*')", "wildcard('*lodz*')", "wildcard('l?dz')", "wildcard('de')", "wildcard('*иошкар*')", "wildcard('か')"], true)
            && !($column === 'l1' && in_array($label, ["wildcard('*lodz*')", "wildcard('l?dz')", "wildcard('*иошкар*')", "wildcard('か')"], true));
    }

    /**
     * PostgreSQL "C" column, per dialect: the queries whose answer the ASCII-only lower() changes (built-in
     * folding: the column side is lowered under "C"; the wrapper lowers inside the function) and those that
     * the ASCII-only classes and case-insensitive matching of `~*` under "C" change (every dialect: `\w`,
     * `\W`, `\y`, `Æ`/`æ`).
     */
    private const PG_C_COLLATION_LIMITS = [
        'built-in' => [
            "wildcard('*ß*')", "wildcard('*œuvre*')", 'regex(/^CÆSAR$/)', 'regex(/^\w+$/)', 'regex(/\W/)', 'regex(/ß/)', 'regex(/o\b/)',
            "wildcard('*иошкар*')", 'regex(/ł/)', 'regex(/иошкар/)',
        ],
        'aspec_fold29()' => ['regex(/^CÆSAR$/)', 'regex(/^\w+$/)', 'regex(/\W/)', 'regex(/o\b/)'],
    ];

    private function testRepositoryParity(PDO $pdo, string $family, ISqlDialect $dialect): int
    {
        $pdo->exec('DROP TABLE IF EXISTS aspec_accent_row29');
        $pdo->exec('CREATE TABLE aspec_accent_row29 (id INT PRIMARY KEY, title ' . ($family === 'pgsql' ? 'TEXT' : 'VARCHAR(40) CHARACTER SET utf8mb4') . ' NULL)');
        $repository = PdoRepository::forEntity($pdo, Module29AccentRow::class, dialect: $dialect);
        $repository->putAll(array_map(fn(int $i, ?string $t): Module29AccentRow => new Module29AccentRow($i + 1, $t), array_keys(self::VALUES), self::VALUES));
        $queries = 0;
        foreach ($this->wildcardBattery() + $this->regexBattery() as $label => [$spec, $expected]) {
            if (str_contains($label, 'regex(') && $family !== 'pgsql') {
                $this->assertThrows(NonTranslatableSpecificationException::class, fn() => $repository->findAll(Spec::property('title', $spec)), "PdoRepository {$family}: {$label} recusado");
                continue;
            }
            $found = array_map(fn(Module29AccentRow $r): int => $r->id, $repository->findAll(Spec::property('title', $spec)));
            sort($found);
            $this->assertEquals($expected, $found, "PdoRepository {$family}: {$label}");
            $queries++;
        }
        $pdo->exec('DROP TABLE aspec_accent_row29');

        return $queries;
    }
}
