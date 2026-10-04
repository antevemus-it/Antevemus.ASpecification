<?php

namespace Antevemus\ASpecification\Helpers;

use Antevemus\ASpecification\Contracts\Helpers\ISpecificationHelper;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * AbstractSpecificationHelper class.
 *
 * Classe abstrata base para implementações de helpers de especificações.
 *
 * Fornece a estrutura base para implementações concretas de helpers,
 * definindo os métodos que devem ser implementados pelas classes derivadas.
 *
 * Implementações concretas devem fornecer:
 * - Lógica de verificação type-safe
 * - Estratégia de criação de especificações únicas
 *
 * Esta classe abstrata garante que todas as implementações sigam o contrato
 * definido pela interface ISpecificationHelper.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Helpers
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractSpecificationHelper implements ISpecificationHelper
{
    /**
     * {@inheritdoc}
     */
    abstract public function typeSafeIsSatisfiedBy(ISpecification $specification, ?object $candidate): bool;

    /**
     * {@inheritdoc}
     */
    abstract public function createUniqueSpecificationFor(object $entity): ISpecification;
}
