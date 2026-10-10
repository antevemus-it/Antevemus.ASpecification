<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IDocumentRuleDefinition;
use InvalidArgumentException;

/**
 * DocumentRuleDefinition - Canonical Implementation of Document Requirement Definition
 *
 * Represents the requirement criteria of a document type linked to an operational
 * validation group, mapping relational rules to typed value objects. Mirrors the
 * relational shape of the document matrix: a requirement always belongs to one
 * operational scope and optionally to one scenario within it.
 *
 * Features:
 * - Mandatory operational scope and optional scenario for exact catalog matching
 * - Support for universal (all), alternative (any), or exclusive (one_of_set) requirement rules
 * - Grouping by alternative sets (e.g. Identity Document: Tax ID, Passport, or Driver License)
 * - Direct hydration from relational database records (fromArray)
 * - English vocabulary since 1.7.0: fromArray() reads the English keys and, as legacy, the Portuguese
 *   keys of 1.6.x (English wins when both are present); the Portuguese getters remain as deprecated
 *   aliases, removed in 2.0.0
 *
 * @version    1.7.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class DocumentRuleDefinition implements IDocumentRuleDefinition
{
    /**
     * @param string $groupCode Stable document group code (identifier only)
     * @param string $documentTypeCode Code of the required document type
     * @param string $scope Operational scope the requirement belongs to (never empty)
     * @param DocumentRequirementMode $requirementMode Requirement mode (ALL, ANY, ONE_OF_SET)
     * @param string|null $alternativeSetCode Alternative set key, required for ANY or ONE_OF_SET rules
     * @param string|null $conditionExpression Conditional activation expression
     * @param int $sortOrder Sequential display order
     * @param bool $active Active status indicator
     * @param string|null $scenario Scenario within the scope; null applies to the whole scope
     * @throws InvalidArgumentException When the scope is empty
     */
    public function __construct(
        public string $groupCode,
        public string $documentTypeCode,
        public string $scope,
        public DocumentRequirementMode $requirementMode = DocumentRequirementMode::ALL,
        public ?string $alternativeSetCode = null,
        public ?string $conditionExpression = null,
        public int $sortOrder = 0,
        public bool $active = true,
        public ?string $scenario = null
    ) {
        if (trim($this->scope) === '') {
            throw new InvalidArgumentException(sprintf(
                'Document requirement "%s" (group "%s") must declare a non-empty operational scope.',
                $this->documentTypeCode,
                $this->groupCode
            ));
        }
    }

    /**
     * Instantiates a document requirement from an associative database record.
     *
     * Canonical keys: `group_code`, `document_type_code`, `scope`, `scenario`, `requirement_mode`,
     * `alternative_set_code`, `condition_expression`, `sort_order`, `active`. The Portuguese keys of
     * 1.6.x are accepted as legacy (`grupo_codigo`/`grupo`, `codigo_tipo_documento`/`tipo_documento`,
     * `escopo`, `cenario`, `regra_obrigatoriedade`/`regra`, `codigo_set_alternativas`/`set_alt`,
     * `condicional_expressao`/`condicional`, `ordem`, `ativo`); when both are present the English key
     * wins. No runtime notice is raised for a legacy key (catalog data is not code).
     *
     * @param array<string, mixed> $row Relational database record; the scope (`scope`, legacy `escopo`) is mandatory
     * @return self
     * @throws InvalidArgumentException When the record has no (or an empty) scope
     */
    public static function fromArray(array $row): self
    {
        $modeRaw = self::pick($row, 'requirement_mode', 'regra_obrigatoriedade', 'regra');
        $mode = $modeRaw instanceof DocumentRequirementMode
            ? $modeRaw
            : DocumentRequirementMode::fromOrDefault(is_string($modeRaw) ? $modeRaw : null);

        $activeRaw = self::pick($row, 'active', 'ativo') ?? true;
        $active = is_bool($activeRaw)
            ? $activeRaw
            : ($activeRaw === 'Y' || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'true');

        $setCode = self::pick($row, 'alternative_set_code', 'codigo_set_alternativas', 'set_alt');
        $condition = self::pick($row, 'condition_expression', 'condicional_expressao', 'condicional');
        $scenario = self::pick($row, 'scenario', 'cenario');

        return new self(
            groupCode: (string) (self::pick($row, 'group_code', 'grupo_codigo', 'grupo') ?? ''),
            documentTypeCode: (string) (self::pick($row, 'document_type_code', 'codigo_tipo_documento', 'tipo_documento') ?? ''),
            scope: (string) (self::pick($row, 'scope', 'escopo') ?? ''),
            requirementMode: $mode,
            alternativeSetCode: $setCode !== null ? (string) $setCode : null,
            conditionExpression: $condition !== null ? (string) $condition : null,
            sortOrder: (int) (self::pick($row, 'sort_order', 'ordem') ?? 0),
            active: $active,
            scenario: $scenario !== null && $scenario !== '' ? (string) $scenario : null
        );
    }

    /**
     * Returns the value of the first key present with a non-null value (canonical key first).
     *
     * @param array<string, mixed> $row
     * @param string ...$keys Canonical key followed by its legacy synonyms
     * @return mixed
     */
    private static function pick(array $row, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (isset($row[$key])) {
                return $row[$key];
            }
        }

        return null;
    }

    /** {@inheritdoc} */
    public function getGroupCode(): string
    {
        return $this->groupCode;
    }

    /** {@inheritdoc} */
    public function getDocumentTypeCode(): string
    {
        return $this->documentTypeCode;
    }

    /** {@inheritdoc} */
    public function getScope(): string
    {
        return $this->scope;
    }

    /** {@inheritdoc} */
    public function getScenario(): ?string
    {
        return $this->scenario;
    }

    /** {@inheritdoc} */
    public function getRequirementMode(): DocumentRequirementMode
    {
        return $this->requirementMode;
    }

    /** {@inheritdoc} */
    public function getAlternativeSetCode(): ?string
    {
        return $this->alternativeSetCode;
    }

    /** {@inheritdoc} */
    public function getConditionExpression(): ?string
    {
        return $this->conditionExpression;
    }

    /** {@inheritdoc} */
    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    /** {@inheritdoc} */
    public function isActive(): bool
    {
        return $this->active;
    }

    // ------------------------------------------------------------------
    // Portuguese aliases of 1.6.x (not part of IDocumentRuleDefinition since 1.7.0)
    // ------------------------------------------------------------------

    /** @deprecated 1.7.0 Removed in 2.0.0; use getGroupCode(). */
    public function getGrupoCodigo(): string
    {
        return $this->getGroupCode();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getDocumentTypeCode(). */
    public function getCodigoTipoDocumento(): string
    {
        return $this->getDocumentTypeCode();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getScope(). */
    public function getEscopo(): string
    {
        return $this->getScope();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getScenario(). */
    public function getCenario(): ?string
    {
        return $this->getScenario();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getRequirementMode(). */
    public function getRegraObrigatoriedade(): DocumentRequirementMode
    {
        return $this->getRequirementMode();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getAlternativeSetCode(). */
    public function getCodigoSetAlternativas(): ?string
    {
        return $this->getAlternativeSetCode();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getConditionExpression(). */
    public function getCondicionalExpressao(): ?string
    {
        return $this->getConditionExpression();
    }

    /** @deprecated 1.7.0 Removed in 2.0.0; use getSortOrder(). */
    public function getOrdem(): int
    {
        return $this->getSortOrder();
    }
}
