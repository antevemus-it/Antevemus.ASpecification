<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

/**
 * RuleChangeKind - What a Row of the Rule History Records
 *
 * The `change_kind` column of `business_rule_change` (2.13.0, forward 060, D4):
 * - Baseline: the photo of a version the editor did not write (a rule that existed before the migration, or
 *   whose version was raised outside the editor), recorded before its first change through the editor or by
 *   RuleCatalogEditor::adopt()
 * - Create: version 1 of a rule created through the editor
 * - Update: a change of one or more columns (RuleCatalogEditor::update())
 * - Activate / Deactivate: the `active` column (RuleCatalogEditor::setActive())
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum RuleChangeKind: string
{
    case Baseline = 'baseline';
    case Create = 'create';
    case Update = 'update';
    case Activate = 'activate';
    case Deactivate = 'deactivate';
}
