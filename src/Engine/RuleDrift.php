<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

/**
 * RuleDrift - A Rule Whose Row Does Not Match Its History
 *
 * One answer of RuleCatalogEditor::findDrift() (2.13.0, forward 060, D11): the rule code, how the row departs
 * from the history (RuleDriftKind), the version of the row (null when the row is gone) and the last version of the
 * history (null when there is none). The portable safety net of the audited path: a trigger that forbids writing
 * the table outside the editor is a choice of the application (it is not portable).
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class RuleDrift
{
    /**
     * @param string $code Rule code
     * @param RuleDriftKind $kind How the row departs from the history
     * @param int|null $rowVersion Version of the current row (null = no row)
     * @param int|null $historyVersion Last version of the history (null = no history)
     */
    public function __construct(
        public string $code,
        public RuleDriftKind $kind,
        public ?int $rowVersion,
        public ?int $historyVersion
    ) {
    }
}
