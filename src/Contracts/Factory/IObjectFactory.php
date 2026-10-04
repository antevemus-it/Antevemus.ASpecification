<?php

namespace Antevemus\ASpecification\Contracts\Factory;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * IObjectFactory interface.
 *
 * Interface que descreve uma fábrica (factory) para criar objetos de domínio
 * baseados em especificações (Specifications).
 *
 * O padrão Factory combinado com o padrão Specification permite criar objetos
 * que atendem a critérios específicos encapsulados em especificações.
 *
 * Esta abordagem é útil para:
 * - Criar objetos configurados baseados em regras de negócio
 * - Implementar builders complexos guiados por especificações
 * - Gerar objetos mock/stub para testes que satisfazem especificações
 * - Implementar padrões de criação baseados em critérios
 *
 * Exemplo de uso:
 *
 * <code>
 * // Especificação para um produto premium
 * $premiumSpec = (new PriceGreaterThanSpecification(100))
 *     ->and(new CategorySpecification('Premium'))
 *     ->and(new InStockSpecification());
 *
 * // Fábrica cria um produto que satisfaz a especificação
 * $productFactory = new ProductFactory();
 * $premiumProduct = $productFactory->create($premiumSpec);
 *
 * // Verifica se o produto criado satisfaz a especificação
 * assert($premiumSpec->isSatisfiedBy($premiumProduct));
 * </code>
 *
 * @template T
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Factory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IObjectFactory
{
    /**
     * Cria um objeto do tipo T especificado pela especificação dada.
     *
     * A implementação desta fábrica deve garantir que o objeto retornado
     * satisfaça a especificação fornecida. Caso contrário, o comportamento
     * depende da estratégia de implementação da fábrica.
     *
     * Estratégias comuns de implementação:
     * - Lançar exceção se não puder criar objeto que satisfaz a especificação
     * - Retornar null se não puder criar objeto que satisfaz a especificação
     * - Criar "melhor aproximação" possível
     * - Criar objeto padrão e logar aviso
     *
     * Exemplo:
     * <code>
     * $spec = new ActiveUserSpecification();
     * $user = $factory->createObjectSpecifiedBy($spec);
     *
     * // O usuário criado deve satisfazer a especificação
     * assert($spec->isSatisfiedBy($user));
     * </code>
     *
     * @template T
     * @param ISpecification<T> $specification A especificação que o objeto criado deve satisfazer
     * @return T Uma instância de tipo T especificada pela especificação; comportamento adicional depende da estratégia da fábrica
     * @throws \InvalidArgumentException Se a especificação for null
     * @throws \RuntimeException Se a fábrica não puder criar um objeto que satisfaça a especificação (dependendo da estratégia)
     */
    public function createObjectSpecifiedBy(ISpecification $specification): mixed;

    /**
     * Alias de createObjectSpecifiedBy().
     *
     * Método de conveniência que fornece uma sintaxe mais concisa
     * para criar objetos baseados em especificações.
     *
     * Exemplo:
     * <code>
     * // Forma longa
     * $user = $factory->createObjectSpecifiedBy($spec);
     *
     * // Forma curta (alias)
     * $user = $factory->create($spec);
     * </code>
     *
     * @template T
     * @param ISpecification<T> $specification A especificação que o objeto criado deve satisfazer
     * @return T Uma instância de tipo T especificada pela especificação
     * @throws \InvalidArgumentException Se a especificação for null
     * @throws \RuntimeException Se a fábrica não puder criar um objeto que satisfaça a especificação
     * @see createObjectSpecifiedBy()
     */
    public function create(ISpecification $specification): mixed;
}
