<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * FeatureFlagChange - One Row of the Change History
 *
 * The history is append-only (forward 034, D4): the library writes one row per change, in the
 * transaction of the change, and offers no way to update or delete it. The kind (forward 040, D2)
 * says what changed:
 * - Toggle: the switch (`oldEnabled` → `newEnabled`, `oldEnabled` null when the row of the flag did
 *   not exist yet)
 * - Subject: the subject list (`subjectKey` set: `newEnabled` true = the subject was added, false =
 *   removed; `oldEnabled` is the membership before)
 * - Validity and Rollout: the validity window or the rollout percentage; the switch did not change
 *   (`oldEnabled` and `newEnabled` both hold the state of the flag, `oldEnabled` null when the row was
 *   created by this change) and `detail` says what was set: `2026-11-01T00:00:00+00:00 → open`,
 *   `cleared`, `25% → 50%`, `none → 25%`
 *
 * Without a kind, a row with a subject key is a Subject change and any other a Toggle (the rows of
 * 2.1.0 and 2.2.0).
 *
 * @version    2.3.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class FeatureFlagChange
{
    /** What the row changed. */
    public readonly FeatureFlagChangeKind $kind;

    /**
     * @param string $code Flag code
     * @param bool|null $oldEnabled State before (null = the flag row did not exist)
     * @param bool $newEnabled State after
     * @param string $reason Normalized reason
     * @param string $origin Where the change came from (FeatureFlags::ORIGIN_* or the application's text)
     * @param DateTimeImmutable $changedAt Instant of the change
     * @param string|null $changedBy Who changed it (null = a script without a user)
     * @param string|null $subjectKey Subject added or removed (only for a Subject change)
     * @param FeatureFlagChangeKind|null $kind What changed (null = Subject with a subject key, Toggle otherwise)
     * @param string|null $detail What was set, in words (validity window, rollout percentage)
     * @throws InvalidArgumentException When the kind and the subject key disagree
     */
    public function __construct(
        public readonly string $code,
        public readonly ?bool $oldEnabled,
        public readonly bool $newEnabled,
        public readonly string $reason,
        public readonly string $origin,
        public readonly DateTimeImmutable $changedAt,
        public readonly ?string $changedBy = null,
        public readonly ?string $subjectKey = null,
        ?FeatureFlagChangeKind $kind = null,
        public readonly ?string $detail = null
    ) {
        $kind ??= $subjectKey !== null ? FeatureFlagChangeKind::Subject : FeatureFlagChangeKind::Toggle;
        if (($kind === FeatureFlagChangeKind::Subject) !== ($subjectKey !== null)) {
            throw new InvalidArgumentException(sprintf(
                'A %s change of feature flag "%s" %s a subject key.',
                $kind->value,
                $code,
                $kind === FeatureFlagChangeKind::Subject ? 'needs' : 'cannot carry'
            ));
        }
        $this->kind = $kind;
    }

    /** True when the row records a change of the subject list rather than of the switch. */
    public function isSubjectChange(): bool
    {
        return $this->kind === FeatureFlagChangeKind::Subject;
    }
}
