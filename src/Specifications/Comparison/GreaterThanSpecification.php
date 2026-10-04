<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\IValueBoundSpecification;
use Antevemus\ASpecification\Specifications\SubsumptionAndEqualityTrait;

/**
 * GreaterThanSpecification - Especificação folha para comparação `>`
 *
 * Valida se o candidato é estritamente maior que o limite configurado,
 * provendo subsunção intervalar ($x > 10 \supseteq x > 50$) e disjunção.
 *
 * Funcionalidades:
 * - Comparação estrita `>`
 * - Subsunção de intervalos maiores e igualdades superiores (RF-10)
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements IValueBoundSpecification<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class GreaterThanSpecification extends AbstractSpecification implements IValueBoundSpecification
{
    use SubsumptionAndEqualityTrait;

    /**
     * @param int|float|string $value Limite inferior estrito
     */
    public function __construct(
        private readonly int|float|string $value
    ) {
    }

    /**
     * Retorna o limite inferior configurado.
     */
    public function getValue(): int|float|string
    {
        return $this->value;
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }
        return $candidate > $this->value;
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return "mixed";
    }

    /**
     * {@inheritdoc}
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        if ($this->checkBaseGeneralization($otherSpecification)) {
            return true;
        }
        if ($otherSpecification instanceof self) {
            return $otherSpecification->getValue() >= $this->value;
        }
        if ($otherSpecification instanceof EqualSpecification) {
            return $otherSpecification->getValue() > $this->value;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($this->checkBaseDisjointness($otherSpecification)) {
            return true;
        }
        if ($otherSpecification instanceof LessThanSpecification) {
            return $otherSpecification->getValue() <= $this->value;
        }
        if ($otherSpecification instanceof EqualSpecification) {
            return $otherSpecification->getValue() <= $this->value;
        }
        return false;
    }
}
