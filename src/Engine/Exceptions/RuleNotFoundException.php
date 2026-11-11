<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine\Exceptions;

/**
 * RuleNotFoundException - The Rule to Change Is Not in the Catalog
 *
 * Thrown by RuleCatalogEditor::update(), setActive() and adopt() (2.13.0, forward 060) when the rule table has no
 * row with the code, active or not. The audited path never deletes a rule (D1): a rule leaves use through
 * setActive(false) and its history stays.
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class RuleNotFoundException extends RuleCatalogEditorException
{
}
