<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Mapping;

use Attribute;

/**
 * UpdatedAt - Marks the Modification Timestamp
 *
 * The temporal property is set by the repository to the instant of its clock on every write of the
 * row: the INSERT, each UPDATE and the soft removal (forward 031, D4). On a property: `#[UpdatedAt]`,
 * optionally with `column:`; on the class, with `property:`, it marks a property declared by a parent.
 *
 * The instant is truncated to the second (what every timestamp column type stores); a Date column
 * gets the date at midnight in its zone.
 *
 * @version    1.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Mapping
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
final class UpdatedAt
{
    /**
     * @param string|null $column Column name
     * @param string|null $property Timestamp property; only on the class, required there
     */
    public function __construct(
        public readonly ?string $column = null,
        public readonly ?string $property = null
    ) {
    }
}
