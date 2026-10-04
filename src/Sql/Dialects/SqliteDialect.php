<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql\Dialects;

/**
 * SqliteDialect - Dialeto Especializado para SQLite 3
 *
 * Provê suporte a identificadores com aspas duplas, booleanos numéricos (1/0)
 * e formatação de texto portátil.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Sql\Dialects
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
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
