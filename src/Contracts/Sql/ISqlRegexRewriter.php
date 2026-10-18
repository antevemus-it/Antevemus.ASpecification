<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Sql;

/**
 * ISqlRegexRewriter - Rewrites a PCRE regular expression body into the syntax of a dialect (2.3.0, bug #73)
 *
 * Separate from ISqlDialect so that external dialects keep compiling, as ISqlPagination and
 * ISqlAccentInsensitiveLike. SqlQueryVisitor passes the body of every regular expression it translates
 * (RegexSpecification without a comparison, with IgnoreCase, and with IgnoreCaseAndAccents through
 * ISqlAccentInsensitiveLike::accentInsensitiveRegex()) through rewriteRegexBody() when the dialect
 * implements this interface; a dialect without it receives the body as written (the identity), which is
 * the behaviour up to 2.2.0.
 *
 * PostgreSQL implements it: its regular expressions (ARE) read PCRE's word boundaries differently, `\b`
 * is a backspace and `\B` a synonym of the backslash, so `/\bfoo/` never matched there (bug #73). The
 * rewriting writes `\b` as `\y` and `\B` as `\Y` outside a bracket expression; a bracket expression
 * (where `[\b]` is a backspace in both), its POSIX classes (`[:alpha:]`) and a `\Q...\E` quotation are
 * kept as they are, and so is every other character and escape. MySQL (ICU) and MariaDB (PCRE2) read
 * `\b` as PCRE does and do not implement it.
 *
 * @version    2.3.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Sql
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISqlRegexRewriter
{
    /**
     * The regular expression body (without PHP delimiters) written in the syntax of the dialect.
     *
     * @param string $body Regular expression body in PCRE syntax
     * @return string
     */
    public function rewriteRegexBody(string $body): string;
}
