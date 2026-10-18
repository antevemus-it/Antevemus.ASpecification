<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags;

/**
 * FeatureFlagChangeKind - What a History Row Changed
 *
 * The kind of a FeatureFlagChange (forward 040, D2): the switch (`Toggle`), the subject list
 * (`Subject`), the validity window (`Validity`) or the rollout percentage (`Rollout`). Stored in
 * `feature_flag_change.change_kind` as the lowercase value of the case; a row written before 2.3.0
 * (no column, or the column default) reads as `Toggle`, or `Subject` when it carries a subject key.
 *
 * @version    2.3.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum FeatureFlagChangeKind: string
{
    /** The switch was turned on or off. */
    case Toggle = 'toggle';

    /** A subject was added to or removed from the subject list. */
    case Subject = 'subject';

    /** The validity window was set or cleared. */
    case Validity = 'validity';

    /** The rollout percentage was set or cleared. */
    case Rollout = 'rollout';
}
