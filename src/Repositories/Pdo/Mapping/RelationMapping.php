<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Mapping;

/**
 * RelationMapping - One Mapped Relationship, as Declared
 *
 * A #[ManyToOne] reference (the foreign key is a column of this table, mapped to a property of this
 * entity) or a #[OneToMany] collection (the foreign key is a property of the child, named by
 * $mappedBy), produced by EntityMapping::manyToOne()/oneToMany() or by the attributes (since 1.13.0,
 * forward 031, D7). Immutable. The side of the other entity is resolved by the repository against
 * the mapping of the related repository (the mappings of two classes that refer to each other are
 * built independently).
 *
 * @version    1.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Mapping
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class RelationMapping
{
    public const MANY_TO_ONE = 'manyToOne';

    public const ONE_TO_MANY = 'oneToMany';

    /**
     * @param string $property Property of the entity holding the related object(s)
     * @param string $kind self::MANY_TO_ONE or self::ONE_TO_MANY
     * @param class-string<\Antevemus\ASpecification\Contracts\Entities\IEntity> $target Related entity class
     * @param string|null $joinColumn ManyToOne: foreign key column of this table
     * @param string|null $mappedBy OneToMany: property of the child holding the parent's identifier
     * @param bool $cascade OneToMany: composition (saved and removed with the parent)
     */
    public function __construct(
        public readonly string $property,
        public readonly string $kind,
        public readonly string $target,
        public readonly ?string $joinColumn = null,
        public readonly ?string $mappedBy = null,
        public readonly bool $cascade = false
    ) {
    }

    /** True for a #[OneToMany] collection. */
    public function isOneToMany(): bool
    {
        return $this->kind === self::ONE_TO_MANY;
    }

    /** True for a #[ManyToOne] reference. */
    public function isManyToOne(): bool
    {
        return $this->kind === self::MANY_TO_ONE;
    }

    /** True for a cascaded #[OneToMany] (composition). */
    public function isComposition(): bool
    {
        return $this->kind === self::ONE_TO_MANY && $this->cascade;
    }

    /**
     * Plain description, for comparisons and diagnostics.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'property' => $this->property,
            'kind' => $this->kind,
            'target' => $this->target,
            'joinColumn' => $this->joinColumn,
            'mappedBy' => $this->mappedBy,
            'cascade' => $this->cascade,
        ];
    }
}
