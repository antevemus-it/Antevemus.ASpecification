<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Engine;

/**
 * IRuleCatalog - Repository / Provider for Rule and Document Definitions
 *
 * Abstracts access to the underlying persistence source (PostgreSQL, cache, JSON files)
 * where the business rule catalog and document groups are stored.
 *
 * Features:
 * - Filtered retrieval of active business rules by scope and scenario
 * - Filtered retrieval of document requirement groups by scope and scenario
 * - Applicability filters with a written contract: product_code, plan_code, reference_date (RN-17)
 * - English vocabulary since 1.7.0; the Portuguese filter and restriction keys of 1.6.x are read as legacy
 *
 * @version    1.7.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IRuleCatalog
{
    /**
     * Retrieves the collection of active business rules for the given scope and scenario.
     *
     * Contract for `$scenario`: a rule without scenario applies to the whole scope; a rule bound to a
     * scenario applies only when that exact scenario is requested. Passing `null` means
     * "scope-global rules only": it must never return the rules of every scenario at once.
     *
     * Contract for `$filters` (RN-17, BUG-20261007-ZB6A): it is the validation context handed over by
     * the engine. The catalog interprets ONLY the reserved keys `product_code` (string),
     * `plan_code` (string) and `reference_date` (DateTimeInterface or date string); every other
     * key is ignored by the catalog (it belongs to the handlers and to the document evaluator).
     * The Portuguese keys of 1.6.x (`codigo_produto`, `codigo_plano`, `data_referencia`) are read as
     * legacy when the English key is absent.
     * Semantics are AND, mirroring scope and scenario: a rule whose `product_code` restriction is
     * null applies to every product; a rule restricted to a product applies only when the filter
     * brings the same value, so a missing filter means "no product" and restricted rules do not apply
     * (same for `plan_code`). A rule applies to `reference_date` when
     * `valid_from <= reference_date` and `valid_until` is null or `>= reference_date`
     * (inclusive, by day); without `reference_date` the validity window is not evaluated.
     * The restrictions are read from `IRuleDefinition::getParameters()` under the keys
     * `product_code`, `plan_code`, `valid_from`, `valid_until` (legacy `codigo_produto`,
     * `codigo_plano`, `data_inicio_vigencia`, `data_fim_vigencia`). Every implementation honours the
     * same keys with the same semantics.
     *
     * @param string $scope Operational scope (e.g. 'rental_contract', 'claim')
     * @param string|null $scenario Specific scenario (e.g. 'claim:occupied'); null = scope-global rules only
     * @param array<string, mixed> $filters Validation context; reserved keys product_code, plan_code, reference_date
     * @return list<IRuleDefinition>
     */
    public function findRules(string $scope, ?string $scenario = null, array $filters = []): array;

    /**
     * Retrieves the collection of document requirements for the given scope and scenario.
     *
     * Documents must never leak between scopes: a requirement belongs to exactly one scope
     * (`getScope()`) and to one scenario (`getScenario()`) or to the whole scope. Matching is exact
     * on those two values; the group code is an identifier and never takes part in matching.
     * `null` scenario returns scope-global documents only.
     *
     * @param string $scope Operational scope (e.g. 'rental_contract', 'claim')
     * @param string|null $scenario Specific scenario (e.g. 'claim:occupied'); null = scope-global documents only
     * @return list<IDocumentRuleDefinition>
     */
    public function findDocumentRules(string $scope, ?string $scenario = null): array;
}
