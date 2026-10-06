<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

/**
 * SqliteDialect - Specialized Dialect for SQLite 3
 *
 * Provides support for double-quoted identifiers, numeric booleans (1/0),
 * and portable text comparisons.
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class SqliteDialect extends AbstractSqlDialect
{
    /** {@inheritdoc} */
    public function getFamily(): string
    {
        return 'sqlite';
    }
}
