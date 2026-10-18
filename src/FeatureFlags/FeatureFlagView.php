<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags;

use DateTimeImmutable;

/**
 * FeatureFlagView - One Line of the Flag Listing
 *
 * What FeatureFlags::all() returns for each flag (forward 034, D11): the union of the registry and
 * the store. A declared flag shows the texts of its declaration (the code wins over the row); a flag
 * found only in the store is listed as not declared, with the texts of its row (or its code as the
 * name). The state is the stored switch (a declared flag without a row is off), with the validity
 * window and the rollout percentage (forward 040, D7: null when there is none, when the flag has no
 * row, or when the store does not keep them), the subject list and the last change (who and when, the
 * most recent of the last "on" and the last "off").
 *
 * @version    2.3.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class FeatureFlagView
{
    public readonly string $name;
    public readonly string $description;
    public readonly string $category;
    public readonly string $audience;
    public readonly string $impactOn;
    public readonly string $impactOff;
    public readonly string $origin;
    public readonly bool $declared;
    public readonly bool $stored;
    public readonly bool $enabled;
    public readonly ?DateTimeImmutable $lastChangeAt;
    public readonly ?string $lastChangeBy;

    /** First instant the flag may be on (null = open, or no window). */
    public readonly ?DateTimeImmutable $validFrom;

    /** Last instant the flag may be on (null = open, or no window). */
    public readonly ?DateTimeImmutable $validUntil;

    /** Rollout percentage, 0 to 100 (null = no rollout). */
    public readonly ?int $rolloutPercent;

    /**
     * @param string $code Flag code
     * @param FeatureFlagDefinition|null $definition Declaration (null = not declared)
     * @param FeatureFlagState|null $state Stored state (null = no row)
     */
    public function __construct(
        public readonly string $code,
        public readonly ?FeatureFlagDefinition $definition,
        public readonly ?FeatureFlagState $state
    ) {
        $this->declared = $definition !== null;
        $this->stored = $state !== null;
        $this->enabled = $state !== null && $state->enabled;
        $this->name = $definition?->name ?? (($state?->name ?? '') !== '' ? (string) $state?->name : $code);
        $this->description = $definition?->description ?? ($state?->description ?? '');
        $this->category = $definition?->category ?? ($state?->category ?? '');
        $this->audience = $definition?->audience ?? ($state?->audience ?? '');
        $this->impactOn = $definition?->impactOn ?? '';
        $this->impactOff = $definition?->impactOff ?? '';
        $this->origin = $definition?->origin ?? '';
        [$this->lastChangeAt, $this->lastChangeBy] = $state?->lastChange() ?? [null, null];
        $this->validFrom = $state?->validFrom;
        $this->validUntil = $state?->validUntil;
        $this->rolloutPercent = $state?->rolloutPercent;
    }

    /**
     * Subject keys of the subject list (empty = no list restriction).
     *
     * @return list<string>
     */
    public function subjects(): array
    {
        return $this->state?->subjects ?? [];
    }
}
