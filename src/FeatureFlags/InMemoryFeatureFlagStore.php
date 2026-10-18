<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags;

use Antevemus\ASpecification\FeatureFlags\Exceptions\FeatureFlagStoreException;
use Antevemus\ASpecification\Persistence\ITransactionManager;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\IInMemoryTransactional;
use Antevemus\ASpecification\StateMachine\Persistence\InMemory\InMemoryTransactionManager;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * InMemoryFeatureFlagStore - Feature Flags in Plain PHP Arrays
 *
 * The in-memory IFeatureFlagStore (forward 034, D3): tests, prototypes and applications that declare
 * their switches in configuration. Same contract as PdoFeatureFlagStore (RN-02): the same battery runs
 * against both with the same results.
 *
 * - Transactions: an InMemoryTransactionManager whose rollback restores the flags, the subject lists
 *   and the history together (pass your own manager to share it with other in-memory stores)
 * - put() seeds a row as a migration script would (validity window, subject list and rollout
 *   percentage included); the service never writes through it, so it records no history. A row seeded
 *   without a version gets version 1, the default of the reference DDL
 * - Every change of a row increments its version: the switch, the validity window and the rollout
 *   percentage (forward 040, D1 and D4: the window and the rollout are compare-and-set by version)
 * - Without subject lists (`$subjects: false`) it mirrors a PDO store whose subject table is absent;
 *   without a validity window (`$validity: false`) or a rollout (`$rollout: false`), a PDO store whose
 *   columns are absent
 *
 * @version    2.3.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InMemoryFeatureFlagStore implements IFeatureFlagStore, IInMemoryTransactional
{
    /** @var array<string, FeatureFlagState> */
    private array $flags = [];

    /** @var list<FeatureFlagChange> */
    private array $changes = [];

    private readonly InMemoryTransactionManager $transactions;

    /**
     * @param InMemoryTransactionManager|null $transactions Manager to take part in (null = a manager of its own)
     * @param bool $subjects Whether the store keeps subject lists
     * @param bool $validity Whether the store keeps the validity window
     * @param bool $rollout Whether the store keeps the rollout percentage
     */
    public function __construct(
        ?InMemoryTransactionManager $transactions = null,
        private readonly bool $subjects = true,
        private readonly bool $validity = true,
        private readonly bool $rollout = true
    ) {
        $this->transactions = ($transactions ?? new InMemoryTransactionManager())->addParticipant($this);
    }

    /**
     * Seeds (or replaces) the row of a flag, as a script would: no history row is written.
     *
     * @return $this
     * @throws InvalidArgumentException When the code is not valid, or a subject list, a validity window or a
     *                                  rollout percentage is given to a store that does not keep it
     */
    public function put(FeatureFlagState $state): self
    {
        FeatureFlagDefinition::assertCode($state->code);
        if (!$this->subjects && $state->subjects !== []) {
            throw new InvalidArgumentException('This in-memory store keeps no subject lists.');
        }
        if (!$this->validity && ($state->validFrom !== null || $state->validUntil !== null)) {
            throw new InvalidArgumentException('This in-memory store keeps no validity window.');
        }
        if (!$this->rollout && $state->rolloutPercent !== null) {
            throw new InvalidArgumentException('This in-memory store keeps no rollout percentage.');
        }
        $this->flags[$state->code] = $state->version === null ? $this->copy($state, ['version' => 1]) : $state;

        return $this;
    }

    /** {@inheritdoc} */
    public function loadAll(): array
    {
        $flags = $this->flags;
        ksort($flags, SORT_STRING);

        return $flags;
    }

    /** {@inheritdoc} */
    public function find(string $code): ?FeatureFlagState
    {
        return $this->flags[$code] ?? null;
    }

    /** {@inheritdoc} */
    public function insertIfAbsent(FeatureFlagDefinition $definition, DateTimeImmutable $at, string|int|null $actor): bool
    {
        if (isset($this->flags[$definition->code])) {
            return false;
        }
        $this->flags[$definition->code] = new FeatureFlagState(
            $definition->code,
            false,
            $definition->name,
            $definition->description !== '' ? $definition->description : null,
            $definition->category !== '' ? $definition->category : null,
            $definition->audience !== '' ? $definition->audience : null,
            version: 1
        );

        return true;
    }

    /** {@inheritdoc} */
    public function compareAndSetEnabled(string $code, bool $expected, bool $enabled, DateTimeImmutable $at, string|int|null $actor): bool
    {
        $current = $this->flags[$code] ?? null;
        if ($current === null || $current->enabled !== $expected) {
            return false;
        }
        $by = $actor === null ? null : (string) $actor;
        $this->flags[$code] = $this->copy($current, [
            'enabled' => $enabled,
            'enabledAt' => $enabled ? $at : $current->enabledAt,
            'enabledBy' => $enabled ? $by : $current->enabledBy,
            'disabledAt' => $enabled ? $current->disabledAt : $at,
            'disabledBy' => $enabled ? $current->disabledBy : $by,
            'version' => $current->version === null ? null : $current->version + 1,
        ]);

        return true;
    }

    /** {@inheritdoc} */
    public function supportsValidity(): bool
    {
        return $this->validity;
    }

    /** {@inheritdoc} */
    public function compareAndSetValidity(string $code, int $expectedVersion, ?DateTimeImmutable $from, ?DateTimeImmutable $until, DateTimeImmutable $at, string|int|null $actor): bool
    {
        if (!$this->validity) {
            throw new FeatureFlagStoreException('This in-memory store keeps no validity window.');
        }

        return $this->compareAndSetVersion($code, $expectedVersion, ['validFrom' => $from, 'validUntil' => $until]);
    }

    /** {@inheritdoc} */
    public function supportsRollout(): bool
    {
        return $this->rollout;
    }

    /** {@inheritdoc} */
    public function compareAndSetRollout(string $code, int $expectedVersion, ?int $percent, DateTimeImmutable $at, string|int|null $actor): bool
    {
        if (!$this->rollout) {
            throw new FeatureFlagStoreException('This in-memory store keeps no rollout percentage.');
        }

        return $this->compareAndSetVersion($code, $expectedVersion, ['rolloutPercent' => $percent]);
    }

    /** {@inheritdoc} */
    public function supportsSubjects(): bool
    {
        return $this->subjects;
    }

    /** {@inheritdoc} */
    public function addSubject(string $code, string $subjectKey): bool
    {
        $current = $this->subjectFlag($code);
        if ($current->hasSubject($subjectKey)) {
            return false;
        }
        $this->flags[$code] = $this->copy($current, ['subjects' => [...$current->subjects, $subjectKey]]);

        return true;
    }

    /** {@inheritdoc} */
    public function removeSubject(string $code, string $subjectKey): bool
    {
        $current = $this->subjects ? ($this->flags[$code] ?? null) : $this->subjectFlag($code);
        if ($current === null || !$current->hasSubject($subjectKey)) {
            return false;
        }
        $this->flags[$code] = $this->copy($current, [
            'subjects' => array_values(array_filter($current->subjects, static fn(string $key): bool => $key !== $subjectKey)),
        ]);

        return true;
    }

    /** {@inheritdoc} */
    public function appendChange(FeatureFlagChange $change): void
    {
        if ($change->subjectKey !== null && !$this->subjects) {
            throw new FeatureFlagStoreException('This in-memory store keeps no subject lists.');
        }
        $this->changes[] = $change;
    }

    /** {@inheritdoc} */
    public function history(?string $code = null, int $limit = 50): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('The history limit must be at least 1, %d given.', $limit));
        }
        $rows = [];
        for ($i = count($this->changes) - 1; $i >= 0 && count($rows) < $limit; $i--) {
            if ($code === null || $this->changes[$i]->code === $code) {
                $rows[] = $this->changes[$i];
            }
        }

        return $rows;
    }

    /** {@inheritdoc} */
    public function getTransactionManager(): ITransactionManager
    {
        return $this->transactions;
    }

    /** {@inheritdoc} */
    public function exportState(): array
    {
        return ['flags' => $this->flags, 'changes' => $this->changes];
    }

    /** {@inheritdoc} */
    public function importState(array $state): void
    {
        $this->flags = $state['flags'];
        $this->changes = $state['changes'];
    }

    /**
     * The row of a flag whose subject list is about to change.
     *
     * @throws FeatureFlagStoreException When the store keeps no lists or the flag has no row
     */
    private function subjectFlag(string $code): FeatureFlagState
    {
        if (!$this->subjects) {
            throw new FeatureFlagStoreException('This in-memory store keeps no subject lists.');
        }
        $current = $this->flags[$code] ?? null;
        if ($current === null) {
            throw new FeatureFlagStoreException(sprintf('Feature flag "%s" has no row: a subject needs the row of its flag.', $code));
        }

        return $current;
    }

    /**
     * Replaces some fields of a row and increments its version, only when the stored version is the
     * expected one.
     *
     * @param array<string, mixed> $with Field => new value
     */
    private function compareAndSetVersion(string $code, int $expectedVersion, array $with): bool
    {
        $current = $this->flags[$code] ?? null;
        if ($current === null || $current->version !== $expectedVersion) {
            return false;
        }
        $this->flags[$code] = $this->copy($current, $with + ['version' => $expectedVersion + 1]);

        return true;
    }

    /**
     * A copy of a state with some fields replaced.
     *
     * @param array<string, mixed> $with Field => new value
     */
    private function copy(FeatureFlagState $state, array $with): FeatureFlagState
    {
        $fields = [
            'code' => $state->code,
            'enabled' => $state->enabled,
            'name' => $state->name,
            'description' => $state->description,
            'category' => $state->category,
            'audience' => $state->audience,
            'notes' => $state->notes,
            'validFrom' => $state->validFrom,
            'validUntil' => $state->validUntil,
            'enabledAt' => $state->enabledAt,
            'enabledBy' => $state->enabledBy,
            'disabledAt' => $state->disabledAt,
            'disabledBy' => $state->disabledBy,
            'version' => $state->version,
            'subjects' => $state->subjects,
            'rolloutPercent' => $state->rolloutPercent,
        ];

        return new FeatureFlagState(...array_replace($fields, $with));
    }
}
