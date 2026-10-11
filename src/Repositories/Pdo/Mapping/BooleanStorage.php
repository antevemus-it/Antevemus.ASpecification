<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Mapping;

/**
 * BooleanStorage - How a Boolean Column Is Stored
 *
 * - Native (the default, as in 1.11.0): a real boolean in PostgreSQL (TRUE/FALSE), 1/0 elsewhere
 * - Integer: 1/0 on every driver, for the SMALLINT/INTEGER flag columns of schemas that do not use
 *   the boolean type (the Adianti tables in PostgreSQL); written values, bound specification values
 *   and the boolean literals a specification compiles to (`col = 1`) all follow it
 *
 * - Char (since 1.14.0, forward 032, D4): text values on every driver, 'Y'/'N' unless the column or
 *   the mapping declares others (`#[Column(booleanAs: BooleanStorage::Char, trueValue: 'S',
 *   falseValue: 'N')]`), for the CHAR(1) flags of legacy schemas (the Adianti `active`, `checked`,
 *   `removed`). Writes store the declared value; reads accept the declared values in any case (and
 *   the trailing spaces a CHAR column pads with) and NULL as null, and refuse ANY other stored value
 *   with a DataIntegrityException naming the column and the value, never a silent false; a
 *   specification compiles a boolean comparison to `col IN ('Y', 'y')` (every case form of the
 *   declared value, so the database finds exactly what a read accepts; `LOWER(col) = 'false'` for a
 *   text of more than two letters)
 *
 * Native and Integer columns read both forms whatever the storage (true/false, t/f, 1/0, yes/no,
 * on/off). Chosen per column (`#[Column(booleanAs: BooleanStorage::Integer)]`,
 * `EntityMapping::column(..., booleanAs:)`) or as the default of a mapping (`#[Table('t', booleanAs:
 * ...)]`, `EntityMapping::booleanAs()`); the column wins over the mapping (forward 031, D2).
 *
 * @version    1.14.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Mapping
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum BooleanStorage: string
{
    case Native = 'native';
    case Integer = 'integer';
    case Char = 'char';
}
