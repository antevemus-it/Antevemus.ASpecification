<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

/**
 * OracleDialect - Dialeto Especializado para Oracle Database (oracle, oci, oci8)
 *
 * Provê suporte a identificadores com aspas duplas, booleanos numéricos (1/0),
 * busca case-insensitive com LOWER() e suporte a REGEXP_LIKE nativo.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class OracleDialect extends AbstractSqlDialect
{
    /**
     * @param string $family Identificador específico ('oracle' ou 'oci')
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
