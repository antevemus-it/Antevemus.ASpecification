<?php

namespace Antevemus\ASpecification\Contracts\Factory;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * ICollectionSpecificationFactory interface.
 *
 * Contrato para fábricas que criam especificações para coleções (arrays/iterables).
 *
 * Esta interface fornece métodos para criar especificações que operam sobre
 * coleções, permitindo validações de tamanho, conteúdo e percentuais de elementos
 * que satisfazem critérios específicos.
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ICollectionSpecificationFactory extends ISpecificationFactory
{
    /**
     * Cria especificação que verifica o tamanho de uma coleção.
     *
     * Exemplo:
     * <code>
     * $spec = $factory->hasSize(equalTo(5));
     * $spec->isSatisfiedBy([1, 2, 3, 4, 5]); // true
     * $spec->isSatisfiedBy([1, 2, 3]); // false
     * </code>
     *
     * @param ISpecification<int> $sizeSpecification Especificação para o tamanho da coleção
     * @return ISpecification Especificação de tamanho de coleção
     */
    public function hasSize(ISpecification $sizeSpecification): ISpecification;

    /**
     * Alias para hasSize().
     *
     * @param ISpecification<int> $sizeSpecification Especificação para o tamanho
     * @return ISpecification
     */
    public function haveSize(ISpecification $sizeSpecification): ISpecification;

    /**
     * Alias para hasSize().
     *
     * @param ISpecification<int> $sizeSpecification Especificação para o tamanho
     * @return ISpecification
     */
    public function hasSizeOf(ISpecification $sizeSpecification): ISpecification;

    /**
     * Alias para hasSize().
     *
     * @param ISpecification<int> $sizeSpecification Especificação para o tamanho
     * @return ISpecification
     */
    public function haveSizeOf(ISpecification $sizeSpecification): ISpecification;

    /**
     * Cria especificação que verifica se a coleção está vazia.
     *
     * Equivalente a: hasSize(equalTo(0))
     *
     * @return ISpecification Especificação de coleção vazia
     */
    public function isEmpty(): ISpecification;

    /**
     * Alias para isEmpty().
     *
     * @return ISpecification
     */
    public function empty(): ISpecification;

    /**
     * Cria especificação que verifica número de elementos que satisfazem critério.
     *
     * Exemplo:
     * <code>
     * // Verifica se exatamente 3 elementos são maiores que 10
     * $spec = $factory->include(equalTo(3), greaterThan(10));
     * $spec->isSatisfiedBy([5, 12, 15, 8, 20]); // true (3 elementos: 12, 15, 20)
     * </code>
     *
     * @param ISpecification<int> $countSpecification Especificação para o número de elementos
     * @param ISpecification $elementSpecification Especificação que os elementos devem satisfazer
     * @return ISpecification Especificação de contagem de elementos aprovados
     */
    public function include(ISpecification $countSpecification, ISpecification $elementSpecification): ISpecification;

    /**
     * Alias para include().
     *
     * @param ISpecification<int> $countSpecification Especificação para o número
     * @param ISpecification $elementSpecification Especificação dos elementos
     * @return ISpecification
     */
    public function includes(ISpecification $countSpecification, ISpecification $elementSpecification): ISpecification;

    /**
     * Cria especificação que verifica percentual de elementos que satisfazem critério.
     *
     * Exemplo:
     * <code>
     * // Verifica se pelo menos 50% dos elementos são pares
     * $spec = $factory->includePercentageOf(atLeast(50), isEven());
     * $spec->isSatisfiedBy([2, 4, 5, 8, 9]); // true (60% são pares)
     * </code>
     *
     * @param ISpecification<int> $percentageSpecification Especificação para o percentual (0-100)
     * @param ISpecification $elementSpecification Especificação que os elementos devem satisfazer
     * @return ISpecification Especificação de percentual de elementos aprovados
     */
    public function includePercentageOf(ISpecification $percentageSpecification, ISpecification $elementSpecification): ISpecification;

    /**
     * Alias para includePercentageOf().
     *
     * @param ISpecification<int> $percentageSpecification Especificação para o percentual
     * @param ISpecification $elementSpecification Especificação dos elementos
     * @return ISpecification
     */
    public function includesPercentageOf(ISpecification $percentageSpecification, ISpecification $elementSpecification): ISpecification;

    /**
     * Cria especificação que verifica se todos os elementos satisfazem critério.
     *
     * Equivalente a: include(equalTo(count($collection)), $elementSpecification)
     *
     * Exemplo:
     * <code>
     * $spec = $factory->all(greaterThan(0));
     * $spec->isSatisfiedBy([1, 2, 3, 4]); // true
     * $spec->isSatisfiedBy([1, 2, 0, 4]); // false
     * </code>
     *
     * @param ISpecification $elementSpecification Especificação que todos elementos devem satisfazer
     * @return ISpecification Especificação universal de elementos
     */
    public function all(ISpecification $elementSpecification): ISpecification;

    /**
     * Cria especificação que verifica se algum elemento satisfaz critério.
     *
     * Equivalente a: include(atLeast(1), $elementSpecification)
     *
     * Exemplo:
     * <code>
     * $spec = $factory->any(greaterThan(100));
     * $spec->isSatisfiedBy([50, 75, 120]); // true (120 > 100)
     * $spec->isSatisfiedBy([50, 75, 90]); // false
     * </code>
     *
     * @param ISpecification $elementSpecification Especificação que pelo menos um elemento deve satisfazer
     * @return ISpecification Especificação existencial de elementos
     */
    public function any(ISpecification $elementSpecification): ISpecification;

    /**
     * Cria especificação que verifica se nenhum elemento satisfaz critério.
     *
     * Equivalente a: include(equalTo(0), $elementSpecification)
     *
     * @param ISpecification $elementSpecification Especificação que nenhum elemento deve satisfazer
     * @return ISpecification Especificação de negação universal de elementos
     */
    public function none(ISpecification $elementSpecification): ISpecification;
}
