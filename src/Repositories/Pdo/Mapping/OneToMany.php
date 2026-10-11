<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Mapping;

use Attribute;

/**
 * OneToMany - A Collection of Child Entities (Composition When Cascaded)
 *
 * On an array property: `#[OneToMany(OrderItem::class, mappedBy: 'orderId', cascade: true)]
 * public array $items = [];` (since 1.13.0, forward 031, D7). `mappedBy` names the property of the
 * CHILD that holds the identifier of the parent: a mapped scalar property (`orderId`) or a
 * #[ManyToOne] back reference of the child to this class (`order`).
 *
 * The collection is loaded on demand with PdoRepository::with('items'), in batch, never lazily; a
 * collection that was not loaded is the empty array (or whatever the property holds) and is never
 * taken as "the parent has no children".
 *
 * With `cascade: true` the children are part of the parent (composition): putting the parent puts the
 * children its collection holds (setting their foreign key) and, when the collection was loaded,
 * removes the children that left it (orphan removal); removing the parent removes its children. With
 * `cascade: false` (the default) the relation is only read: the children are written through their
 * own repository.
 *
 * @version    1.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Mapping
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class OneToMany
{
    /**
     * @param class-string<\Antevemus\ASpecification\Contracts\Entities\IEntity> $target Child entity class
     * @param string $mappedBy Property of the child holding the parent's identifier (a mapped
     *                         property, or the child's #[ManyToOne] back reference)
     * @param bool $cascade True = composition: the children are saved and removed with the parent
     */
    public function __construct(
        public readonly string $target,
        public readonly string $mappedBy,
        public readonly bool $cascade = false
    ) {
    }
}
