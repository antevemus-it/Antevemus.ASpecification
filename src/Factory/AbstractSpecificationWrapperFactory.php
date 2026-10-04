<?php

namespace Antevemus\ASpecification\Factory;

use Antevemus\ASpecification\Contracts\Factory\ISpecificationWrapperFactory;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * AbstractSpecificationWrapperFactory class.
 *
 * Classe abstrata base para fábricas de wrappers de especificações.
 *
 * Como todos os métodos são wrappers de identidade (retornam a spec sem alterações),
 * esta classe fornece implementação completa. Classes derivadas podem sobrescrever
 * se precisarem de comportamento customizado.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractSpecificationWrapperFactory implements ISpecificationWrapperFactory
{
    /**
     * Valida que a especificação não é null.
     *
     * @param ISpecification $specification Especificação a validar
     * @throws \InvalidArgumentException Se a especificação for null
     */
    protected function validateSpecification(ISpecification $specification): void
    {
        if ($specification === null) {
            throw new \InvalidArgumentException('Specification cannot be null');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(ISpecification $specification): ISpecification
    {
        $this->validateSpecification($specification);
        return $specification;
    }

    /**
     * {@inheritdoc}
     */
    public function a(ISpecification $specification): ISpecification
    {
        $this->validateSpecification($specification);
        return $specification;
    }

    /**
     * {@inheritdoc}
     */
    public function an(ISpecification $specification): ISpecification
    {
        $this->validateSpecification($specification);
        return $specification;
    }

    /**
     * {@inheritdoc}
     */
    public function is(ISpecification $specification): ISpecification
    {
        $this->validateSpecification($specification);
        return $specification;
    }

    /**
     * {@inheritdoc}
     */
    public function isA(ISpecification $specification): ISpecification
    {
        $this->validateSpecification($specification);
        return $specification;
    }

    /**
     * {@inheritdoc}
     */
    public function isAn(ISpecification $specification): ISpecification
    {
        $this->validateSpecification($specification);
        return $specification;
    }

    /**
     * {@inheritdoc}
     */
    public function are(ISpecification $specification): ISpecification
    {
        $this->validateSpecification($specification);
        return $specification;
    }

    /**
     * {@inheritdoc}
     */
    public function isFrom(ISpecification $specification): ISpecification
    {
        $this->validateSpecification($specification);
        return $specification;
    }
}
