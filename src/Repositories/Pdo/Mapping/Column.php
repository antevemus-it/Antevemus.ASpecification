<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Mapping;

use Attribute;

/**
 * Column - Maps a Property to a Column
 *
 * On a property: `#[Column('name', type: ColumnType::String)]`; every argument is optional (the
 * column name defaults to the snake_case form of the property name, the type is inferred from the
 * declared type of the property). On the class, with `property:`, it maps a property the class
 * cannot annotate because a parent declares it (`#[Column('created_at', property: 'timeOfCreation')]`);
 * it is repeatable there.
 *
 * Mapping a property is the opt-in of the author of the class (forward 028, D2): a mapped property is
 * read and written by reflection even when it is private or protected. Reading it by name from a
 * specification still follows the #[Specifiable] rule of the library.
 *
 * Since 1.13.0 (forward 031): `booleanAs:` stores a Boolean column as 1/0 on every driver
 * (BooleanStorage::Integer), whatever the default of the mapping (D2); `timezone:` wins over the
 * default zone of the mapping and of the repository (D3).
 *
 * Since 1.14.0 (forward 032, D4): `booleanAs: BooleanStorage::Char` stores a Boolean column as text,
 * with `trueValue:`/`falseValue:` ('Y'/'N' by default; letters and digits, 1 to 16 characters, distinct
 * whatever the case).
 *
 * @version    1.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Mapping
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Column
{
    /**
     * @param string|null $name Column name (default: snake_case of the property name)
     * @param ColumnType $type Conversion (default: inferred from the declared type)
     * @param string|null $property Mapped property; only on the class, required there
     * @param class-string<\UnitEnum>|null $enumClass Enum of an Enum column whose property has no enum type
     * @param string|null $timezone Time zone of a Date/DateTime column (default: the mapping's, the repository's, UTC)
     * @param BooleanStorage|null $booleanAs Storage of a Boolean column (default: the mapping's, Native); since 1.13.0
     * @param string|null $trueValue Stored text of true of a Char boolean (default: the mapping's, 'Y'); since 1.14.0
     * @param string|null $falseValue Stored text of false of a Char boolean (default: the mapping's, 'N'); since 1.14.0
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ColumnType $type = ColumnType::Auto,
        public readonly ?string $property = null,
        public readonly ?string $enumClass = null,
        public readonly ?string $timezone = null,
        public readonly ?BooleanStorage $booleanAs = null,
        public readonly ?string $trueValue = null,
        public readonly ?string $falseValue = null
    ) {
    }
}
