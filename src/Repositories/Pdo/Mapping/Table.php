<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Mapping;

use Attribute;

/**
 * Table - Maps an Entity Class to a Table
 *
 * `#[Table('system_module')]` or `#[Table('schema.table')]`. The name must be a plain SQL identifier,
 * optionally qualified by one schema; it is quoted by the dialect, so give it exactly as the catalog
 * stores it (lowercase for unquoted DDL in PostgreSQL). Read by EntityMapping::fromAttributes(); the
 * nearest declaration up the class hierarchy wins.
 *
 * Since 1.13.0 (forward 031): `timezone:` is the default zone of the temporal columns that declare
 * none (D3; for tables holding local time without offset, as the Adianti ones), `booleanAs:` the
 * default storage of the Boolean columns that declare none (D2).
 *
 * Since 1.14.0 (forward 032, D4): with `booleanAs: BooleanStorage::Char`, `trueValue:`/`falseValue:`
 * are the default texts of the Char booleans ('Y'/'N' unless given).
 *
 * @version    1.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Mapping
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Table
{
    /**
     * @param string $name Table name, optionally `schema.table`
     * @param string|null $timezone Default time zone of the temporal columns (since 1.13.0)
     * @param BooleanStorage|null $booleanAs Default storage of the Boolean columns (since 1.13.0)
     * @param string|null $trueValue Default text of true of the Char booleans (since 1.14.0)
     * @param string|null $falseValue Default text of false of the Char booleans (since 1.14.0)
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $timezone = null,
        public readonly ?BooleanStorage $booleanAs = null,
        public readonly ?string $trueValue = null,
        public readonly ?string $falseValue = null
    ) {
    }
}
