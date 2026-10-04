<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts;

/**
 * ISpecificationVisitor - Contrato Visitor para travessia de árvores de Especificações
 *
 * Define a interface do padrão Visitor para percorrer uma Árvore de Sintaxe Abstrata (AST)
 * de especificações (ICompositeSpecification e folhas ISpecification), permitindo traduzir
 * ou inspecionar regras de negócio sem acoplar lógica externa às especificações.
 *
 * Funcionalidades:
 * - Travessia de especificações compostas (AND, OR, NOT, NOR) via visitComposite()
 * - Travessia de especificações folha (regras de negócio concretas) via visitLeaf()
 *
 * @template TResult
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISpecificationVisitor
{
    /**
     * Visita uma especificação composta (ex: AndSpecification, OrSpecification, NotSpecification).
     *
     * @param ICompositeSpecification $specification Especificação composta sendo visitada
     * @return TResult Resultado produzido pelo visitor
     */
    public function visitComposite(ICompositeSpecification $specification): mixed;

    /**
     * Visita uma especificação folha (regra de negócio ou critério atômico).
     *
     * @param ISpecification $specification Especificação folha sendo visitada
     * @return TResult Resultado produzido pelo visitor
     */
    public function visitLeaf(ISpecification $specification): mixed;
}
