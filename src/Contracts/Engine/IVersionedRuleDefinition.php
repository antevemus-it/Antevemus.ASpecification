<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Engine;

/**
 * IVersionedRuleDefinition - A Catalog Rule That Knows Its Version
 *
 * The version of a rule of the catalog (2.13.0, forward 060, D9): the `version` column of `business_rule`,
 * incremented by RuleCatalogEditor on every change, and the number under which the history keeps the photo of
 * the rule (RuleRevision). A rule read with its version can be cited: "rule R, version 3" is one row of the
 * history.
 *
 * A separate interface, so that IRuleDefinition does not change and a class implementing it directly keeps
 * working untouched. RuleDefinition implements it; the version is null when the catalog does not read the column
 * (PdoRuleCatalog without `COLUMNS_SINCE_2_13`, or a rule built in code without one).
 *
 * @version    2.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IVersionedRuleDefinition extends IRuleDefinition
{
    /**
     * The version of the rule in the catalog, or null when it was not read.
     *
     * @return int|null
     */
    public function getVersion(): ?int;
}
