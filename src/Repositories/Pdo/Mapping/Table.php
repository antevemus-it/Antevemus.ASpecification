<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Mapping;

use Attribute;

/**
 * Table - Maps an Entity Class to a Table
 *
 * `#[Table('system_module')]` or `#[Table('schema.table')]`. The name must be a plain SQL identifier,
 * optionally qualified by one schema; it is quoted by the dialect, so give it exactly as the catalog
 * stores it (lowercase for unquoted DDL in PostgreSQL). Read by EntityMapping::fromAttributes(); the
 * nearest declaration up the class hierarchy wins.
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Mapping
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Table
{
    /**
     * @param string $name Table name, optionally `schema.table`
     */
    public function __construct(public readonly string $name)
    {
    }
}
