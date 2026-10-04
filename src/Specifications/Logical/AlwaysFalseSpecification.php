<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Logical;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\SubsumptionAndEqualityTrait;

/**
 * AlwaysFalseSpecification - Especificação folha contraditória (nunca satisfeita)
 *
 * Representa o conjunto vazio de satisfação, sendo caso especial de qualquer
 * especificação e disjunta de todas as especificações.
 *
 * Funcionalidades:
 * - Avaliação constante `false` para qualquer candidato
 * - Disjunção universal em relação a qualquer especificação (RF-10)
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Logical
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class AlwaysFalseSpecification extends AbstractSpecification
{
    use SubsumptionAndEqualityTrait;

    /**
     * @param class-string<T>|string $type Tipo do candidato
     */
    public function __construct(
        private readonly string $type = "mixed"
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * {@inheritdoc}
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        return $otherSpecification instanceof self;
    }

    /**
     * {@inheritdoc}
     */
    public function isSpecialCaseOf(ISpecification $otherSpecification): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        return true;
    }
}
