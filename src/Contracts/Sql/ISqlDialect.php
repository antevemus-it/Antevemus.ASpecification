<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Sql;

/**
 * ISqlDialect - Contrato para Dialeto de Banco de Dados Relacional
 *
 * Define o comportamento de escape de identificadores, representação de literais booleanos,
 * operadores de busca textual (LIKE/ILIKE) e suporte a expressões regulares específico de cada SGBD.
 *
 * Funcionalidades:
 * - Identificação da família do SGBD (PostgreSQL, MySQL, SQL Server, Oracle, Firebird, SQLite)
 * - Delimitação segura de nomes de colunas e tabelas (escapeIdentifier)
 * - Formatação de booleanos em conformidade com o driver (TRUE/FALSE, 1/0, 'Y'/'N')
 * - Cláusulas canônicas de verdade e falsidade tautológica (1=1, 1=0)
 * - Mapeamento idiomático de buscas LIKE e Regex
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Sql
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISqlDialect
{
    /**
     * Retorna o identificador da família do SGBD (ex: 'pgsql', 'mysql', 'sqlsrv', 'oracle', 'firebird', 'sqlite').
     *
     * @return string
     */
    public function getFamily(): string;

    /**
     * Aplica o delimitador de escape apropriado ao identificador (ex: "coluna", `coluna`, [coluna]).
     *
     * @param string $identifier Nome da coluna ou tabela
     * @return string
     */
    public function escapeIdentifier(string $identifier): string;

    /**
     * Formata um valor booleano para a sintaxe nativa do SGBD.
     *
     * @param bool $value
     * @return string
     */
    public function formatBoolean(bool $value): string;

    /**
     * Retorna a expressão SQL universalmente avaliada como verdadeira (ex: '1 = 1').
     *
     * @return string
     */
    public function getTrueCondition(): string;

    /**
     * Retorna a expressão SQL universalmente avaliada como falsa (ex: '1 = 0').
     *
     * @return string
     */
    public function getFalseCondition(): string;

    /**
     * Formata uma comparação de padrão LIKE com suporte a sensibilidade de caixa.
     *
     * @param string $column Coluna já escapada
     * @param string $paramPlaceholder Nome do parâmetro nomeado (ex: ':p1')
     * @param bool $caseSensitive Define se a busca deve diferenciar maiúsculas de minúsculas
     * @return string
     */
    public function formatLike(string $column, string $paramPlaceholder, bool $caseSensitive = true): string;

    /**
     * Formata uma comparação por Expressão Regular com base no dialeto do SGBD.
     *
     * @param string $column Coluna já escapada
     * @param string $paramPlaceholder Nome do parâmetro nomeado (ex: ':p1')
     * @param bool $caseSensitive Define se o regex diferencia maiúsculas de minúsculas
     * @return string
     */
    public function formatRegex(string $column, string $paramPlaceholder, bool $caseSensitive = true): string;

    /**
     * Informa se o dialeto suporta operadores nativos de Expressão Regular em consultas SQL.
     *
     * @return bool
     */
    public function supportsRegex(): bool;
}
