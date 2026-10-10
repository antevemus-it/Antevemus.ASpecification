<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

/**
 * GuardRef - Named Guard Leaf of a Guard Expression
 *
 * References a guard factory of the GuardRegistry by name, with JSON-friendly parameters. The
 * factory turns the parameters into an ISpecification evaluated against a TransitionCandidate
 * (forward 023, D4).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class GuardRef extends GuardExpression
{
    public readonly string $name;

    /** @var array<mixed> */
    public readonly array $params;

    /**
     * @param string $name Guard name registered in the GuardRegistry
     * @param array<mixed> $params JSON-friendly parameters
     */
    public function __construct(string $name, array $params = [])
    {
        $this->name = DefinitionSupport::assertName($name, 'guard name');
        $this->params = DefinitionSupport::assertJsonFriendly($params, "guard '{$name}'");
    }

    /** {@inheritdoc} */
    public function refs(): array
    {
        return [$this];
    }

    /** {@inheritdoc} */
    public function toArray(): array
    {
        return $this->withAnnotations(['guard' => $this->name, 'params' => $this->params]);
    }
}
