<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Repositories\Pdo\PdoDriverLimits;
use Antevemus\ASpecification\Sql\SqlDialect;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * PdoDriverLimitsTest - the limits of each driver family, without a database (2.11.0).
 *
 * The expected numbers are the ones PdoRepository::maxParameters()/multiRowInsert() gave before they moved to
 * PdoDriverLimits (measured on the 2.10.0 code, through a repository of each SqlDialect case): parameters per
 * statement and multi-row INSERT for every family, SQLite by its server version (3.32.0 is the threshold; an
 * empty or unreadable version gives the older limit), the version read once and only for SQLite.
 *
 * @version 2.11.0
 */
final class PdoDriverLimitsTest extends TestCase
{
    /**
     * Every family a dialect reports (the values of SqlDialect: MariaDB is the mysql family).
     *
     * @return iterable<string, array{string, int, bool}>
     */
    public static function families(): iterable
    {
        yield 'ansi' => ['ansi', 999, false];
        yield 'pgsql' => ['pgsql', 65535, true];
        yield 'mysql (and MariaDB)' => ['mysql', 65535, true];
        yield 'oracle' => ['oracle', 999, false];
        yield 'oci' => ['oci', 999, false];
        yield 'sqlsrv' => ['sqlsrv', 2000, true];
        yield 'mssql' => ['mssql', 2000, true];
        yield 'dblib' => ['dblib', 2000, true];
        yield 'firebird' => ['firebird', 999, false];
        yield 'fbird' => ['fbird', 999, false];
        yield 'ibase' => ['ibase', 999, false];
        yield 'db2' => ['db2', 999, true];
        yield 'informix' => ['informix', 999, false];
        yield 'duckdb' => ['duckdb', 999, true];
        yield 'unknown family' => ['unknown', 999, false];
    }

    #[DataProvider('families')]
    public function testEachFamilyHasItsLimitsAndNeverReadsTheConnection(string $family, int $maxParameters, bool $multiRow): void
    {
        $pdo = new PdoDriverLimitsVersionPdo('3.45.1');
        $limits = new PdoDriverLimits($family, $pdo);

        self::assertSame($maxParameters, $limits->maxParameters());
        self::assertSame($maxParameters, $limits->maxParameters(), 'the same on the next call');
        self::assertSame($multiRow, $limits->multiRowInsert());
        self::assertSame(0, $pdo->reads, 'only SQLite reads the server version');
    }

    public function testEveryDialectCaseIsCovered(): void
    {
        $covered = [...array_map(static fn(array $case): string => $case[0], iterator_to_array(self::families())), 'sqlite'];
        foreach (SqlDialect::cases() as $dialect) {
            self::assertContains($dialect->value, $covered, "{$dialect->value} has its limits declared here");
        }
    }

    /**
     * @return iterable<string, array{string|Throwable, int}>
     */
    public static function sqliteVersions(): iterable
    {
        yield '3.31.1, before the threshold' => ['3.31.1', 999];
        yield '3.32.0, the threshold' => ['3.32.0', 32766];
        yield '3.32.1' => ['3.32.1', 32766];
        yield '3.45.1' => ['3.45.1', 32766];
        yield '3.8, older' => ['3.8', 999];
        yield 'empty version' => ['', 999];
        yield 'unreadable version' => [new RuntimeException('no version'), 999];
    }

    #[DataProvider('sqliteVersions')]
    public function testSqliteDependsOnTheServerVersionReadOnceOnFirstUse(string|Throwable $version, int $maxParameters): void
    {
        $pdo = new PdoDriverLimitsVersionPdo($version);
        $limits = new PdoDriverLimits('sqlite', $pdo);
        self::assertSame(0, $pdo->reads, 'nothing is read when the limits are built');

        self::assertSame($maxParameters, $limits->maxParameters());
        self::assertSame($maxParameters, $limits->maxParameters());
        self::assertSame(1, $pdo->reads, 'the version is read once, then kept');
        self::assertSame([PDO::ATTR_SERVER_VERSION], $pdo->attributes);
        self::assertTrue($limits->multiRowInsert());
        self::assertSame(1, $pdo->reads, 'multiRowInsert() reads nothing');
    }

    public function testARealSqliteConnection(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available');
        }
        $pdo = new PDO('sqlite::memory:');
        $version = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

        $expected = version_compare($version, '3.32.0', '>=') ? 32766 : 999;
        self::assertSame($expected, (new PdoDriverLimits('sqlite', $pdo))->maxParameters(), "SQLite {$version}");
    }
}

/** A connection that only answers getAttribute() with a fixed server version (or throws), counting the reads. */
final class PdoDriverLimitsVersionPdo extends PDO
{
    public int $reads = 0;

    /** @var list<int> */
    public array $attributes = [];

    public function __construct(private readonly string|Throwable $version)
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        $this->reads++;
        $this->attributes[] = $attribute;
        if ($this->version instanceof Throwable) {
            throw $this->version;
        }

        return $this->version;
    }
}
