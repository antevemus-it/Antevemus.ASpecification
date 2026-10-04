<?php

namespace Antevemus\ASpecification\Contracts;

/**
 * ILeafSpecification interface.
 *
 * Part of the Evans/Fowler Specifications pattern.
 *
 * Interface que marca uma especificação como uma "folha" (leaf) na árvore de
 * especificações compostas. Especificações folha são especificações atômicas,
 * não compostas, que representam regras de negócio individuais e indivisíveis.
 *
 * Em contraste com ICompositeSpecification (que combina múltiplas especificações),
 * uma ILeafSpecification é uma especificação simples que não pode ser decomposta
 * em especificações menores.
 *
 * Exemplos de especificações folha:
 * - AgeGreaterThanSpecification
 * - EmailVerifiedSpecification
 * - PriceInRangeSpecification
 * - StatusEqualsSpecification
 *
 * Esta interface é um marcador (marker interface) que não adiciona métodos
 * adicionais, servindo apenas para distinguir especificações folha de
 * especificações compostas na hierarquia de tipos.
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
interface ILeafSpecification extends ISpecification
{
    // Marker interface - sem métodos adicionais
    // As especificações folha implementam apenas os métodos de ISpecification
}
