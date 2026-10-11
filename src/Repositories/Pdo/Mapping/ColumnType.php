<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Mapping;

/**
 * ColumnType - How a Mapped Property Is Converted to and from Its Column
 *
 * Auto infers the type from the declared type of the property when the mapping is built
 * (int, float, bool, string, array, DateTimeInterface, enum; untyped or mixed becomes Raw). Every
 * other case fixes the conversion:
 *
 * - String: (string) both ways
 * - Integer: (int) both ways
 * - Float: written as text (shortest round-trip form), read as float
 * - Decimal: exact decimal kept as text both ways (never a float)
 * - Boolean: TRUE/FALSE in PostgreSQL, 1/0 elsewhere; read from any driver form (t/f, 1/0, true/false)
 * - Date: `Y-m-d`, read as DateTimeImmutable (or DateTime) at midnight in the column time zone
 * - DateTime: converted to the column time zone (UTC by default) and written as `Y-m-d H:i:s`, with
 *   `.u` when the value has microseconds; read as DateTimeImmutable (or DateTime) in that zone
 * - Json: json_encode()/json_decode(associative) with exceptions on error
 * - Enum: a backed enum by its value, a pure enum by its case name
 * - Raw: whatever the driver returns, written as given (scalars and Stringable only)
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Mapping
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum ColumnType: string
{
    case Auto = 'auto';
    case String = 'string';
    case Integer = 'integer';
    case Float = 'float';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Json = 'json';
    case Enum = 'enum';
    case Raw = 'raw';

    /**
     * True for the types read as a date object.
     */
    public function isTemporal(): bool
    {
        return $this === self::Date || $this === self::DateTime;
    }
}
