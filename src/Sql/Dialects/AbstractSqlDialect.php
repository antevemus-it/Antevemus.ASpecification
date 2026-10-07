<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

use Antevemus\ASpecification\Contracts\Sql\ISqlDialect;
use Antevemus\ASpecification\Sql\Exceptions\UnsafeIdentifierException;
use Antevemus\ASpecification\Sql\Exceptions\UnsupportedSqlOperationException;

/**
 * AbstractSqlDialect - Base Implementation and Default ANSI SQL Behavior
 *
 * Provides canonical implementations for rules shared across relational dialects.
 *
 * Features:
 * - Default double-quote escaping ("identifier")
 * - Composite escaping for qualified identifiers (table.column)
 * - Universal tautologies (1 = 1 and 1 = 0)
 * - Default LIKE search handling with LOWER()
 *
 * @version    1.1.2
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractSqlDialect implements ISqlDialect
{
    /** Plain identifier segment: letter or underscore, then letters, digits, underscore or dollar. */
    private const SEGMENT = '[A-Za-z_][A-Za-z0-9_$]*';

    /** Plain or dot-qualified identifier (table.column, schema.table.column). */
    private const IDENTIFIER = '/^' . self::SEGMENT . '(?:\.' . self::SEGMENT . ')*$/';

    /**
     * Simple function call over identifiers only: NAME(ident[, ident]*) or NAME().
     * No literals, operators, nested calls or quotes: anything richer must be built outside the visitor.
     */
    private const FUNCTION_CALL = '/^' . self::SEGMENT . '\(\s*(?:' . self::SEGMENT . '(?:\.' . self::SEGMENT . ')*\s*(?:,\s*' . self::SEGMENT . '(?:\.' . self::SEGMENT . ')*\s*)*)?\)$/';

    /**
     * {@inheritdoc}
     *
     * Every identifier that reaches the WHERE clause is either quoted by the dialect
     * or rejected (BUG-20261007-SJVE). A simple function call over identifiers is the
     * only form preserved verbatim, and only when it matches the strict grammar.
     *
     * @throws UnsafeIdentifierException When the identifier does not match the accepted grammar
     */
    public function escapeIdentifier(string $identifier): string
    {
        $id = trim($identifier);

        // Simple function expression declared by the field mapper (e.g. LOWER(name)): preserved as-is
        if (preg_match(self::FUNCTION_CALL, $id) === 1) {
            return $id;
        }

        if (preg_match(self::IDENTIFIER, $id) !== 1) {
            throw new UnsafeIdentifierException($identifier);
        }

        // Qualified identifier (table.column): escape each segment separately
        if (str_contains($id, '.')) {
            return implode('.', array_map(fn($part) => $this->escapeSegment($part), explode('.', $id)));
        }

        return $this->escapeSegment($id);
    }

    /**
     * Escape a single identifier segment.
     *
     * @param string $segment
     * @return string
     */
    protected function escapeSegment(string $segment): string
    {
        // Padrão ANSI: aspas duplas
        return '"' . str_replace('"', '""', $segment) . '"';
    }

    /** {@inheritdoc} */
    public function formatBoolean(bool $value): string
    {
        return $value ? '1' : '0';
    }

    /** {@inheritdoc} */
    public function getTrueCondition(): string
    {
        return '1 = 1';
    }

    /** {@inheritdoc} */
    public function getFalseCondition(): string
    {
        return '1 = 0';
    }

    /** {@inheritdoc} */
    public function formatLike(string $column, string $paramPlaceholder, bool $caseSensitive = true): string
    {
        if ($caseSensitive) {
            return "{$column} LIKE {$paramPlaceholder}";
        }

        return "LOWER({$column}) LIKE LOWER({$paramPlaceholder})";
    }

    /** {@inheritdoc} */
    public function formatRegex(string $column, string $paramPlaceholder, bool $caseSensitive = true): string
    {
        throw new UnsupportedSqlOperationException('REGEX', $this->getFamily());
    }

    /** {@inheritdoc} */
    public function supportsRegex(): bool
    {
        return false;
    }
}
