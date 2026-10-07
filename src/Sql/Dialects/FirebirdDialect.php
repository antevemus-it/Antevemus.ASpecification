<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

/**
 * FirebirdDialect - Specialized Dialect for Firebird and InterBase (firebird, fbird, ibase)
 *
 * Provides support for delimited identifiers in upper case ("COLUMN"), numeric boolean flags (1/0),
 * and case-insensitive textual filtering with LOWER().
 *
 * @version    1.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class FirebirdDialect extends AbstractSqlDialect
{
    /**
     * @param string $family Specific driver family ('firebird', 'fbird', or 'ibase')
     */
    public function __construct(
        private readonly string $family = 'firebird'
    ) {
    }

    /** {@inheritdoc} */
    public function getFamily(): string
    {
        return $this->family;
    }

    /**
     * {@inheritdoc}
     *
     * Identifiers created without quotes are stored in upper case by this engine, and a quoted
     * identifier is case-sensitive, so the quoted form must be upper case to match columns created
     * by plain DDL (spec 012 RN-03, BUG-20261007-MNZN).
     */
    protected function escapeSegment(string $segment): string
    {
        return '"' . str_replace('"', '""', strtoupper($segment)) . '"';
    }
}
