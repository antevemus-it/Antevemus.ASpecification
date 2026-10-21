<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Cache;

use Antevemus\ASpecification\Engine\PdoRuleCatalog;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * PdoCatalogVersion - The Version of a Relational Rule Catalog, Read in Light Queries
 *
 * An ICatalogVersion over PDO: for each table, `SELECT COUNT(*)` and, when a version column is given,
 * `MAX(<column>)`, one query per table; the token is the hash of the answers. A row inserted or deleted
 * changes the count; a row updated changes the largest value of the column when the application stamps
 * it on every write (an `updated_at` stamped by the screen that edits the rules, or a version counter).
 * Without a column only the counts take part, so an update that keeps the number of rows is seen only
 * at the end of the TTL or after an explicit invalidate(). An update stamp with a resolution of one second
 * misses a second update in the same second, and a stamp written backwards misses the update; a counter
 * bumped by the writer has neither limit.
 *
 * The reference DDL of the library (`resources/sql/rule_catalog.ansi.sql`) has no update stamp; a table of
 * an application that keeps one (`updated_at`, as the audit columns many applications add) passes it to
 * forRuleCatalog(). An application with a one-row version table passes it as one more table:
 * `new PdoCatalogVersion($pdo, ['business_rule' => 'updated_at', 'catalog_version' => 'version'])`.
 *
 * Identifiers are validated as PdoRuleCatalog validates them (`[A-Za-z_][A-Za-z0-9_]*`, optionally
 * qualified by a schema) and never quoted; nothing of the input reaches the SQL as a value.
 *
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Cache
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoCatalogVersion implements ICatalogVersion
{
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    /** @var list<string> One query per table */
    private readonly array $queries;

    /**
     * @param PDO $pdo Connection to the catalog
     * @param array<string, string|null> $tables Table => version column (null = the row count only); at least one
     * @throws InvalidArgumentException When there is no table or an identifier is not a plain SQL identifier
     */
    public function __construct(private readonly PDO $pdo, array $tables)
    {
        if ($tables === []) {
            throw new InvalidArgumentException('PdoCatalogVersion needs at least one table.');
        }
        $queries = [];
        foreach ($tables as $table => $column) {
            if (!is_string($table) || preg_match(self::IDENTIFIER, $table) !== 1) {
                throw new InvalidArgumentException(sprintf('Catalog version table "%s" is not a plain SQL identifier.', (string) $table));
            }
            if ($column !== null && preg_match(self::IDENTIFIER, $column) !== 1) {
                throw new InvalidArgumentException(sprintf('Catalog version column "%s" of table "%s" is not a plain SQL identifier.', $column, $table));
            }
            $queries[] = $column === null
                ? sprintf('SELECT COUNT(*) FROM %s', $table)
                : sprintf('SELECT COUNT(*), MAX(%s) FROM %s', $column, $table);
        }
        $this->queries = $queries;
    }

    /**
     * The version of the three tables of a PdoRuleCatalog (rules, document groups, document requirements),
     * with the same version column in each (its real name), or the row counts only.
     *
     * @param string|null $column Real name of the update stamp or version column of the three tables (null = counts only)
     * @throws InvalidArgumentException When the column is not a plain SQL identifier
     */
    public static function forRuleCatalog(PDO $pdo, PdoRuleCatalog $catalog, ?string $column = null): self
    {
        return new self($pdo, array_fill_keys($catalog->getTables(), $column));
    }

    /**
     * The SQL run on every read, one statement per table.
     *
     * @return list<string>
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    /**
     * {@inheritdoc}
     *
     * @throws RuntimeException When a query fails (the PDOException is the previous one)
     */
    public function catalogVersion(): string
    {
        $answers = [];
        foreach ($this->queries as $sql) {
            try {
                $statement = $this->pdo->query($sql);
                $row = $statement === false ? false : $statement->fetch(PDO::FETCH_NUM);
            } catch (PDOException $e) {
                throw new RuntimeException(sprintf('The catalog version could not be read (%s): %s', $sql, $e->getMessage()), previous: $e);
            }
            if ($row === false) {
                throw new RuntimeException(sprintf('The catalog version could not be read (%s).', $sql));
            }
            // As text, so that every connection gives the same token whatever its fetch settings
            $answers[] = array_map(static fn(mixed $value): ?string => $value === null ? null : (string) $value, $row);
        }

        return hash('xxh128', json_encode($answers, JSON_THROW_ON_ERROR));
    }
}
