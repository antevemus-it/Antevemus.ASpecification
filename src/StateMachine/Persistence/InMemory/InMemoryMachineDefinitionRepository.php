<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence\InMemory;

use Antevemus\ASpecification\StateMachine\Calendar\DeadlineCalendarRegistry;
use Antevemus\ASpecification\StateMachine\Exceptions\DefinitionDraftConflictException;
use Antevemus\ASpecification\StateMachine\Exceptions\InvalidMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\Exceptions\UnknownMachineDefinitionException;
use Antevemus\ASpecification\StateMachine\GuardRegistry;
use Antevemus\ASpecification\StateMachine\MachineDefinition;
use Antevemus\ASpecification\StateMachine\MachineDefinitionError;
use Antevemus\ASpecification\StateMachine\Persistence\DefinitionDraftSupport;
use Antevemus\ASpecification\StateMachine\Persistence\DraftInfo;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineDefinitionDrafts;
use Antevemus\ASpecification\StateMachine\Persistence\IMachineDefinitionRepository;
use Antevemus\ASpecification\StateMachine\Persistence\MachineDefinitionDraft;
use Antevemus\ASpecification\StateMachine\Persistence\PublishedMachineDefinition;
use DateTimeImmutable;

/**
 * InMemoryMachineDefinitionRepository - Machine Definitions Registered at Boot
 *
 * Definitions are data shipped with the application (builder code or JSON files, forward 023,
 * D3): the application registers every version that still has live instances (D16). A pair
 * (key, version) is immutable: registering the same definition again is a no-op, registering a
 * different fingerprint under it is refused.
 *
 * Since 2.7.0 it also keeps drafts (IMachineDefinitionDrafts), with the same rules as
 * PdoMachineDefinitionRepository: one draft per key, compare-and-set on its revision, publication of
 * the next version after the validator. What it had before is unchanged.
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence\InMemory
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryMachineDefinitionRepository implements IMachineDefinitionRepository, IMachineDefinitionDrafts
{
    /** @var array<string, array<int, MachineDefinition>> */
    private array $definitions = [];

    /** @var array<string, array{info: DraftInfo, definition: array<string, mixed>}> */
    private array $drafts = [];

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

    /** {@inheritdoc} */
    public function saveDraft(string $machineKey, array $definition, ?int $expectedRevision, ?string $actor, DateTimeImmutable $at): DraftInfo
    {
        DefinitionDraftSupport::assertDraft($machineKey, $definition);
        $current = $this->checkRevision($machineKey, $expectedRevision);
        $info = new DraftInfo(
            $machineKey,
            $current === null ? 1 : $current->revision + 1,
            $current !== null ? $current->basedOnVersion : $this->latestVersion($machineKey),
            $at,
            $actor
        );
        $this->drafts[$machineKey] = ['info' => $info, 'definition' => $definition];

        return $info;
    }

    /** {@inheritdoc} */
    public function draft(string $machineKey, ?GuardRegistry $guards = null, ?array $knownActions = null, ?DeadlineCalendarRegistry $calendars = null): ?MachineDefinitionDraft
    {
        $draft = $this->drafts[$machineKey] ?? null;
        if ($draft === null) {
            return null;
        }

        return new MachineDefinitionDraft(
            $draft['info'],
            $draft['definition'],
            DefinitionDraftSupport::analyze($machineKey, $draft['definition'], $guards ?? new GuardRegistry(), $knownActions, $calendars)
        );
    }

    /** {@inheritdoc} */
    public function draftFrom(string $machineKey, int $version, ?int $expectedRevision, ?string $actor, DateTimeImmutable $at): DraftInfo
    {
        $published = $this->get($machineKey, $version);
        $current = $this->checkRevision($machineKey, $expectedRevision);
        $info = new DraftInfo($machineKey, $current === null ? 1 : $current->revision + 1, $version, $at, $actor);
        $this->drafts[$machineKey] = ['info' => $info, 'definition' => $published->toArray()];

        return $info;
    }

    /** {@inheritdoc} */
    public function discardDraft(string $machineKey, int $expectedRevision): void
    {
        $this->checkRevision($machineKey, $expectedRevision);
        unset($this->drafts[$machineKey]);
    }

    /** {@inheritdoc} */
    public function publish(
        string $machineKey,
        int $expectedRevision,
        GuardRegistry $guards,
        ?array $knownActions,
        string $reason,
        ?string $actor,
        DateTimeImmutable $at,
        ?DeadlineCalendarRegistry $calendars = null
    ): PublishedMachineDefinition {
        $this->checkRevision($machineKey, $expectedRevision);
        [$definition, $warnings] = DefinitionDraftSupport::publishable(
            $machineKey,
            $this->drafts[$machineKey]['definition'],
            ($this->latestVersion($machineKey) ?? 0) + 1,
            $guards,
            $knownActions,
            $reason,
            $calendars
        );
        $this->definitions[$machineKey][$definition->version] = $definition;
        unset($this->drafts[$machineKey]);

        return new PublishedMachineDefinition($definition, $warnings, $reason, $actor, $at);
    }

    /**
     * The current draft, after checking it has the expected revision (null = no draft expected).
     *
     * @throws DefinitionDraftConflictException
     */
    private function checkRevision(string $machineKey, ?int $expectedRevision): ?DraftInfo
    {
        $current = $this->drafts[$machineKey]['info'] ?? null;
        if ($current?->revision !== $expectedRevision) {
            throw new DefinitionDraftConflictException($machineKey, $expectedRevision, $current?->revision);
        }

        return $current;
    }
}
