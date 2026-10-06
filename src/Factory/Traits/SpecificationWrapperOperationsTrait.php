<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Factory\Traits;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * SpecificationWrapperOperationsTrait - Trait aggregating adapters and fluent specification wrappers (ISpecificationWrapperFactory).
 *
 * Provides delegation methods forwarding to the underlying specification wrapper factory.
 *
 * Features:
 * - Transparent specification wrapping
 * - Semantic fluency aliases (isSatisfiedBy, are, isFrom)
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Factory\Traits
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait SpecificationWrapperOperationsTrait
{
    /**
     * Fluent wrapper returning the specification without modifications.
     *
     * Idiomatic usage: "isSatisfiedBy someSpec"
     * Improves readability in contexts where conditional satisfaction is tested.
     *
     * @template T
     * @param ISpecification<T> $specification Specification to wrap
     * @return ISpecification<T> The same specification instance
     */
    public function isSatisfiedBy(ISpecification $specification): ISpecification
    {
        return $this->wrapperFactory->isSatisfiedBy($specification);
    }

    /**
     * Fluent wrapper returning the specification without modifications.
     *
     * Idiomatic usage: "are someSpec"
     *
     * @template T
     * @param ISpecification<T> $specification Specification to wrap
     * @return ISpecification<T> The same specification instance
     */
    public function are(ISpecification $specification): ISpecification
    {
        return $this->wrapperFactory->are($specification);
    }

    /**
     * Fluent wrapper returning the specification without modifications.
     *
     * Idiomatic usage: "isFrom someSpec"
     *
     * @template T
     * @param ISpecification<T> $specification Specification to wrap
     * @return ISpecification<T> The same specification instance
     */
    public function isFrom(ISpecification $specification): ISpecification
    {
        return $this->wrapperFactory->isFrom($specification);
    }
}
