<?php

namespace Antevemus\ASpecification\Contracts\Factory;

/**
 * ISpecificationFactory interface.
 *
 * Interface base para todas as fábricas de especificações.
 *
 * Define o contrato comum que todas as fábricas de especificações devem seguir.
 * Factories específicas (Type, Comparison, Logical, Special, String) devem estender
 * esta interface e adicionar seus métodos específicos.
 *
 * Esta interface serve como marcador e base comum para o sistema de factories de
 * especificações, permitindo polimorfismo e composição de diferentes tipos de factories.
 *
 * Hierarquia de interfaces:
 * <code>
 * ISpecificationFactory (base)
 *   ├── ITypeSpecificationFactory
 *   ├── IComparisonSpecificationFactory
 *   ├── ILogicalSpecificationFactory
 *   ├── ISpecialSpecificationFactory
 *   └── IStringSpecificationFactory
 * </code>
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISpecificationFactory
{
    // Marker interface - factories específicas adicionam seus métodos
}
