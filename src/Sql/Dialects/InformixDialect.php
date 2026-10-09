<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

use InvalidArgumentException;

/**
 * InformixDialect - Specialized Dialect for IBM Informix (informix, ifx, pdo_informix)
 *
 * Features:
 * - Identifiers without delimiters by default: Informix reads a double-quoted text as a string
 *   literal unless the client sets DELIMIDENT, so `"status" = :p1` would silently compare two
 *   strings. The identifier grammar of AbstractSqlDialect (letters, digits, underscore, dollar, dot
 *   qualification) already refuses everything else, so the bare form is safe; Informix folds it to
 *   lower case. Construct with $delimitedIdentifiers = true when DELIMIDENT is set: identifiers then
 *   go out double-quoted in lower case (the case plain DDL stores them in)
 * - Boolean literals 't' and 'f' (Informix BOOLEAN)
 * - Case-insensitive LIKE through LOWER(); LIKE ... ESCAPE as in ANSI
 * - No regular expressions: Informix has no native regex operator (only MATCHES, a glob, and the
 *   optional Regex extension), so REGEX raises UnsupportedSqlOperationException as in SQLite and Firebird
 * - Pagination in the projection clause: `SELECT SKIP m FIRST n ...`
 *
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class InformixDialect extends AbstractSqlDialect
{
    /**
     * @param bool $delimitedIdentifiers True when the connection sets DELIMIDENT (identifiers double-quoted)
     */
    public function __construct(
        private readonly bool $delimitedIdentifiers = false
    ) {
    }

    /** {@inheritdoc} */
    public function getFamily(): string
    {
        return 'informix';
    }

    /**
     * {@inheritdoc}
     *
     * Bare segment (validated by the closed grammar) unless DELIMIDENT is declared.
     */
    protected function escapeSegment(string $segment): string
    {
        if (!$this->delimitedIdentifiers) {
            return $segment;
        }

        return '"' . str_replace('"', '""', strtolower($segment)) . '"';
    }

    /** {@inheritdoc} */
    public function formatBoolean(bool $value): string
    {
        return $value ? "'t'" : "'f'";
    }

    /**
     * {@inheritdoc}
     *
     * `SKIP m FIRST n` right after the SELECT keyword of the statement.
     *
     * @throws InvalidArgumentException When the statement does not start with SELECT
     */
    protected function formatPagination(string $sql, ?int $limit, ?int $offset): string
    {
        if (preg_match('/^(\s*SELECT)\b/i', $sql, $match) !== 1) {
            throw new InvalidArgumentException('Informix pagination goes in the projection clause: the statement must start with SELECT.');
        }

        $clause = '';
        if ($offset !== null) {
            $clause .= " SKIP {$offset}";
        }
        if ($limit !== null) {
            $clause .= " FIRST {$limit}";
        }

        return $match[1] . $clause . substr($sql, strlen($match[1]));
    }
}
