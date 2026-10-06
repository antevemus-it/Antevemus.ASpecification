<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Factory;

/**
 * ISpecificationFactory - Base interface for specification factories
 *
 * Defines the common contract that all specification factories must follow.
 * Specific factories (Type, Comparison, Logical, Special, String, Collection, Date)
 * extend this interface to provide domain-specific creation methods.
 *
 * Serves as a marker and unified base for the specification factory subsystem,
 * enabling polymorphism and factory composition.
 *
 * Interface hierarchy:
 * <code>
 * ISpecificationFactory (base)
 *   ├── ITypeSpecificationFactory
 *   ├── IComparisonSpecificationFactory
 *   ├── ILogicalSpecificationFactory
 *   ├── ISpecialSpecificationFactory
 *   ├── IStringSpecificationFactory
 *   ├── ICollectionSpecificationFactory
 *   ├── IDateSpecificationFactory
 *   └── ISpecificationWrapperFactory
 * </code>
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISpecificationFactory
{
    // Marker interface - specific factories define specialized methods
}
