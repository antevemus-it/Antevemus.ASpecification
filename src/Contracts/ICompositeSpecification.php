<?php

namespace Antevemus\ASpecification\Contracts;

/**
 * ICompositeSpecification interface.
 *
 * Part of the Evans/Fowler Specifications pattern.
 *
 * Interface que representa uma especificação composta, formada pela combinação
 * de múltiplas especificações usando operadores lógicos (AND, OR, NOT, WHERE).
 *
 * Esta interface estende ISpecification e é o tipo de retorno dos métodos de
 * composição (and, or, not, where), permitindo o encadeamento fluente de operações.
 *
 * Uma ICompositeSpecification mantém referências às especificações que a compõem
 * e implementa a lógica de avaliação combinada.
 *
 * @template T
 * @extends ISpecification<T>
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 * @see        https://www.martinfowler.com/apsupp/spec.pdf The Specifications Pattern
 */
interface ICompositeSpecification extends ISpecification
{
    /**
     * Cria uma conjunção de duas especificações com propriedade parametrizada.
     *
     * Combina esta especificação composta com uma especificação parametrizada
     * baseada no nome de uma propriedade acessível e a especificação correspondente.
     *
     * Exemplo:
     * <code>
     * $spec = $userSpec->where('age', $ageSpec)
     *                  ->andWhere('address', new CitySpecification('São Paulo'));
     * </code>
     *
     * @template F
     * @param string $accessibleObjectName Nome da propriedade/método acessível
     * @param ISpecification<F> $accessibleObjectSpecification Especificação para a propriedade
     * @return ICompositeSpecification<T> Nova especificação: esta AND a especificação parametrizada
     * @throws \InvalidArgumentException Se qualquer parâmetro for null
     * @throws \InvalidArgumentException Se o nome da propriedade for inválido
     * @throws \InvalidArgumentException Se os tipos não forem compatíveis
     */
    public function andWhere(string $accessibleObjectName, ISpecification $accessibleObjectSpecification): ICompositeSpecification;

    /**
     * Cria uma disjunção de duas especificações com propriedade parametrizada.
     *
     * Combina esta especificação composta com uma especificação parametrizada
     * baseada no nome de uma propriedade acessível e a especificação correspondente.
     *
     * Exemplo:
     * <code>
     * $spec = $userSpec->where('role', $roleSpec)
     *                  ->orWhere('permissions', new PermissionSpecification('admin'));
     * </code>
     *
     * @template F
     * @param string $accessibleObjectName Nome da propriedade/método acessível
     * @param ISpecification<F> $accessibleObjectSpecification Especificação para a propriedade
     * @return ICompositeSpecification<T> Nova especificação: esta OR a especificação parametrizada
     * @throws \InvalidArgumentException Se qualquer parâmetro for null
     * @throws \InvalidArgumentException Se o nome da propriedade for inválido
     * @throws \InvalidArgumentException Se os tipos não forem compatíveis
     */
    public function orWhere(string $accessibleObjectName, ISpecification $accessibleObjectSpecification): ICompositeSpecification;

    /**
     * Retorna a especificação do lado esquerdo da composição.
     *
     * Em uma operação binária (AND, OR), este método retorna a primeira
     * especificação (operando esquerdo) da composição.
     *
     * @return ISpecification<T>|null A especificação do lado esquerdo, ou null se não aplicável
     */
    public function getLeftSide(): ?ISpecification;

    /**
     * Retorna a especificação do lado direito da composição.
     *
     * Em uma operação binária (AND, OR), este método retorna a segunda
     * especificação (operando direito) da composição.
     *
     * @return ISpecification<T>|null A especificação do lado direito, ou null se não aplicável
     */
    public function getRightSide(): ?ISpecification;

    /**
     * Retorna todas as especificações que compõem esta especificação composta.
     *
     * Este método retorna um array contendo todas as especificações individuais
     * que fazem parte desta composição, útil para análise e debugging.
     *
     * @return array<ISpecification<T>> Array de especificações que compõem esta especificação
     */
    public function getSpecifications(): array;

    /**
     * Especificação parcialmente satisfeita (Partially satisfied specification).
     *
     * Retorna uma especificação composta contendo todos os componentes desta
     * especificação que NÃO foram satisfeitos pelo candidato dado.
     *
     * Se a especificação é completamente satisfeita pelo candidato, retorna null.
     *
     * Este método é útil para:
     * - Validação progressiva
     * - Identificar quais partes de uma especificação complexa falharam
     * - Fornecer feedback detalhado ao usuário
     *
     * Exemplo:
     * <code>
     * $userSpec = $ageSpec->and($emailSpec)->and($termsSpec);
     * $remainder = $userSpec->remainderUnsatisfiedBy($user);
     *
     * if ($remainder !== null) {
     *     echo "Requisitos não satisfeitos: " . $remainder;
     *     // Pode mostrar: "EmailVerifiedSpec AND TermsAcceptedSpec"
     * }
     * </code>
     *
     * @param T $candidate O objeto candidato
     * @return ICompositeSpecification<T>|null Especificação com componentes não satisfeitos, ou null se totalmente satisfeita
     */
    public function remainderUnsatisfiedBy(object $candidate): ?ICompositeSpecification;
}
