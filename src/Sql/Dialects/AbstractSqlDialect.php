<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

use Antevemus\ASpecification\Contracts\Sql\ISqlDialect;
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
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractSqlDialect implements ISqlDialect
{
    /** {@inheritdoc} */
    public function escapeIdentifier(string $identifier): string
    {
        $id = trim($identifier);

        // If identifier contains functional parentheses or delimiters, preserve it
        if (str_contains($id, '(') || str_contains($id, ')')) {
            return $id;
        }

        // If identifier is qualified (table.column), escape each segment separately
        if (str_contains($id, '.')) {
            $parts = explode('.', $id);
            return implode('.', array_map(fn($part) => $this->escapeSegment(trim($part)), $parts));
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
