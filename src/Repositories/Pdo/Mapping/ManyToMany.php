<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Mapping;

use Attribute;

/**
 * ManyToMany - A Collection of Associated Entities Through a Join Table (Association)
 *
 * On an array property: `#[ManyToMany(Tag::class, 'post_tag', joinColumn: 'post_id',
 * inverseJoinColumn: 'tag_id')] public array $tags = [];` (since 1.14.0, forward 032, D1). The join
 * table holds one row per link: `joinColumn` refers to this entity's identifier, `inverseJoinColumn`
 * to the target's. Both names, and the table's, come only from here and must be plain SQL identifiers.
 *
 * An ASSOCIATION, never a composition: the targets live on their own (written and removed through their
 * own repository, they must have an identifier to be linked). Putting this entity with its collection
 * LOADED (PdoRepository::with('tags'), or PdoRepository::markLoaded()) synchronizes only the join table:
 * the links of the targets added are inserted, the links of the targets that left the collection are
 * deleted; no target row is ever written or deleted. A collection that was NOT loaded never touches the
 * join table. The collection is loaded on demand in batch, never lazily; a soft-removed target is left
 * out of it (its link stays). Removing this entity for real deletes its links; a soft removal keeps
 * them. The repository of the target must use the same connection.
 *
 * @version    1.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Mapping
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ManyToMany
{
    /**
     * @param class-string<\Antevemus\ASpecification\Contracts\Entities\IEntity> $target Associated entity class
     * @param string $joinTable Join table, optionally `schema.table`
     * @param string $joinColumn Column of the join table holding this entity's identifier
     * @param string $inverseJoinColumn Column of the join table holding the target's identifier
     */
    public function __construct(
        public readonly string $target,
        public readonly string $joinTable,
        public readonly string $joinColumn,
        public readonly string $inverseJoinColumn
    ) {
    }
}
