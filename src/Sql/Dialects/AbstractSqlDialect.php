<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

use Antevemus\ASpecification\Contracts\Sql\ISqlDialect;
use Antevemus\ASpecification\Sql\Exceptions\UnsupportedSqlOperationException;

/**
 * AbstractSqlDialect - Implementação Base e Comportamento Padrão ANSI SQL
 *
 * Fornece implementações canônicas para regras compartilhadas entre dialetos relacionais.
 *
 * Funcionalidades:
 * - Escape padrão com aspas duplas ("identificador")
 * - Escape composto para identificadores qualificados (tabela.coluna)
 * - Tautologias universais (1 = 1 e 1 = 0)
 * - Tratamento padrão de busca LIKE com LOWER()
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractSqlDialect implements ISqlDialect
{
    /** {@inheritdoc} */
    public function escapeIdentifier(string $identifier): string
    {
        $id = trim($identifier);

        // Se já contiver delimitadores ou for uma expressão funcional, mantém
        if (str_contains($id, '(') || str_contains($id, ')')) {
            return $id;
        }

        // Se contiver qualificador (tabela.coluna), escapa cada segmento separadamente
        if (str_contains($id, '.')) {
            $parts = explode('.', $id);
            return implode('.', array_map(fn($part) => $this->escapeSegment(trim($part)), $parts));
        }

        return $this->escapeSegment($id);
    }

    /**
     * Escapa um segmento único de identificador.
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
