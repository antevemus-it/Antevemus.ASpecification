<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

/**
 * SqliteDialect - Specialized Dialect for SQLite 3
 *
 * Provides support for double-quoted identifiers, numeric booleans (1/0),
 * and portable text comparisons.
 *
 * Pagination (1.6.0): `LIMIT n OFFSET m`; an offset alone is `LIMIT -1 OFFSET m` (SQLite has no
 * OFFSET without LIMIT).
 *
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class SqliteDialect extends AbstractSqlDialect
{
    /** {@inheritdoc} */
    public function getFamily(): string
    {
        return 'sqlite';
    }

    /** {@inheritdoc} `LIMIT n OFFSET m`; an offset alone is `LIMIT -1 OFFSET m`. */
    protected function formatPagination(string $sql, ?int $limit, ?int $offset): string
    {
        return $this->limitOffsetPagination($sql, $limit, $offset, '-1');
    }
}
