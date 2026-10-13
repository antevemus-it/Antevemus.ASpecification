<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Sql;

/**
 * ISqlAccentInsensitiveLike - LIKE that ignores case and accents, in the syntax of a dialect (2.1.0)
 *
 * Separate from ISqlDialect so that external dialects keep compiling, as ISqlPagination; every
 * dialect of the library implements it through AbstractSqlDialect. SqlQueryVisitor translates a
 * text operator built with TextComparison::IgnoreCaseAndAccents (and
 * EqualIgnoreCaseAndAccentsStringSpecification) through it, and refuses the specification with
 * NonTranslatableSpecificationException when the dialect does not implement this interface or
 * answers null.
 *
 * A dialect answers only where the database selects the rows the in-memory comparison accepts
 * (TextComparison::foldCaseAndAccents(): NFD, nonspacing marks removed, NFC, lower case), measured
 * against a battery of pt-BR, es, fr and de words with `ß` and `æ` kept distinct from `ss` and `ae`.
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Sql
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISqlAccentInsensitiveLike
{
    /**
     * The condition `column LIKE pattern` ignoring case and accents, or null when the dialect cannot
     * answer it as the in-memory comparison does (the visitor then refuses the specification).
     *
     * The bound pattern is the LIKE pattern of the literal as written (wildcards `%`/`_` and the
     * escape character `!` already applied); the visitor appends the `ESCAPE` clause when needed.
     *
     * @param string $column Pre-escaped column expression
     * @param string $paramPlaceholder Named parameter placeholder (e.g. ':p1')
     * @return string|null
     */
    public function formatAccentInsensitiveLike(string $column, string $paramPlaceholder): ?string;
}
