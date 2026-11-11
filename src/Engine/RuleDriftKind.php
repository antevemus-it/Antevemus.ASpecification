<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

/**
 * RuleDriftKind - How a Rule Row Departs From Its History
 *
 * Found by RuleCatalogEditor::findDrift() (2.13.0, forward 060, D11), comparing the current row with the photo
 * of the last version of the history:
 * - NoHistory: the rule has no history row (it existed before the migration and was never changed nor adopted
 *   through the editor); adopt() or its first change records a baseline
 * - VersionAhead: the version of the row is above the last version of the history (it was raised outside the
 *   editor); adopt() or the next change records a baseline of the current version
 * - Modified: the row is not the photo of the last version (an UPDATE by hand kept or lowered the version); the
 *   editor refuses to change such a rule (RuleCatalogDriftException) until its version is raised by hand
 * - Deleted: the history has the rule, the table does not (a DELETE by hand)
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum RuleDriftKind: string
{
    case NoHistory = 'no_history';
    case VersionAhead = 'version_ahead';
    case Modified = 'modified';
    case Deleted = 'deleted';

    /** True when a baseline of the current version settles it (adopt() or the next change records one). */
    public function needsBaseline(): bool
    {
        return $this === self::NoHistory || $this === self::VersionAhead;
    }
}
