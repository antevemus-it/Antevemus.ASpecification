<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications;

use Antevemus\ASpecification\Contracts\ISpecification;
use Closure;

/**
 * SpecificationPredicate - Adaptador de Especificação para Closure e Callbacks nativos do PHP
 *
 * Envelopa uma instância de ISpecification convertendo-a em uma Closure ou objeto invocável
 * compatível com funções de ordem superior nativas do PHP, como array_filter(), array_map(),
 * usort() e coleções iteráveis.
 *
 * Funcionalidades:
 * - Conversão estática direta via SpecificationPredicate::from($spec) retornando Closure(mixed): bool
 * - Conversão estática com inversão lógica via SpecificationPredicate::negate($spec)
 * - Suporte a invocação direta (__invoke) como objeto callable imutável
 * - Filtragem direta de arrays e iteráveis preservando ou reindexando chaves
 *
 * @template T
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Specifications
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class SpecificationPredicate
{
    /**
     * Inicializa um predicado invocável envelopando a especificação alvo.
     *
     * @param ISpecification<T> $specification Especificação a ser adaptada
     * @param bool $negated Se true, inverte o resultado booleano de isSatisfiedBy()
     */
    public function __construct(
        private ISpecification $specification,
        private bool $negated = false
    ) {
    }

    /**
     * Cria uma Closure nativa do PHP que delega a avaliação para ISpecification::isSatisfiedBy().
     *
     * Ideal para uso direto em array_filter($items, SpecificationPredicate::from($spec)).
     *
     * @template TCandidate
     * @param ISpecification<TCandidate> $specification Especificação base
     * @return Closure(mixed): bool Closure de avaliação do candidato
     */
    public static function from(ISpecification $specification): Closure
    {
        return static fn(mixed $candidate): bool => $specification->isSatisfiedBy($candidate);
    }

    /**
     * Cria uma Closure nativa do PHP que inverte o resultado de ISpecification::isSatisfiedBy().
     *
     * Ideal para rejeitar elementos que satisfazem a especificação em array_filter().
     *
     * @template TCandidate
     * @param ISpecification<TCandidate> $specification Especificação base a ser negada
     * @return Closure(mixed): bool Closure com resultado booleano invertido
     */
    public static function negate(ISpecification $specification): Closure
    {
        return static fn(mixed $candidate): bool => !$specification->isSatisfiedBy($candidate);
    }

    /**
     * Permite que a própria instância de SpecificationPredicate seja usada como callable.
     *
     * @param mixed $candidate Objeto ou valor candidato a ser avaliado
     * @return bool True se o candidato satisfizer o predicado (considerando a flag $negated)
     */
    public function __invoke(mixed $candidate): bool
    {
        $satisfied = $this->specification->isSatisfiedBy($candidate);

        return $this->negated ? !$satisfied : $satisfied;
    }

    /**
     * Converte esta instância em uma Closure explícita.
     *
     * @return Closure(mixed): bool
     */
    public function toClosure(): Closure
    {
        return $this->negated
            ? self::negate($this->specification)
            : self::from($this->specification);
    }

    /**
     * Retorna uma nova instância com a polaridade lógica invertida.
     *
     * @return self
     */
    public function inverted(): self
    {
        return new self($this->specification, !$this->negated);
    }

    /**
     * Retorna a especificação subjacente envelopada por este predicado.
     *
     * @return ISpecification<T>
     */
    public function getSpecification(): ISpecification
    {
        return $this->specification;
    }

    /**
     * Indica se este predicado está operando em modo negado.
     *
     * @return bool
     */
    public function isNegated(): bool
    {
        return $this->negated;
    }
}
