<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Mapping;

use Attribute;

/**
 * SoftDelete - Removal Marks the Row Instead of Deleting It
 *
 * `#[SoftDelete('deleted_at')]` on the class: remove() and removeBy() write the instant of the
 * repository's clock into that column instead of deleting the row, and every read leaves the marked
 * rows out; PdoRepository::withTrashed() and onlyTrashed() give views that see them, forceRemove()
 * deletes for real (forward 031, D4). The column is a nullable timestamp that the repository alone
 * writes (NULL on the INSERT); add `property:` to also map it to a nullable DateTimeInterface property,
 * which is then hydrated and set on removal. On a property, `#[SoftDelete]` (optionally with the
 * column name) maps that property.
 *
 * @version    1.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Mapping
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
final class SoftDelete
{
    /**
     * @param string|null $column Column of the removal instant (default: deleted_at on the class,
     *                            the snake_case property name on a property)
     * @param string|null $property Property mapped to the column; only on the class, optional there
     */
    public function __construct(
        public readonly ?string $column = null,
        public readonly ?string $property = null
    ) {
    }
}
