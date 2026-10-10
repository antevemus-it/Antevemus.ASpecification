<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;

/**
 * MachineBuilder - Fluent Builder of a MachineDefinition
 *
 * Entry point of the fluent API (Spec::machine($key, $version)). Builders are mutable; build()
 * produces the immutable definition. Every nested builder (region, state, transition) can
 * continue the chain at any level (`->state(...)`, `->region(...)`, `->build()`) or go up with
 * `->end()`. Transitions without explicit id receive a deterministic one (`region.state.EVENT`).
 *
 * <code>
 * $definition = Spec::machine('document', 1)
 *     ->region('lifecycle', 'pending')
 *         ->state('pending')
 *             ->on('DELIVER', 'delivered')->guard('payloadHas', ['field' => 'file'])
 *         ->state('delivered')->deadline('P30D', 'EXPIRE')
 *             ->on('VALIDATE', 'validated')
 *             ->on('EXPIRE', 'expired')
 *         ->finalState('validated')
 *         ->finalState('expired')
 *     ->build();
 * </code>
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class MachineBuilder
{
    /** @var list<RegionBuilder> */
    private array $regions = [];

    /**
     * @param string $key Stable machine key
     * @param int $version Definition version (>= 1)
     */
    public function __construct(
        private readonly string $key,
        private readonly int $version
    ) {
    }

    /**
     * Starts a new region.
     *
     * @param string $name Region name
     * @param string|null $initial Initial state (may also be set with RegionBuilder::initial())
     * @return RegionBuilder
     */
    public function region(string $name, ?string $initial = null): RegionBuilder
    {
        $region = new RegionBuilder($this, $name, $initial);
        $this->regions[] = $region;
        return $region;
    }

    /**
     * Builds the immutable definition.
     *
     * @return MachineDefinition
     * @throws InvalidMachineDefinitionException On a broken structural invariant
     */
    public function build(): MachineDefinition
    {
        $taken = [];
        foreach ($this->regions as $region) {
            foreach ($region->explicitIds() as $id) {
                $taken[$id] = true;
            }
        }

        return new MachineDefinition(
            $this->key,
            $this->version,
            array_map(static fn(RegionBuilder $r): RegionDefinition => $r->toDefinition($taken), $this->regions)
        );
    }
}
