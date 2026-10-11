<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Mapping;

use Attribute;

/**
 * ManyToOne - A Reference to Another Entity (Aggregation)
 *
 * On a nullable property typed with the referenced class (or untyped):
 * `#[ManyToOne(Customer::class, 'customer_id')] public ?Customer $customer = null;` (since 1.13.0,
 * forward 031, D7). The foreign key column must also be mapped to a scalar property of the entity
 * (`#[Column('customer_id')] public ?int $customerId;`): that property is the stored truth; the object
 * is a convenience loaded on demand with PdoRepository::with('customer'), never lazily.
 *
 * A reference is NEVER cascaded: putting the entity writes the identifier of the referenced object
 * into the foreign key property (the referenced object must already have one) and never writes the
 * referenced entity itself. A reference that was not loaded is null, and that null never clears the
 * foreign key: only a reference loaded non-null and then set to null clears it.
 *
 * @version    1.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Mapping
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ManyToOne
{
    /**
     * @param class-string<\Antevemus\ASpecification\Contracts\Entities\IEntity> $target Referenced entity class
     * @param string $joinColumn Foreign key column of this table, mapped to a property of this entity;
     *                           it holds the identifier of the referenced entity
     */
    public function __construct(
        public readonly string $target,
        public readonly string $joinColumn
    ) {
    }
}
