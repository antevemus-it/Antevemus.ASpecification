<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Mapping;

use Attribute;

/**
 * Id - Marks the Identifier Property and Its Generation Strategy
 *
 * On a property: `#[Id(strategy: IdStrategy::Auto)]`, optionally with `column:` (default: the name
 * of a #[Column] on the same property, else the snake_case property name), `type:` and, for Auto in
 * PostgreSQL, `sequence:` (without it the id is read back with `INSERT ... RETURNING`). On the class,
 * with `property:`, it marks an identifier declared by a parent
 * (`#[Id(strategy: IdStrategy::Ulid, column: 'id', property: 'entityId')]` for AbstractUlidEntity).
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Mapping
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
final class Id
{
    /**
     * @param IdStrategy $strategy Identifier generation strategy
     * @param string|null $column Column name
     * @param ColumnType $type Conversion (default: inferred)
     * @param string|null $sequence Sequence read by lastInsertId() for Auto (PostgreSQL, Oracle-like drivers)
     * @param string|null $property Identifier property; only on the class, required there
     */
    public function __construct(
        public readonly IdStrategy $strategy = IdStrategy::Auto,
        public readonly ?string $column = null,
        public readonly ColumnType $type = ColumnType::Auto,
        public readonly ?string $sequence = null,
        public readonly ?string $property = null
    ) {
    }
}
