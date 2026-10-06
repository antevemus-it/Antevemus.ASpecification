<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

/**
 * MySqlDialect - Specialized Dialect for MySQL and MariaDB
 *
 * Provides support for backtick delimiters (`column`), case-sensitive matching with BINARY,
 * numeric boolean representation (1/0), and the native REGEXP operator.
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class MySqlDialect extends AbstractSqlDialect
{
    /** {@inheritdoc} */
    public function getFamily(): string
    {
        return 'mysql';
    }

    /** {@inheritdoc} */
    protected function escapeSegment(string $segment): string
    {
        return '`' . str_replace('`', '``', $segment) . '`';
    }

    /** {@inheritdoc} */
    public function formatLike(string $column, string $paramPlaceholder, bool $caseSensitive = true): string
    {
        if ($caseSensitive) {
            return "BINARY {$column} LIKE {$paramPlaceholder}";
        }

        return "{$column} LIKE {$paramPlaceholder}";
    }

    /** {@inheritdoc} */
    public function formatRegex(string $column, string $paramPlaceholder, bool $caseSensitive = true): string
    {
        $bin = $caseSensitive ? 'BINARY ' : '';
        return "{$column} REGEXP {$bin}{$paramPlaceholder}";
    }

    /** {@inheritdoc} */
    public function supportsRegex(): bool
    {
        return true;
    }
}
