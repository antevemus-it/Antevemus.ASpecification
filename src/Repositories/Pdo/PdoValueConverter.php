<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo;

use Antevemus\ASpecification\Repositories\Pdo\Mapping\BooleanStorage;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnMapping;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use PDO;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * PdoValueConverter - Column Type Conversion Between PHP and the Driver
 *
 * Converts a property value to the value bound for its column and a fetched value back to the
 * property value, by the ColumnType of the mapping (forward 028, D3). The only driver-specific form
 * is the boolean: PostgreSQL gets a real boolean, every other driver 1/0, and a column stored as
 * BooleanStorage::Integer gets 1/0 on every driver (since 1.13.0, forward 031, D2). Values of specification
 * parameters go through toQueryParameter(): objects, booleans and arrays are converted like a
 * written value of that column (a DateTimeImmutable compared with a DateTime column is bound in the
 * stored format and time zone, an enum by its value), other scalars are bound as given.
 *
 * toProperty() (since 1.13.0) is the lenient read behind EntityMapping::hydrate(): a value already of
 * the property's PHP type is kept, any other is converted like a fetched value.
 *
 * @internal Used by PdoRepository and EntityMapping; not part of the public API.
 * @version    1.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoValueConverter
{
    /** @var array<string, DateTimeZone> */
    private array $zones = [];

    /**
     * @param string $family Dialect family of the connection (pgsql, mysql, sqlite...)
     */
    public function __construct(private readonly string $family)
    {
    }

    /**
     * Value bound for a column when the property holds $value.
     *
     * @throws InvalidArgumentException When the value does not fit the column type
     */
    public function toDatabase(ColumnMapping $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        try {
            return match ($column->type) {
                ColumnType::String => $this->toText($column, $value),
                ColumnType::Integer => $this->toInteger($column, $value),
                ColumnType::Float => is_float($value) || is_int($value) ? $this->floatText((float) $value) : $this->toText($column, $value),
                ColumnType::Decimal => is_float($value) ? $this->floatText($value) : $this->toText($column, $value),
                ColumnType::Boolean => $this->toBoolean($column, $value),
                ColumnType::Date => $this->toDate($column, $value)->format('Y-m-d'),
                ColumnType::DateTime => $this->formatDateTime($this->toDate($column, $value)->setTimezone($this->zone($column->timezone))),
                ColumnType::Json => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                ColumnType::Enum => $value instanceof BackedEnum ? $value->value : ($value instanceof UnitEnum ? $value->name : $this->scalar($column, $value)),
                ColumnType::Raw, ColumnType::Auto => $this->scalar($column, $value),
            };
        } catch (JsonException $e) {
            throw new InvalidArgumentException(sprintf('Property "%s" cannot be encoded as JSON: %s', $column->property, $e->getMessage()), 0, $e);
        }
    }

    /**
     * Value bound for a specification parameter compared with the column.
     */
    public function toQueryParameter(ColumnMapping $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_object($value) || is_bool($value) || is_array($value)) {
            return $this->toDatabase($column, $value);
        }

        return $value;
    }

    /**
     * Property value of a fetched column value.
     *
     * @throws InvalidArgumentException When the stored value cannot be converted (bad date, JSON or enum case)
     */
    public function fromDatabase(ColumnMapping $column, mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }
        if (is_resource($raw) && $column->type !== ColumnType::Raw) {
            $raw = (string) stream_get_contents($raw);
        }

        try {
            return match ($column->type) {
                ColumnType::String, ColumnType::Decimal => is_float($raw) ? $this->floatText($raw) : (string) $raw,
                ColumnType::Integer => (int) $raw,
                ColumnType::Float => (float) $raw,
                ColumnType::Boolean => $this->readBoolean($raw),
                ColumnType::Date => $this->readDate($column, (string) $raw, true),
                ColumnType::DateTime => $this->readDate($column, (string) $raw, false),
                ColumnType::Json => json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR),
                ColumnType::Enum => $this->readEnum($column, $raw),
                ColumnType::Raw, ColumnType::Auto => $raw,
            };
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidArgumentException(sprintf(
                'Column "%s" holds a value that cannot be converted to property "%s" (%s): %s',
                $column->column,
                $column->property,
                $column->type->value,
                $e->getMessage()
            ), 0, $e);
        }
    }

    /**
     * Property value of a value given by the caller (EntityMapping::hydrate()): kept when it already
     * has the property's PHP type, converted like a fetched value otherwise (since 1.13.0).
     *
     * @throws InvalidArgumentException When the value cannot be converted
     */
    public function toProperty(ColumnMapping $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $kept = match ($column->type) {
            ColumnType::String, ColumnType::Decimal => is_string($value),
            ColumnType::Integer => is_int($value),
            ColumnType::Float => is_float($value),
            ColumnType::Boolean => is_bool($value),
            ColumnType::Json => is_array($value),
            ColumnType::Enum => $column->enumClass !== null && $value instanceof $column->enumClass,
            ColumnType::Date, ColumnType::DateTime => false,
            ColumnType::Raw, ColumnType::Auto => true,
        };
        if ($kept) {
            return $value;
        }
        if ($column->type === ColumnType::Float && is_int($value)) {
            return (float) $value;
        }
        if ($column->type->isTemporal() && $value instanceof DateTimeInterface) {
            if ($value instanceof $column->dateClass) {
                return $value;
            }
            return $column->dateClass === DateTime::class
                ? DateTime::createFromInterface($value)
                : DateTimeImmutable::createFromInterface($value);
        }
        if (is_array($value) || $value instanceof DateTimeInterface || (is_object($value) && !$value instanceof Stringable && !$value instanceof UnitEnum)) {
            throw $this->mismatch($column, $value);
        }
        if ($value instanceof UnitEnum) {
            $value = $value instanceof BackedEnum ? $value->value : $value->name;
        }

        return $this->fromDatabase($column, $value instanceof Stringable ? (string) $value : $value);
    }

    /**
     * PDO parameter type for a bound value.
     */
    public function bindType(mixed $value): int
    {
        return match (true) {
            $value === null => PDO::PARAM_NULL,
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            default => PDO::PARAM_STR,
        };
    }

    /**
     * Bindable form of a value that reached the binder without a column (an identifier lookup).
     */
    public function scalarOrString(mixed $value): mixed
    {
        if ($value === null || is_int($value) || is_string($value) || is_bool($value)) {
            return $value;
        }
        if (is_float($value)) {
            return $this->floatText($value);
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }

        throw new InvalidArgumentException(sprintf('A %s value cannot be bound as a parameter.', get_debug_type($value)));
    }

    private function toText(ColumnMapping $column, mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return $this->floatText($value);
        }
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }
        if ($value instanceof DateTimeInterface) {
            return $this->formatDateTime($value);
        }

        throw $this->mismatch($column, $value);
    }

    private function toInteger(ColumnMapping $column, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value instanceof BackedEnum && is_int($value->value)) {
            return $value->value;
        }
        if ((is_string($value) || is_float($value)) && is_numeric($value) && (float) $value === floor((float) $value)) {
            return (int) $value;
        }

        throw $this->mismatch($column, $value);
    }

    private function toBoolean(ColumnMapping $column, mixed $value): bool|int
    {
        $bool = is_bool($value) ? $value : $this->readBoolean($value);

        return ($this->family === 'pgsql' && $column->booleanStorage === BooleanStorage::Native) ? $bool : ($bool ? 1 : 0);
    }

    private function readBoolean(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if (is_int($raw) || is_float($raw)) {
            return $raw != 0;
        }

        return in_array(strtolower(trim((string) $raw)), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }

    private function toDate(ColumnMapping $column, mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return new DateTimeImmutable($value, $this->zone($column->timezone));
            } catch (Throwable $e) {
                throw new InvalidArgumentException(sprintf('"%s" is not a date for property "%s".', $value, $column->property), 0, $e);
            }
        }

        throw $this->mismatch($column, $value);
    }

    private function formatDateTime(DateTimeInterface $value): string
    {
        return $value->format('u') === '000000' ? $value->format('Y-m-d H:i:s') : $value->format('Y-m-d H:i:s.u');
    }

    private function readDate(ColumnMapping $column, string $raw, bool $dateOnly): DateTimeInterface
    {
        $zone = $this->zone($column->timezone);
        $text = $dateOnly ? substr(trim($raw), 0, 10) . ' 00:00:00' : trim($raw);
        $value = new DateTimeImmutable($text, $zone);
        // A stored offset (timestamptz) is honoured, then shown in the column zone
        $value = $value->setTimezone($zone);

        return $column->dateClass === DateTime::class ? DateTime::createFromImmutable($value) : $value;
    }

    private function readEnum(ColumnMapping $column, mixed $raw): UnitEnum
    {
        /** @var class-string<UnitEnum> $enum */
        $enum = (string) $column->enumClass;
        if (is_subclass_of($enum, BackedEnum::class)) {
            $reflection = new \ReflectionEnum($enum);
            $backing = (string) $reflection->getBackingType();
            $value = $backing === 'int' ? (int) $raw : (string) $raw;

            return $enum::from($value);
        }

        foreach ($enum::cases() as $case) {
            if ($case->name === (string) $raw) {
                return $case;
            }
        }

        throw new InvalidArgumentException(sprintf('"%s" is not a case of %s.', (string) $raw, $enum));
    }

    private function scalar(ColumnMapping $column, mixed $value): mixed
    {
        if (is_scalar($value)) {
            return is_float($value) ? $this->floatText($value) : $value;
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }

        throw $this->mismatch($column, $value);
    }

    private function floatText(float $value): string
    {
        $text = var_export($value, true);

        return str_contains($text, 'E') || str_contains($text, 'e') ? sprintf('%.17G', $value) : $text;
    }

    private function zone(string $name): DateTimeZone
    {
        return $this->zones[$name] ??= new DateTimeZone($name);
    }

    private function mismatch(ColumnMapping $column, mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'A %s value does not fit the %s column "%s" (property "%s").',
            get_debug_type($value),
            $column->type->value,
            $column->column,
            $column->property
        ));
    }
}
