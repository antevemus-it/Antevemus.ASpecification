<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Helpers;

use Antevemus\ASpecification\Contracts\Helpers\ISpecificationHelper;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * AbstractSpecificationHelper - Base Abstract Class for Specification Helpers
 *
 * Provides the foundational structure for concrete specification helpers,
 * defining contracts required by derived classes.
 *
 * Concrete implementations must provide:
 * - Type-safe candidate evaluation logic
 * - Unique entity identity specification generation strategy
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Helpers
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
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
