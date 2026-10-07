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
 * - Applicability filters with a written contract: codigo_produto, codigo_plano, data_referencia (RN-17)
 *
 * @version    1.4.0
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
     * Contract for `$cenario`: a rule without scenario applies to the whole scope; a rule bound to a
     * scenario applies only when that exact scenario is requested. Passing `null` means
     * "scope-global rules only": it must never return the rules of every scenario at once.
     *
     * Contract for `$filters` (RN-17, BUG-20261007-ZB6A): it is the validation context handed over by
     * the engine. The catalog interprets ONLY the reserved keys `codigo_produto` (string),
     * `codigo_plano` (string) and `data_referencia` (DateTimeInterface or date string); every other
     * key is ignored by the catalog (it belongs to the handlers and to the document evaluator).
     * Semantics are AND, mirroring scope and scenario: a rule whose `codigo_produto` restriction is
     * null applies to every product; a rule restricted to a product applies only when the filter
     * brings the same value, so a missing filter means "no product" and restricted rules do not apply
     * (same for `codigo_plano`). A rule applies to `data_referencia` when
     * `data_inicio_vigencia <= data_referencia` and `data_fim_vigencia` is null or `>= data_referencia`
     * (inclusive, by day); without `data_referencia` the validity window is not evaluated.
     * The restrictions are read from `IRuleDefinition::getParametros()` under the keys
     * `codigo_produto`, `codigo_plano`, `data_inicio_vigencia`, `data_fim_vigencia`. Every
     * implementation honours the same keys with the same semantics.
     *
     * @param string $escopo Operational scope (e.g. 'rental_contract', 'claim')
     * @param string|null $cenario Specific scenario (e.g. 'claim:occupied'); null = scope-global rules only
     * @param array<string, mixed> $filters Validation context; reserved keys codigo_produto, codigo_plano, data_referencia
     * @return list<IRuleDefinition>
     */
    public function findRules(string $escopo, ?string $cenario = null, array $filters = []): array;

    /**
     * Retrieves the collection of document requirements for the given scope and scenario.
     *
     * Documents must never leak between scopes: a requirement belongs to exactly one scope
     * (`getEscopo()`) and to one scenario (`getCenario()`) or to the whole scope. Matching is exact
     * on those two values; the group code is an identifier and never takes part in matching.
     * `null` scenario returns scope-global documents only.
     *
     * @param string $escopo Operational scope (e.g. 'rental_contract', 'claim')
     * @param string|null $cenario Specific scenario (e.g. 'claim:occupied'); null = scope-global documents only
     * @return list<IDocumentRuleDefinition>
     */
    public function findDocumentRules(string $escopo, ?string $cenario = null): array;
}
