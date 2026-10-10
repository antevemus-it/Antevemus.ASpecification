<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Engine;

use Antevemus\ASpecification\Engine\DocumentRequirementMode;

/**
 * IDocumentRuleDefinition - Contract for Document Requirement in Operational Group
 *
 * Defines the requirement parameters of a document type in a domain operation,
 * establishing the applicable Boolean rule (all, any, one_of_set) and alternative sets.
 *
 * Features:
 * - Linkage to technical document type code
 * - Operational scope (mandatory) and scenario (optional) the requirement belongs to;
 *   catalogs match documents exactly on them, never on the group code
 * - Resolution of Boolean requirement semantics (all, any, one_of_set)
 * - Grouping by alternative sets (e.g., 'identity'), mandatory for any / one_of_set
 * - Conditional expression for requirement activation
 * - English vocabulary since 1.7.0; the Portuguese getters of 1.6.x left the contract and survive
 *   only as deprecated aliases on DocumentRuleDefinition (removed in 2.0.0)
 *
 * @version    1.7.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IDocumentRuleDefinition
{
    /**
     * Returns the code of the requirement group to which this document belongs.
     * The group code is an identifier only: it never encodes the scope or the scenario.
     *
     * @return string
     */
    public function getGroupCode(): string;

    /**
     * Returns the operational scope this document requirement belongs to (e.g. 'contrato_locacao',
     * 'sinistro'). Never empty: a document requirement always belongs to exactly one scope.
     *
     * @return string
     */
    public function getScope(): string;

    /**
     * Returns the scenario this document requirement belongs to (e.g. 'sinistro:ocupado').
     * Null means the requirement applies to the whole scope regardless of scenario.
     *
     * @return string|null
     */
    public function getScenario(): ?string;

    /**
     * Returns the technical code of the required document type (e.g. 'cnpj_imobiliaria', 'cnh_locatario').
     *
     * @return string
     */
    public function getDocumentTypeCode(): string;

    /**
     * Returns the document requirement mode (ALL, ANY, ONE_OF_SET).
     *
     * @return DocumentRequirementMode
     */
    public function getRequirementMode(): DocumentRequirementMode;

    /**
     * Returns the alternative set identifier for ANY or ONE_OF_SET rules. Null is only valid
     * for ALL rules: the compiler refuses ANY / ONE_OF_SET requirements without a set.
     *
     * @return string|null
     */
    public function getAlternativeSetCode(): ?string;

    /**
     * Returns the conditional expression determining whether the document is required.
     *
     * @return string|null
     */
    public function getConditionExpression(): ?string;

    /**
     * Returns the display/evaluation order of the document within the group.
     *
     * @return int
     */
    public function getSortOrder(): int;

    /**
     * Verifies whether the document requirement is currently active.
     *
     * @return bool
     */
    public function isActive(): bool;
}
