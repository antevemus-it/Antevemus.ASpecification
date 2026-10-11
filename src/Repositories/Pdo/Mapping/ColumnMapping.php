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
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Mapping
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class ColumnMapping
{
    /**
     * @param string $property Property name
     * @param string $column Column name (plain SQL identifier)
     * @param ColumnType $type Concrete conversion
     * @param class-string<\UnitEnum>|null $enumClass Enum class of an Enum column
     * @param string $timezone Time zone of a temporal column
     * @param class-string<\DateTimeInterface> $dateClass Class hydrated for a temporal column
     * @param bool $isId Whether the column is the identifier
     * @param bool $isVersion Whether the column is the optimistic locking counter
     */
    public function __construct(
        public readonly string $property,
        public readonly string $column,
        public readonly ColumnType $type,
        public readonly ?string $enumClass = null,
        public readonly string $timezone = 'UTC',
        public readonly string $dateClass = \DateTimeImmutable::class,
        public readonly bool $isId = false,
        public readonly bool $isVersion = false
    ) {
    }

    /**
     * Copy with other identifier/version flags.
     */
    public function withRole(bool $isId, bool $isVersion): self
    {
        return new self(
            $this->property,
            $this->column,
            $this->type,
            $this->enumClass,
            $this->timezone,
            $this->dateClass,
            $isId,
            $isVersion
        );
    }

    /**
     * Plain description, for comparisons and diagnostics.
     *
     * @return array{property: string, column: string, type: string, enumClass: string|null, timezone: string, dateClass: string, id: bool, version: bool}
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
        ];
    }
}
