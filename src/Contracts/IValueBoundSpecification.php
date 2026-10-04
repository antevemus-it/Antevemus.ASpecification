<?php

namespace Antevemus\ASpecification\Contracts;

/**
 * IValueBoundSpecification interface.
 *
 * Part of the Evans/Fowler Specifications pattern.
 *
 * Interface para especificações que estão vinculadas a um valor específico.
 *
 * Outra forma de ver uma especificação vinculada a valor (value bound) é que
 * ela representa uma relação binária com um dos dois valores de operando.
 * O segundo valor do operando é aplicado como candidato em isSatisfiedBy().
 *
 * Em outras palavras, uma IValueBoundSpecification encapsula:
 * - Um valor de referência (obtido via getValue())
 * - Uma operação de comparação/validação
 * - Um candidato (fornecido via isSatisfiedBy())
 *
 * Exemplos de especificações vinculadas a valor:
 *
 * <code>
 * // Especificação que verifica se idade é maior que um valor específico
 * class AgeGreaterThanSpecification implements IValueBoundSpecification
 * {
 *     public function __construct(private int $minimumAge) {}
 *
 *     public function getValue(): int
 *     {
 *         return $this->minimumAge; // O valor vinculado
 *     }
 *
 *     public function isSatisfiedBy(?object $candidate): bool
 *     {
 *         return $candidate !== null && $candidate->age > $this->minimumAge;
 *     }
 * }
 *
 * // Especificação que verifica se preço está dentro de um range
 * class PriceInRangeSpecification implements IValueBoundSpecification
 * {
 *     public function __construct(
 *         private float $minPrice,
 *         private float $maxPrice
 *     ) {}
 *
 *     public function getValue(): array
 *     {
 *         return ['min' => $this->minPrice, 'max' => $this->maxPrice];
 *     }
 *
 *     public function isSatisfiedBy(?object $candidate): bool
 *     {
 *         return $candidate !== null
 *             && $candidate->price >= $this->minPrice
 *             && $candidate->price <= $this->maxPrice;
 *     }
 * }
 * </code>
 *
 * @template T
 * @extends ILeafSpecification<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 * @see        https://www.martinfowler.com/apsupp/spec.pdf The Specifications Pattern
 */
interface IValueBoundSpecification extends ILeafSpecification
{
    /**
     * Retorna o valor que está vinculado a esta especificação.
     *
     * Este valor representa o operando de referência usado na comparação
     * ou validação realizada por esta especificação.
     *
     * O tipo do valor retornado depende da implementação específica:
     * - Pode ser um valor escalar (int, float, string, bool)
     * - Pode ser um objeto
     * - Pode ser um array de valores (para ranges, listas, etc.)
     *
     * Exemplo:
     * <code>
     * $ageSpec = new AgeGreaterThanSpecification(18);
     * echo $ageSpec->getValue(); // 18
     *
     * $rangeSpec = new PriceRangeSpecification(10.0, 100.0);
     * print_r($rangeSpec->getValue()); // ['min' => 10.0, 'max' => 100.0]
     * </code>
     *
     * @return mixed O valor vinculado a esta especificação
     */
    public function getValue(): mixed;
}
