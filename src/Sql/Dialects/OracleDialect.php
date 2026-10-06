<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

/**
 * OracleDialect - Specialized Dialect for Oracle Database (oracle, oci, oci8)
 *
 * Provides support for double-quoted identifiers, numeric booleans (1/0),
 * case-insensitive matching via LOWER(), and native REGEXP_LIKE pattern matching.
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class OracleDialect extends AbstractSqlDialect
{
    /**
     * @param string $family Specific driver family ('oracle' or 'oci')
     */
    public function __construct(
        private readonly string $family = 'oracle'
    ) {
    }

    /** {@inheritdoc} */
    public function getFamily(): string
    {
        return $this->family;
    }

    /** {@inheritdoc} */
    public function formatRegex(string $column, string $paramPlaceholder, bool $caseSensitive = true): string
    {
        $matchParam = $caseSensitive ? "'c'" : "'i'";
        return "REGEXP_LIKE({$column}, {$paramPlaceholder}, {$matchParam})";
    }

    /** {@inheritdoc} */
    public function supportsRegex(): bool
    {
        return true;
    }
}
