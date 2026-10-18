<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags;

/**
 * FeatureFlagReason - Why a Flag Evaluated On or Off
 *
 * The reason of a FeatureFlagEvaluation (forward 034, D8), with its documented mapping to the
 * evaluation reasons of OpenFeature (CNCF), so that an OpenFeature provider can be written over
 * FeatureFlags later without changing the library:
 *
 * | Reason           | Value | OpenFeature reason | OpenFeature error code |
 * |------------------|-------|--------------------|------------------------|
 * | ENABLED          | true  | STATIC             |                        |
 * | TARGETING_MATCH  | true  | TARGETING_MATCH    |                        |
 * | DISABLED         | false | DISABLED           |                        |
 * | NOT_IN_TARGET    | false | DEFAULT            |                        |
 * | OUTSIDE_VALIDITY | false | DEFAULT            |                        |
 * | OUTSIDE_ROLLOUT  | false | DEFAULT            |                        |
 * | UNKNOWN_FLAG     | false | ERROR              | FLAG_NOT_FOUND         |
 * | ERROR            | false | ERROR              | GENERAL                |
 *
 * OUTSIDE_ROLLOUT (forward 040, D6, 2.3.0): the subject is in the target but its bucket falls outside
 * the rollout percentage.
 *
 * @version    2.3.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum FeatureFlagReason: string
{
    /** On, without targeting (for every subject). */
    case ENABLED = 'enabled';

    /** On, and the subject is in the target (subject list and/or specification). */
    case TARGETING_MATCH = 'targeting_match';

    /** Off: the switch is off, or the declared flag has no row yet (it is born off). */
    case DISABLED = 'disabled';

    /** The switch is on, but the subject is not in the target (or there is no subject to match). */
    case NOT_IN_TARGET = 'not_in_target';

    /** The switch is on, but the reference instant is outside the validity window. */
    case OUTSIDE_VALIDITY = 'outside_validity';

    /** The switch is on and the subject is in the target, but its bucket is outside the rollout percentage. */
    case OUTSIDE_ROLLOUT = 'outside_rollout';

    /** The code is not declared in the registry: always off. */
    case UNKNOWN_FLAG = 'unknown_flag';

    /** The state could not be read (or the target could not be evaluated): off, and reported. */
    case ERROR = 'error';

    /** True for the reasons whose value is "on". */
    public function isOn(): bool
    {
        return $this === self::ENABLED || $this === self::TARGETING_MATCH;
    }

    /** The OpenFeature evaluation reason (STATIC, TARGETING_MATCH, DISABLED, DEFAULT or ERROR). */
    public function toOpenFeatureReason(): string
    {
        return match ($this) {
            self::ENABLED => 'STATIC',
            self::TARGETING_MATCH => 'TARGETING_MATCH',
            self::DISABLED => 'DISABLED',
            self::NOT_IN_TARGET, self::OUTSIDE_VALIDITY, self::OUTSIDE_ROLLOUT => 'DEFAULT',
            self::UNKNOWN_FLAG, self::ERROR => 'ERROR',
        };
    }

    /** The OpenFeature error code (FLAG_NOT_FOUND or GENERAL), or null when the reason is not an error. */
    public function toOpenFeatureErrorCode(): ?string
    {
        return match ($this) {
            self::UNKNOWN_FLAG => 'FLAG_NOT_FOUND',
            self::ERROR => 'GENERAL',
            default => null,
        };
    }
}
