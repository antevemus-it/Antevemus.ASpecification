<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\Exceptions\UnknownMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\MachineDefinition;

/**
 * IMachineDefinitionRepository - Port of the Machine Definitions by (Key, Version)
 *
 * An instance is pinned to the version it was born with (forward 023, D16): the runner asks the
 * repository for exactly that (key, version) on every event, so every version that still has live
 * instances must stay registered. A (key, version) pair is immutable: registering a different
 * definition (another fingerprint) under a registered pair is refused (D3).
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IMachineDefinitionRepository
{
    /**
     * Registers a definition under its key and version (idempotent for the same fingerprint).
     *
     * @param MachineDefinition $definition Definition to register
     * @throws InvalidMachineDefinitionException When another definition holds the same key and version
     */
    public function register(MachineDefinition $definition): void;

    /**
     * The definition of the (key, version) pair.
     *
     * @throws UnknownMachineDefinitionException When the pair is not registered
     */
    public function get(string $key, int $version): MachineDefinition;

    /** True when the (key, version) pair is registered. */
    public function has(string $key, int $version): bool;

    /** Highest registered version of the key, or null. */
    public function latestVersion(string $key): ?int;
}
