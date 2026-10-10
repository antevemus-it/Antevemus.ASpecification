<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\InMemory;

use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\Exceptions\UnknownMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\MachineDefinition;
use Antevemus\ASpecification\StateMachine\MachineDefinitionError;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineDefinitionRepository;

/**
 * InMemoryMachineDefinitionRepository - Machine Definitions Registered at Boot
 *
 * Definitions are data shipped with the application (builder code or JSON files, forward 023,
 * D3): the application registers every version that still has live instances (D16). A pair
 * (key, version) is immutable: registering the same definition again is a no-op, registering a
 * different fingerprint under it is refused.
 *
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\InMemory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryMachineDefinitionRepository implements IMachineDefinitionRepository
{
    /** @var array<string, array<int, MachineDefinition>> */
    private array $definitions = [];

    /**
     * @param list<MachineDefinition> $definitions Definitions to register at once
     * @throws InvalidMachineDefinitionException
     */
    public function __construct(array $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }

    /** {@inheritdoc} */
    public function register(MachineDefinition $definition): void
    {
        $existing = $this->definitions[$definition->key][$definition->version] ?? null;
        if ($existing !== null) {
            if ($existing->fingerprint() !== $definition->fingerprint()) {
                throw InvalidMachineDefinitionException::single(
                    MachineDefinitionError::KIND_DUPLICATE,
                    sprintf(
                        'Machine "%s" v%d is already registered with another definition (fingerprint %s): a changed definition needs a new version.',
                        $definition->key,
                        $definition->version,
                        $existing->fingerprint()
                    )
                );
            }

            return;
        }
        $this->definitions[$definition->key][$definition->version] = $definition;
    }

    /** {@inheritdoc} */
    public function get(string $key, int $version): MachineDefinition
    {
        return $this->definitions[$key][$version] ?? throw new UnknownMachineDefinitionException(sprintf(
            'Machine "%s" v%d is not registered.',
            $key,
            $version
        ));
    }

    /** {@inheritdoc} */
    public function has(string $key, int $version): bool
    {
        return isset($this->definitions[$key][$version]);
    }

    /** {@inheritdoc} */
    public function latestVersion(string $key): ?int
    {
        return isset($this->definitions[$key]) ? max(array_keys($this->definitions[$key])) : null;
    }
}
