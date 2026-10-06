<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Sql;

/**
 * IFieldMapper - Entity Property to Database Column Mapper
 *
 * Resolves object-relational impedance by mapping domain model property names
 * (e.g. camelCase 'birthDate') to physical database column expressions (e.g. snake_case 'c.dt_birth').
 *
 * Features:
 * - Static and dynamic mapping of domain attributes to SQL columns
 * - Table prefix and alias resolution
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Sql
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IFieldMapper
{
    /**
     * Translate a specification property name to its corresponding physical SQL column expression.
     *
     * @param string $propertyName Target inspected property name
     * @return string Physical column identifier (e.g. "status", "c.rental_value")
     */
    public function mapField(string $propertyName): string;
}
