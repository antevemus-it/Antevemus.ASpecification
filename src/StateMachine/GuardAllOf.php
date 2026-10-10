<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;

/**
 * GuardAllOf - Conjunction Node of a Guard Expression (`and`)
 *
 * Passes when every operand passes. Compiled into a chain of AndSpecification, so the failures of
 * every failing operand are reported (or a single annotated failure when the node declares a
 * message or code).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class GuardAllOf extends GuardExpression
{
    /** @var list<GuardExpression> */
    public readonly array $operands;

    /**
     * @param list<GuardExpression> $operands At least one operand
     * @throws InvalidMachineDefinitionException
     */
    public function __construct(array $operands)
    {
        if ($operands === []) {
            throw InvalidMachineDefinitionException::single(
                MachineDefinitionError::KIND_MALFORMED,
                'A guard "and" node needs at least one operand.'
            );
        }
        $this->operands = array_values($operands);
    }

    /** {@inheritdoc} */
    public function refs(): array
    {
        return array_merge(...array_map(static fn(GuardExpression $e): array => $e->refs(), $this->operands));
    }

    /** {@inheritdoc} */
    public function toArray(): array
    {
        return $this->withAnnotations([
            'and' => array_map(static fn(GuardExpression $e): array => $e->toArray(), $this->operands),
        ]);
    }
}
