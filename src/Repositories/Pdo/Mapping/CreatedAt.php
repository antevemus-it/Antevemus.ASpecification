<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Mapping;

use Attribute;

/**
 * CreatedAt - Marks the Creation Timestamp
 *
 * The temporal property (DateTimeInterface, Date or DateTime column) is filled by the repository
 * with the instant of its clock when the entity is inserted and holds no value yet (a value the
 * entity already carries, as in an import, is kept); an UPDATE never writes it (forward 031, D4).
 * On a property: `#[CreatedAt]`, optionally with `column:` (default: the name of a #[Column] on the
 * same property, else the snake_case property name). On the class, with `property:`, it marks a
 * property declared by a parent.
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
final class CreatedAt
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
