<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

/**
 * FirebirdDialect - Specialized Dialect for Firebird and InterBase (firebird, fbird, ibase)
 *
 * Provides support for delimited identifiers ("column"), numeric boolean flags (1/0),
 * and case-insensitive textual filtering with LOWER().
 *
 * @version    1.1.0
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
}
