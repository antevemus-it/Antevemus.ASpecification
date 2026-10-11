<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Mapping;

use Attribute;

/**
 * Version - Marks the Optimistic Locking Counter
 *
 * The integer property is set to 1 by the INSERT and incremented by every UPDATE, which only matches
 * the row while it still holds the version the entity was read with: an update (or a removal) from a
 * stale copy throws OptimisticLockException. On the class, with `property:`, it marks a counter
 * declared by a parent (`#[Version(property: 'version')]` for AbstractEntity::$version).
 *
 * @version    1.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Mapping
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
final class Version
{
    /**
     * @param string|null $column Column name (default: snake_case of the property name)
     * @param string|null $property Counter property; only on the class, required there
     */
    public function __construct(
        public readonly ?string $column = null,
        public readonly ?string $property = null
    ) {
    }
}
