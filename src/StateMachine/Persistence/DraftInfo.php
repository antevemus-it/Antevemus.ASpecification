<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine\Persistence;

use DateTimeImmutable;

/**
 * DraftInfo - Revision and Authorship of a Definition Draft (2.7.0)
 *
 * What IMachineDefinitionDrafts answers after a save: the machine key, the revision to send back on
 * the next save, discard or publication (compare-and-set), the published version the draft started
 * from (null when there was none) and who saved it, when (UTC; the instant comes from the caller).
 *
 * @version    2.7.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine\Persistence
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DraftInfo
{
    public readonly DateTimeImmutable $updatedAt;

    /**
     * @param string $machineKey Machine key
     * @param int $revision Revision of the draft (1 at creation, + 1 per save)
     * @param int|null $basedOnVersion Published version the draft started from, null when none
     * @param DateTimeImmutable $updatedAt Instant of the last save (stored in UTC)
     * @param string|null $updatedBy Who saved it last
     */
    public function __construct(
        public readonly string $machineKey,
        public readonly int $revision,
        public readonly ?int $basedOnVersion,
        DateTimeImmutable $updatedAt,
        public readonly ?string $updatedBy
    ) {
        $this->updatedAt = PersistenceSupport::utc($updatedAt);
    }

    /**
     * Returns the JSON-friendly array form.
     *
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'machine_key' => $this->machineKey,
            'revision' => $this->revision,
            'based_on_version' => $this->basedOnVersion,
            'updated_at' => PersistenceSupport::toText($this->updatedAt),
            'updated_by' => $this->updatedBy,
        ];
    }
}
