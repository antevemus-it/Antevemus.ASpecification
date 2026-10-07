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
 *
 * @version    1.4.0
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
    public function getCodigo(): string;

    /**
     * Returns the friendly rule display name.
     *
     * @return string
     */
    public function getNome(): string;

    /**
     * Returns the detailed description of the rule purpose.
     *
     * @return string|null
     */
    public function getDescricao(): ?string;

    /**
     * Returns the technical key identifying the validation type (e.g. 'max_events_per_contract').
     *
     * @return string
     */
    public function getTipoRegra(): string;

    /**
     * Returns the operational action prescribed upon violating the rule.
     *
     * @return RuleAction
     */
    public function getAcaoAoViolar(): RuleAction;

    /**
     * Returns the configured integer parameter (e.g. advance notice days, maximum count).
     *
     * @return int|null
     */
    public function getValorInteiro(): ?int;

    /**
     * Returns the configured decimal parameter (e.g. penalty percentage, minimum monetary threshold).
     *
     * @return float|null
     */
    public function getValorDecimal(): ?float;

    /**
     * Returns the configured string parameter (e.g. expression, secondary code).
     *
     * @return string|null
     */
    public function getValorTexto(): ?string;

    /**
     * Returns the corresponding statutory article or regulatory basis (e.g. 'Law 8.245/91 Art. 43, II').
     *
     * @return string|null
     */
    public function getFundamentoLegal(): ?string;

    /**
     * Returns the human-readable violation message.
     *
     * @return string|null
     */
    public function getMensagemViolacao(): ?string;

    /**
     * Returns the expression or guard predicate for conditional rule activation.
     *
     * @return string|null
     */
    public function getCondicionalExpressao(): ?string;

    /**
     * Returns the rule execution priority (higher values evaluated first).
     *
     * @return int
     */
    public function getPrioridade(): int;

    /**
     * Returns the rule application scope (e.g. 'rental_contract', 'claim', 'endorsement').
     *
     * @return string|null
     */
    public function getEscopo(): ?string;

    /**
     * Returns the applicable business scenario (e.g. 'claim:occupied', 'claim:vacant').
     *
     * @return string|null
     */
    public function getCenario(): ?string;

    /**
     * Returns additional parameters or metadata in associative format.
     *
     * Two kinds of entries live here (RN-17): the applicability restrictions read by the catalog
     * (`codigo_produto`, `codigo_plano`, `data_inicio_vigencia`, `data_fim_vigencia`, hydrated from
     * the catalog row by `RuleDefinition::fromArray()`) and free data for the rule handlers.
     *
     * @return array<string, mixed>
     */
    public function getParametros(): array;

    /**
     * Verifies whether the rule is active for evaluation.
     *
     * @return bool
     */
    public function isActive(): bool;
}
