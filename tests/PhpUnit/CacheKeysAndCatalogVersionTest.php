<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\PhpUnit;

use Antevemus\ASpecification\Cache\CacheKeys;
use Antevemus\ASpecification\Cache\PdoCatalogVersion;
use Antevemus\ASpecification\Engine\PdoRuleCatalog;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * CacheKeysAndCatalogVersionTest - the keys of the caching decorators and the catalog version over PDO.
 *
 * Neither needs psr/simple-cache, so this class also runs where it is not installed.
 *
 * @version 2.8.0
 */
final class CacheKeysAndCatalogVersionTest extends TestCase
{
    public function testThePrefixAcceptsOnlyTheCharactersEveryPsr16CacheAccepts(): void
    {
        self::assertSame('aspec.', CacheKeys::DEFAULT_PREFIX);
        self::assertSame('', CacheKeys::prefix(''));
        self::assertSame('App_1.cache.', CacheKeys::prefix('App_1.cache.'));
        self::assertSame(str_repeat('a', 64), CacheKeys::prefix(str_repeat('a', 64)));
        foreach (['app:', 'a{b', 'a}b', 'a(b', 'a)b', 'a/b', 'a\\b', 'a@b', 'a b', 'á', 'a-b', str_repeat('a', 65)] as $prefix) {
            try {
                CacheKeys::prefix($prefix);
                self::fail("prefix {$prefix} accepted");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('at most 64 characters', $e->getMessage());
            }
        }
    }

    public function testAKeyIsThePrefixAndTheSegmentsJoinedByDots(): void
    {
        self::assertSame('aspec.flags.all.s1', CacheKeys::key('aspec.', 'flags', 'all', 's1'));
        self::assertSame('x', CacheKeys::key('', 'x'));
        $body = str_repeat('a', 64);
        self::assertSame('p.' . $body, CacheKeys::key('p.', $body));
        self::assertSame('p.' . str_repeat('a', 31) . '.' . str_repeat('b', 32), CacheKeys::key('p.', str_repeat('a', 31), str_repeat('b', 32)));
        foreach ([[''], ['a.b'], ['a:b'], ['a', ''], ['a-b']] as $segments) {
            try {
                CacheKeys::key('p.', ...$segments);
                self::fail('segments accepted: ' . implode('|', $segments));
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('must be made of', $e->getMessage());
            }
        }
        try {
            CacheKeys::key('p.', str_repeat('a', 32), str_repeat('b', 32));
            self::fail('a body of 65 characters accepted');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('longer than 64 characters', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        CacheKeys::key('bad:', 'a');
    }

    public function testHashesAreHexadecimalAndDependOnEveryPart(): void
    {
        $hash = CacheKeys::hash(['a', 1, null]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $hash);
        self::assertSame($hash, CacheKeys::hash(['a', 1, null]));
        self::assertNotSame($hash, CacheKeys::hash([1, 'a', null]));
        self::assertNotSame($hash, CacheKeys::hash(['a', '1', null]));
        self::assertNotSame($hash, CacheKeys::hash(['a', 1, '']));
        self::assertSame(hash('xxh128', '["a/é",1,null]'), CacheKeys::hash(['a/é', 1, null]), 'JSON without escaped slashes nor unicode');
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', CacheKeys::shortHash('repository'));
        self::assertSame(hash('xxh64', 'repository'), CacheKeys::shortHash('repository'));
    }

    public function testTheCatalogVersionChangesWithTheCountsAndTheVersionColumn(): void
    {
        $pdo = self::catalog();
        $version = new PdoCatalogVersion($pdo, ['business_rule' => 'updated_at', 'document_requirement_group' => null]);
        self::assertSame([
            'SELECT COUNT(*), MAX(updated_at) FROM business_rule',
            'SELECT COUNT(*) FROM document_requirement_group',
        ], $version->getQueries());

        $first = $version->catalogVersion();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first);
        self::assertSame($first, $version->catalogVersion(), 'unchanged catalog, same version');

        $pdo->exec("UPDATE business_rule SET text_value = 'x' WHERE code = 'r1'");
        self::assertSame($first, $version->catalogVersion(), 'an update without a new stamp is not seen');

        $pdo->exec("UPDATE business_rule SET updated_at = '2026-10-21 10:00:01' WHERE code = 'r1'");
        $second = $version->catalogVersion();
        self::assertNotSame($first, $second, 'a new stamp is seen');

        $pdo->exec("INSERT INTO document_requirement_group (id, code, scope) VALUES (9, 'g9', 'claim')");
        self::assertNotSame($second, $version->catalogVersion(), 'a new row of a table without column is seen');
    }

    public function testEveryConnectionGivesTheSameToken(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'aspec_version_');
        try {
            $pdo = new PDO('sqlite:' . $file);
            $pdo->exec('CREATE TABLE business_rule (code VARCHAR(10), version INTEGER)');
            $pdo->exec("INSERT INTO business_rule VALUES ('a', 7), ('b', 9)");
            $native = new PDO('sqlite:' . $file);
            $text = new PDO('sqlite:' . $file, options: [PDO::ATTR_STRINGIFY_FETCHES => true]);
            self::assertSame(
                (new PdoCatalogVersion($native, ['business_rule' => 'version']))->catalogVersion(),
                (new PdoCatalogVersion($text, ['business_rule' => 'version']))->catalogVersion(),
                'integers and texts of the same values give the same version'
            );
        } finally {
            @unlink($file);
        }
    }

    public function testTheVersionOfARuleCatalogCoversItsThreeTables(): void
    {
        $pdo = self::catalog();
        $catalog = new PdoRuleCatalog($pdo);
        self::assertSame([
            'SELECT COUNT(*), MAX(updated_at) FROM business_rule',
            'SELECT COUNT(*), MAX(updated_at) FROM document_requirement_group',
            'SELECT COUNT(*), MAX(updated_at) FROM document_requirement',
        ], PdoCatalogVersion::forRuleCatalog($pdo, $catalog, 'updated_at')->getQueries());
        $counts = PdoCatalogVersion::forRuleCatalog($pdo, $catalog);
        self::assertSame([
            'SELECT COUNT(*) FROM business_rule',
            'SELECT COUNT(*) FROM document_requirement_group',
            'SELECT COUNT(*) FROM document_requirement',
        ], $counts->getQueries());
        $before = $counts->catalogVersion();
        $pdo->exec("DELETE FROM business_rule WHERE code = 'r2'");
        self::assertNotSame($before, $counts->catalogVersion(), 'a deleted rule is seen by the counts');
        self::assertSame(
            ['SELECT COUNT(*) FROM app.rules'],
            (new PdoCatalogVersion($pdo, ['app.rules' => null]))->getQueries(),
            'a schema-qualified table'
        );
    }

    public function testIdentifiersAreValidated(): void
    {
        $pdo = new PDO('sqlite::memory:');
        foreach ([[[]], [['bad table' => null]], [[0 => null]], [['t' => 'col; DROP']], [['t' => 'a.b.c']], [['1t' => null]]] as [$tables]) {
            try {
                new PdoCatalogVersion($pdo, $tables);
                self::fail('accepted: ' . json_encode($tables));
            } catch (InvalidArgumentException $e) {
                self::assertMatchesRegularExpression('/needs at least one table|is not a plain SQL identifier/', $e->getMessage());
            }
        }
    }

    public function testAVersionThatCannotBeReadThrows(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            (new PdoCatalogVersion($pdo, ['missing_table' => null]))->catalogVersion();
            self::fail('no failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('SELECT COUNT(*) FROM missing_table', $e->getMessage());
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
        }
        $silent = new PDO('sqlite::memory:');
        $silent->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The catalog version could not be read (SELECT COUNT(*) FROM missing_table).');
        (new PdoCatalogVersion($silent, ['missing_table' => null]))->catalogVersion();
    }

    /** The reference DDL plus the update stamp an application keeps (as the consumers measured do). */
    private static function catalog(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $ddl = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/sql/rule_catalog.ansi.sql');
        $lines = array_filter(explode("\n", $ddl), static fn(string $line): bool => !str_starts_with(ltrim($line), '--'));
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
        foreach (['business_rule', 'document_requirement_group', 'document_requirement'] as $table) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN updated_at VARCHAR(20)");
        }
        $pdo->exec("INSERT INTO business_rule (code, name, rule_type, updated_at) VALUES ('r1', 'R1', 't', '2026-10-21 10:00:00'), ('r2', 'R2', 't', '2026-10-20 09:00:00')");

        return $pdo;
    }
}
