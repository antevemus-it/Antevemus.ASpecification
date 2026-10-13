<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags;

use DateTimeImmutable;

/**
 * FeatureFlagChange - One Row of the Change History
 *
 * The history is append-only (forward 034, D4): the library writes one row per change, in the
 * transaction of the change, and offers no way to update or delete it. A row records either the
 * switch of the flag (`subjectKey` null: `oldEnabled` → `newEnabled`, `oldEnabled` null when the row
 * of the flag did not exist yet) or a change of its subject list (`subjectKey` set: `newEnabled` true
 * = the subject was added, false = removed; `oldEnabled` is the membership before).
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class FeatureFlagChange
{
    /**
     * @param string $code Flag code
     * @param bool|null $oldEnabled State before (null = the flag row did not exist)
     * @param bool $newEnabled State after
     * @param string $reason Normalized reason
     * @param string $origin Where the change came from (FeatureFlags::ORIGIN_* or the application's text)
     * @param DateTimeImmutable $changedAt Instant of the change
     * @param string|null $changedBy Who changed it (null = a script without a user)
     * @param string|null $subjectKey Subject added or removed (null = the switch itself)
     */
    public function __construct(
        public readonly string $code,
        public readonly ?bool $oldEnabled,
        public readonly bool $newEnabled,
        public readonly string $reason,
        public readonly string $origin,
        public readonly DateTimeImmutable $changedAt,
        public readonly ?string $changedBy = null,
        public readonly ?string $subjectKey = null
    ) {
    }

    /** True when the row records a change of the subject list rather than of the switch. */
    public function isSubjectChange(): bool
    {
        return $this->subjectKey !== null;
    }
}
