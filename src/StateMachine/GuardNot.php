<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

/**
 * GuardNot - Negation Node of a Guard Expression (`not`)
 *
 * Passes when the operand fails. Compiled into a NotSpecification: an evaluation error of the
 * operand is never inverted into a pass.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class GuardNot extends GuardExpression
{
    /**
     * @param GuardExpression $operand Negated expression
     */
    public function __construct(public readonly GuardExpression $operand)
    {
    }

    /** {@inheritdoc} */
    public function refs(): array
    {
        return $this->operand->refs();
    }

    /** {@inheritdoc} */
    public function toArray(): array
    {
        return $this->withAnnotations(['not' => $this->operand->toArray()]);
    }
}
