<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

/**
 * AnsiSqlDialect - Standard ANSI SQL Dialect
 *
 * Baseline implementation compliant with standard ISO/IEC 9075 SQL specifications.
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class AnsiSqlDialect extends AbstractSqlDialect
{
    /** {@inheritdoc} */
    public function getFamily(): string
    {
        return 'ansi';
    }
}
