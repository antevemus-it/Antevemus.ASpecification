<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Mapping;

/**
 * ColumnMapping - One Mapped Property, Resolved
 *
 * The property, its column, the concrete conversion (never ColumnType::Auto: the type is inferred
 * when the EntityMapping is built), the enum class of an Enum column, the time zone and the date
 * class of a temporal column, and whether the column is the identifier or the version counter.
 * Immutable; produced by EntityMapping.
 *
 * Since 1.13.0 (forward 031):
 * - $timezone is the EFFECTIVE zone: the one declared on the column, else the default of the
 *   mapping, else UTC; $timezoneDeclared tells whether the column declared it (a column that did not
 *   follows the defaults of the mapping and of the repository, D3)
 * - $booleanStorage is the effective storage of a Boolean column (declared on the column, else the
 *   default of the mapping, else Native); $booleanStorageDeclared tells whether the column declared it
 * - the lifecycle roles: creation and modification timestamps and the soft delete instant (D4). The
 *   soft delete column of a mapping that does not map it to a property is described by a ColumnMapping
 *   whose property is the empty string (EntityMapping::getSoftDelete()); it never appears among the
 *   mapped columns
 *
 * @version    1.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Mapping
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class ColumnMapping
{
    /**
     * @param string $property Property name ('' for a soft delete column not mapped to a property)
     * @param string $column Column name (plain SQL identifier)
     * @param ColumnType $type Concrete conversion
     * @param class-string<\UnitEnum>|null $enumClass Enum class of an Enum column
     * @param string $timezone Effective time zone of a temporal column
     * @param class-string<\DateTimeInterface> $dateClass Class hydrated for a temporal column
     * @param bool $isId Whether the column is the identifier
     * @param bool $isVersion Whether the column is the optimistic locking counter
     * @param BooleanStorage $booleanStorage Effective storage of a Boolean column (since 1.13.0)
     * @param bool $booleanStorageDeclared Whether the column declared its storage (since 1.13.0)
     * @param bool $timezoneDeclared Whether the column declared its time zone (since 1.13.0)
     * @param bool $isCreatedAt Whether the column is the creation timestamp (since 1.13.0)
     * @param bool $isUpdatedAt Whether the column is the modification timestamp (since 1.13.0)
     * @param bool $isSoftDelete Whether the column is the soft delete instant (since 1.13.0)
     */
    public function __construct(
        public readonly string $property,
        public readonly string $column,
        public readonly ColumnType $type,
        public readonly ?string $enumClass = null,
        public readonly string $timezone = 'UTC',
        public readonly string $dateClass = \DateTimeImmutable::class,
        public readonly bool $isId = false,
        public readonly bool $isVersion = false,
        public readonly BooleanStorage $booleanStorage = BooleanStorage::Native,
        public readonly bool $booleanStorageDeclared = false,
        public readonly bool $timezoneDeclared = false,
        public readonly bool $isCreatedAt = false,
        public readonly bool $isUpdatedAt = false,
        public readonly bool $isSoftDelete = false
    ) {
    }

    /**
     * Copy with other identifier/version flags.
     */
    public function withRole(bool $isId, bool $isVersion): self
    {
        return $this->copy(['isId' => $isId, 'isVersion' => $isVersion]);
    }

    /**
     * Copy with other lifecycle flags (since 1.13.0).
     */
    public function withLifecycle(bool $isCreatedAt, bool $isUpdatedAt, bool $isSoftDelete): self
    {
        return $this->copy(['isCreatedAt' => $isCreatedAt, 'isUpdatedAt' => $isUpdatedAt, 'isSoftDelete' => $isSoftDelete]);
    }

    /**
     * Copy with the defaults of a mapping applied: the time zone and the boolean storage the column
     * did not declare (since 1.13.0).
     */
    public function withDefaults(string $timezone, BooleanStorage $booleanStorage): self
    {
        return $this->copy([
            'timezone' => $this->timezoneDeclared ? $this->timezone : $timezone,
            'booleanStorage' => $this->booleanStorageDeclared ? $this->booleanStorage : $booleanStorage,
        ]);
    }

    /** True when the column holds a lifecycle value managed by the repository (since 1.13.0). */
    public function isLifecycle(): bool
    {
        return $this->isCreatedAt || $this->isUpdatedAt || $this->isSoftDelete;
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
            'column' => $this->column,
            'type' => $this->type->value,
            'enumClass' => $this->enumClass,
            'timezone' => $this->timezone,
            'dateClass' => $this->dateClass,
            'id' => $this->isId,
            'version' => $this->isVersion,
            'booleanStorage' => $this->booleanStorage->value,
            'booleanStorageDeclared' => $this->booleanStorageDeclared,
            'timezoneDeclared' => $this->timezoneDeclared,
            'createdAt' => $this->isCreatedAt,
            'updatedAt' => $this->isUpdatedAt,
            'softDelete' => $this->isSoftDelete,
        ];
    }

    /**
     * @param array<string, mixed> $changes Constructor argument => new value
     */
    private function copy(array $changes): self
    {
        $arguments = [
            'property' => $this->property,
            'column' => $this->column,
            'type' => $this->type,
            'enumClass' => $this->enumClass,
            'timezone' => $this->timezone,
            'dateClass' => $this->dateClass,
            'isId' => $this->isId,
            'isVersion' => $this->isVersion,
            'booleanStorage' => $this->booleanStorage,
            'booleanStorageDeclared' => $this->booleanStorageDeclared,
            'timezoneDeclared' => $this->timezoneDeclared,
            'isCreatedAt' => $this->isCreatedAt,
            'isUpdatedAt' => $this->isUpdatedAt,
            'isSoftDelete' => $this->isSoftDelete,
        ];

        return new self(...array_replace($arguments, $changes));
    }
}
