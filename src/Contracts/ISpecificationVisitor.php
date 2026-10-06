<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts;

/**
 * ISpecificationVisitor - Visitor contract for traversing specification trees.
 *
 * Defines the Visitor pattern interface for traversing an Abstract Syntax Tree (AST)
 * of specifications (ICompositeSpecification and leaf ISpecification instances), allowing
 * inspection, evaluation, or translation of business rules without coupling external logic.
 *
 * Features:
 * - Traversal of composite specifications (AND, OR, NOT, NOR) via visitComposite()
 * - Traversal of leaf specifications (atomic criteria and rules) via visitLeaf()
 *
 * @template TResult
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISpecificationVisitor
{
    /**
     * Visits a composite specification (e.g. AndSpecification, OrSpecification, NotSpecification).
     *
     * @param ICompositeSpecification $specification Composite specification being visited
     * @return TResult Result produced by the visitor
     */
    public function visitComposite(ICompositeSpecification $specification): mixed;

    /**
     * Visits a leaf specification (atomic business rule or criterion).
     *
     * @param ISpecification $specification Leaf specification being visited
     * @return TResult Result produced by the visitor
     */
    public function visitLeaf(ISpecification $specification): mixed;
}
