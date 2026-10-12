<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Engine;

use Antevemus\ASpecification\Engine\RuleAction;

/**
 * IRuleDefinition - Contract for Abstract Business Rule Definition from Catalog
 *
 * Encapsulates the core attributes of a configurable database specification,
 * including identity, typed parameters, statutory foundations, and violation action guidelines.
 *
 * Features:
 * - Typed access to stable keys and technical validation type
 * - Polymorphic parameter resolution (integer, decimal, string, custom metadata)
 * - Severity and operational violation action identification (RuleAction)
 * - Statutory/legal basis for compliance auditing and contract emission
 * - English vocabulary since 1.7.0; the Portuguese getters of 1.6.x left the contract in 1.7.0 and
 *   their deprecated aliases on RuleDefinition were removed in 2.0.0
 *
 * @version    2.0.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IRuleDefinition
{
    /**
     * Returns the unique and stable rule code.
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Returns the friendly rule display name.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Returns the detailed description of the rule purpose.
     *
     * @return string|null
     */
    public function getDescription(): ?string;

    /**
     * Returns the technical key identifying the validation type (e.g. 'max_events_per_contract').
     *
     * @return string
     */
    public function getRuleType(): string;

    /**
     * Returns the operational action prescribed upon violating the rule.
     *
     * @return RuleAction
     */
    public function getViolationAction(): RuleAction;

    /**
     * Returns the configured integer parameter (e.g. advance notice days, maximum count).
     *
     * @return int|null
     */
    public function getIntValue(): ?int;

    /**
     * Returns the configured decimal parameter (e.g. penalty percentage, minimum monetary threshold).
     *
     * @return float|null
     */
    public function getDecimalValue(): ?float;

    /**
     * Returns the configured string parameter (e.g. expression, secondary code).
     *
     * @return string|null
     */
    public function getTextValue(): ?string;

    /**
     * Returns the corresponding statutory article or regulatory basis (e.g. 'Law 8.245/91 Art. 43, II').
     *
     * @return string|null
     */
    public function getLegalBasis(): ?string;

    /**
     * Returns the human-readable violation message.
     *
     * @return string|null
     */
    public function getViolationMessage(): ?string;

    /**
     * Returns the expression or guard predicate for conditional rule activation.
     *
     * @return string|null
     */
    public function getConditionExpression(): ?string;

    /**
     * Returns the rule execution priority (higher values evaluated first).
     *
     * @return int
     */
    public function getPriority(): int;

    /**
     * Returns the rule application scope (e.g. 'rental_contract', 'claim', 'endorsement').
     *
     * @return string|null
     */
    public function getScope(): ?string;

    /**
     * Returns the applicable business scenario (e.g. 'claim:occupied', 'claim:vacant').
     *
     * @return string|null
     */
    public function getScenario(): ?string;

    /**
     * Returns additional parameters or metadata in associative format.
     *
     * Two kinds of entries live here (RN-17): the applicability restrictions read by the catalog
     * (`product_code`, `plan_code`, `valid_from`, `valid_until`, hydrated from the catalog row by
     * `RuleDefinition::fromArray()`; their Portuguese names of 1.6.x `codigo_produto`, `codigo_plano`,
     * `data_inicio_vigencia`, `data_fim_vigencia` are refused since 2.0.0) and free data for the
     * rule handlers.
     *
     * @return array<string, mixed>
     */
    public function getParameters(): array;

    /**
     * Verifies whether the rule is active for evaluation.
     *
     * @return bool
     */
    public function isActive(): bool;
}
