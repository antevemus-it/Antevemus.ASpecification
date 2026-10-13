<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Entities\AbstractEntity;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\EntityMapping;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\IdStrategy;
use Antevemus\ASpecification\Repositories\Pdo\PdoRepository;
use Antevemus\ASpecification\Spec;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

/** Row of the parity table: a date-time, an integer and a text, all nullable. */
final class AsdMoment extends AbstractEntity
{
    public function __construct(
        private ?int $id,
        public ?DateTimeInterface $at,
        public ?int $n = null,
        public ?string $s = null
    ) {
        parent::__construct();
    }

    public function getEntityId(): ?int
    {
        return $this->id;
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}

/**
 * DateSqlParityTest - Memory × SQL parity of the comparison leaves over date-times (forward 037, D5).
 *
 * Since 2.1.0 equalTo()/notEqual()/in() compare date-times by instant (D1, BUG-20261013-LIZI), as the
 * relational leaves, at() and SQL do. For every specification below, the rows PdoRepository::find()
 * selects in the database are the rows the same specification accepts in memory, over the entities
 * as written (date-times of other classes and time zones) and over the entities hydrated back from the
 * database. Up to 2.0.0 equalTo($d) found the row in SQL and refused the same value hydrated in memory.
 * A few integer and text leaves of the soundness pool ride along.
 *
 * SQLite runs always; PostgreSQL, MySQL and MariaDB run when ASPEC_PDO_PGSQL_DSN, ASPEC_PDO_MYSQL_DSN
 * and ASPEC_PDO_MARIADB_DSN point to them (tests/Fixtures/pdo_repository/run_portability.sh brings up
 * disposable containers and runs this test).
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\PhpUnit
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DateSqlParityTest extends TestCase
{
    private const TABLE = 'asd_date_parity';

    public function testDateLeavesSelectInSqlWhatTheyAcceptInMemory(): void
    {
        $checked = [];
        foreach (self::connections() as $family => $pdo) {
            $checked[] = $family . ' ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            $repository = self::prepare($pdo, $family);
            $written = self::rows();
            $repository->putAll($written);
            $hydrated = $repository->findAll(Spec::alwaysTrue());
            self::assertCount(count($written), $hydrated, "{$family}: rows written");

            foreach (self::specifications() as $label => $spec) {
                $found = array_map(static fn(AsdMoment $m): int => (int) $m->getId(), $repository->find($spec));
                sort($found);
                foreach (['written' => $written, 'hydrated' => $hydrated] as $side => $entities) {
                    $memory = [];
                    foreach ($entities as $entity) {
                        if ($spec->isSatisfiedBy($entity)) {
                            $memory[] = (int) $entity->getId();
                        }
                    }
                    sort($memory);
                    self::assertSame($memory, $found, sprintf('%s: %s selects [%s] in SQL and accepts [%s] in memory (%s entities)', $family, $label, implode(',', $found), implode(',', $memory), $side));
                }
            }
        }

        self::assertNotEmpty($checked);
        $reported = getenv('ASPEC_PARITY_REPORT');
        if (is_string($reported) && $reported !== '') {
            file_put_contents($reported, implode("\n", $checked) . "\n", FILE_APPEND);
        }
    }

    /** @return list<AsdMoment> */
    private static function rows(): array
    {
        $utc = new DateTimeZone('UTC');
        $saoPaulo = new DateTimeZone('America/Sao_Paulo');
        $d = new DateTimeImmutable('2026-01-01 01:00:00', $utc);

        return [
            new AsdMoment(1, $d, 1, 'a'),
            new AsdMoment(2, $d->setTimezone($saoPaulo), 5, 'b'),           // same instant, other zone
            new AsdMoment(3, new DateTime('2026-01-01 01:00:00', $utc), 10, 'ab'), // same instant, mutable
            new AsdMoment(4, $d->modify('+1 second'), null, null),
            new AsdMoment(5, new DateTimeImmutable('2025-06-01 00:00:00', $utc), 2, 'A'),
            new AsdMoment(6, new DateTimeImmutable('2026-03-01 12:00:00', $utc), 6, ''),
            new AsdMoment(7, null, 0, 'abc'),
            new AsdMoment(8, new DateTimeImmutable('2026-01-01 01:00:00.250000', $utc), 9, 'ba'),
        ];
    }

    /** @return array<string, ISpecification> */
    private static function specifications(): array
    {
        $utc = new DateTimeZone('UTC');
        $d = new DateTimeImmutable('2026-01-01 01:00:00', $utc);
        $sp = $d->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        $mutable = new DateTime('2025-12-31 22:00:00', new DateTimeZone('-03:00'));
        $later = new DateTimeImmutable('2026-03-01 12:00:00', $utc);
        $earlier = new DateTimeImmutable('2025-06-01 00:00:00', $utc);
        $fraction = new DateTimeImmutable('2026-01-01 01:00:00.250000', $utc);

        $leaves = [
            'equal(d)' => Spec::equal($d),
            'equal(sp(d))' => Spec::equal($sp),
            'equal(mutable(d) -03:00)' => Spec::equal($mutable),
            'equal(d + 250ms)' => Spec::equal($fraction),
            'notEqual(d)' => Spec::notEqual($d),
            'notEqual(sp(d))' => Spec::notEqual($sp),
            'in(sp(d), later)' => Spec::in($sp, $later),
            'in(mutable(d), earlier)' => Spec::in($mutable, $earlier),
            'notIn(sp(d))' => Spec::notIn($sp),
            'at(sp(d))' => Spec::at($sp),
            'before(sp(d))' => Spec::before($sp),
            'afterOrAt(mutable(d))' => Spec::afterOrAt($mutable),
            'between(earlier, sp(d))' => Spec::between($earlier, $sp),
            'not(equal(sp(d)))' => Spec::not(Spec::equal($sp)),
            'equal(null)' => Spec::equal(null),
            'isNull()' => Spec::isNull(),
            'notEqual(null)' => Spec::notEqual(null),
        ];
        $specs = [];
        foreach ($leaves as $label => $leaf) {
            $specs["property(at, {$label})"] = Spec::property('at', $leaf);
        }
        $specs['not(property(at, in(sp(d))))'] = Spec::not(Spec::property('at', Spec::in($sp)));
        $specs['property(at, equal(sp(d))) OR property(at, equal(later))'] = Spec::anyOf(Spec::property('at', Spec::equal($sp)), Spec::property('at', Spec::equal($later)));

        // Integer and text leaves of the soundness pool
        foreach ([
            'n' => ['equal(5)' => Spec::equal(5), 'notEqual(5)' => Spec::notEqual(5), 'in(1, 2)' => Spec::in(1, 2), 'notIn(1, 2)' => Spec::notIn(1, 2), 'greaterThan(5)' => Spec::greaterThan(5), 'lessThanOrEqualTo(5)' => Spec::lessThanOrEqualTo(5), 'equal(null)' => Spec::equal(null)],
            's' => ["equal('a')" => Spec::equal('a'), "notEqual('a')" => Spec::notEqual('a'), "in('a', 'b')" => Spec::in('a', 'b'), "startsWith('a')" => Spec::startsWith('a'), "contains('b')" => Spec::contains('b'), "endsWith('a')" => Spec::endsWith('a'), 'isNull()' => Spec::isNull()],
        ] as $property => $byLabel) {
            foreach ($byLabel as $label => $leaf) {
                $specs["property({$property}, {$label})"] = Spec::property($property, $leaf);
                $specs["not(property({$property}, {$label}))"] = Spec::not(Spec::property($property, $leaf));
            }
        }

        return $specs;
    }

    private static function prepare(PDO $pdo, string $family): PdoRepository
    {
        $pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
        $pdo->exec(match ($family) {
            'pgsql' => 'CREATE TABLE ' . self::TABLE . ' (id INTEGER PRIMARY KEY, at_utc TIMESTAMP(6) NULL, n INTEGER NULL, s VARCHAR(20) NULL)',
            'mysql', 'mariadb' => 'CREATE TABLE ' . self::TABLE . ' (id INT PRIMARY KEY, at_utc DATETIME(6) NULL, n INT NULL, s VARCHAR(20) COLLATE utf8mb4_bin NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            default => 'CREATE TABLE ' . self::TABLE . ' (id INTEGER PRIMARY KEY, at_utc TEXT NULL, n INTEGER NULL, s VARCHAR(20) NULL)',
        });

        $mapping = EntityMapping::for(AsdMoment::class)
            ->table(self::TABLE)
            ->id('id', IdStrategy::Assigned)
            ->column('at', 'at_utc', ColumnType::DateTime, timezone: 'UTC')
            ->column('n', type: ColumnType::Integer)
            ->column('s', type: ColumnType::String);

        return new PdoRepository($pdo, $mapping);
    }

    /** @return array<string, PDO> */
    private static function connections(): array
    {
        $connections = ['sqlite' => new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])];
        foreach (['pgsql' => ['PGSQL', 'pdo_pgsql'], 'mysql' => ['MYSQL', 'pdo_mysql'], 'mariadb' => ['MARIADB', 'pdo_mysql']] as $family => [$env, $extension]) {
            $dsn = getenv("ASPEC_PDO_{$env}_DSN");
            if (!is_string($dsn) || $dsn === '' || !extension_loaded($extension)) {
                continue;
            }
            $user = getenv("ASPEC_PDO_{$env}_USER");
            $password = getenv("ASPEC_PDO_{$env}_PASSWORD");
            $connections[$family] = new PDO($dsn, $user === false ? null : $user, $password === false ? null : $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }

        return $connections;
    }
}
