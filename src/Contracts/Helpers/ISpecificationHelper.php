<?php

namespace Antevemus\ASpecification\Contracts\Helpers;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * ISpecificationHelper interface.
 *
 * Interface que define métodos auxiliares para trabalhar com especificações.
 *
 * Esta interface fornece métodos auxiliares para operações comuns com especificações,
 * incluindo verificação type-safe e criação de especificações únicas.
 *
 * Principais funcionalidades:
 * - Verificação type-safe de especificações em tempo de execução
 * - Criação de especificações únicas para entidades
 *
 * Exemplo de uso:
 *
 * <code>
 * // Verificação type-safe
 * $spec = new ActiveUserSpecification();
 * $user = new User();
 * $helper = new SpecificationHelper();
 *
 * if ($helper->typeSafeIsSatisfiedBy($spec, $user)) {
 *     echo "Usuário ativo!";
 * }
 *
 * // Criação de especificação única para entidade
 * $uniqueSpec = $helper->createUniqueSpecificationFor($user);
 * // Resultado: (id = 123)
 * </code>
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Helpers
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISpecificationHelper
{
    /**
     * Verifica se uma especificação é satisfeita por um candidato de forma type-safe.
     *
     * Este método realiza verificação dinâmica de tipos em tempo de execução,
     * garantindo que o candidato seja do tipo esperado pela especificação antes
     * de executar a verificação.
     *
     * Diferente do método padrão isSatisfiedBy() que pode falhar silenciosamente
     * ou lançar erros de tipo, este método garante compatibilidade de tipos antes
     * da verificação.
     *
     * Comportamento:
     * - Verifica se o candidato é do tipo esperado pela especificação (via getType())
     * - Se os tipos são compatíveis, executa isSatisfiedBy()
     * - Se os tipos são incompatíveis, retorna false
     * - Se o candidato é null, retorna false
     *
     * Exemplo:
     * <code>
     * $userSpec = new ActiveUserSpecification(); // Espera User
     * $product = new Product();
     * $user = new User();
     *
     * // Retorna false - tipos incompatíveis (Product vs User)
     * $helper->typeSafeIsSatisfiedBy($userSpec, $product);
     *
     * // Executa verificação - tipos compatíveis
     * $helper->typeSafeIsSatisfiedBy($userSpec, $user);
     * </code>
     *
     * @template T
     * @param ISpecification<T> $specification A especificação a verificar
     * @param object|null $candidate O candidato a testar
     * @return bool True se o candidato é do tipo correto E satisfaz a especificação; false caso contrário
     * @throws \InvalidArgumentException Se a especificação for null
     */
    public function typeSafeIsSatisfiedBy(ISpecification $specification, ?object $candidate): bool;

    /**
     * Cria uma especificação única para identificar uma entidade específica.
     *
     * Este método gera uma especificação que identifica unicamente uma entidade
     * baseada em suas propriedades de identidade (ID, chave primária, etc.).
     *
     * A especificação resultante pode ser usada para:
     * - Buscar a entidade em repositórios
     * - Verificar se um objeto representa a mesma entidade
     * - Criar queries de lookup
     * - Implementar equals() baseado em identidade
     *
     * Para entidades com ID simples:
     * - Retorna especificação do tipo: (id = <valor>)
     *
     * Para entidades com chave composta:
     * - Retorna especificação composta: (prop1 = valor1 AND prop2 = valor2 AND ...)
     *
     * Exemplo:
     * <code>
     * $user = new User(id: 123, name: 'João');
     * $spec = $helper->createUniqueSpecificationFor($user);
     * // Resultado: (id = 123)
     *
     * // Usar para buscar no repositório
     * $foundUser = $repository->findBySpecification($spec);
     * assert($foundUser->getId() === $user->getId());
     *
     * // Entidade com chave composta
     * $orderItem = new OrderItem(orderId: 1, productId: 5);
     * $spec = $helper->createUniqueSpecificationFor($orderItem);
     * // Resultado: (orderId = 1 AND productId = 5)
     * </code>
     *
     * @template T
     * @param T $entity A entidade para criar especificação única
     * @return ISpecification<T> Especificação que identifica unicamente a entidade
     * @throws \InvalidArgumentException Se a entidade for null
     * @throws \RuntimeException Se a entidade não possui propriedades de identidade identificáveis
     */
    public function createUniqueSpecificationFor(object $entity): ISpecification;
}
