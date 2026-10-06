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
 * - Resolution of Boolean requirement semantics (all, any, one_of_set)
 * - Grouping by alternative sets (e.g., 'identity')
 * - Conditional expression for requirement activation
 *
 * @version    1.1.0
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
     *
     * @return string
     */
    public function getGrupoCodigo(): string;

    /**
     * Returns the technical code of the required document type (e.g. 'cnpj_imobiliaria', 'cnh_locatario').
     *
     * @return string
     */
    public function getCodigoTipoDocumento(): string;

    /**
     * Returns the document requirement mode (ALL, ANY, ONE_OF_SET).
     *
     * @return DocumentRequirementMode
     */
    public function getRegraObrigatoriedade(): DocumentRequirementMode;

    /**
     * Returns the alternative set identifier for ANY or ONE_OF_SET rules.
     *
     * @return string|null
     */
    public function getCodigoSetAlternativas(): ?string;

    /**
     * Returns the conditional expression determining whether the document is required.
     *
     * @return string|null
     */
    public function getCondicionalExpressao(): ?string;

    /**
     * Returns the display/evaluation order of the document within the group.
     *
     * @return int
     */
    public function getOrdem(): int;

    /**
     * Verifies whether the document requirement is currently active.
     *
     * @return bool
     */
    public function isActive(): bool;
}
