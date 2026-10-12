<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IDocumentRuleDefinition;
use Antevemus\ASpecification\Engine\Exceptions\LegacyVocabularyException;
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
 * - English vocabulary since 1.7.0, only English since 2.0.0: fromArray() refuses a Portuguese key of
 *   1.6.x (LEGACY_KEYS) with LegacyVocabularyException; the deprecated Portuguese getters were removed
 *
 * @version    2.0.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class DocumentRuleDefinition implements IDocumentRuleDefinition
{
    /**
     * Portuguese keys of a document requirement row read by fromArray() up to 1.14.x (legacy =>
     * canonical), synonyms included; refused since 2.0.0 (LegacyVocabularyException).
     */
    public const LEGACY_KEYS = [
        'grupo_codigo' => 'group_code',
        'grupo' => 'group_code',
        'codigo_tipo_documento' => 'document_type_code',
        'tipo_documento' => 'document_type_code',
        'escopo' => 'scope',
        'cenario' => 'scenario',
        'regra_obrigatoriedade' => 'requirement_mode',
        'regra' => 'requirement_mode',
        'codigo_set_alternativas' => 'alternative_set_code',
        'set_alt' => 'alternative_set_code',
        'condicional_expressao' => 'condition_expression',
        'condicional' => 'condition_expression',
        'ordem' => 'sort_order',
        'ativo' => 'active',
    ];

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
     * Keys: `group_code`, `document_type_code`, `scope`, `scenario`, `requirement_mode`,
     * `alternative_set_code`, `condition_expression`, `sort_order`, `active`. The Portuguese keys of
     * 1.6.x (read as legacy up to 1.14.x: `grupo_codigo`/`grupo`, `codigo_tipo_documento`/`tipo_documento`,
     * `escopo`, `cenario`, `regra_obrigatoriedade`/`regra`, `codigo_set_alternativas`/`set_alt`,
     * `condicional_expressao`/`condicional`, `ordem`, `ativo`) are refused since 2.0.0 with
     * LegacyVocabularyException, which points to UPGRADE-2.0.md.
     *
     * @param array<string, mixed> $row Relational database record; the scope (`scope`) is mandatory
     * @return self
     * @throws InvalidArgumentException When the record has no (or an empty) scope
     * @throws LegacyVocabularyException When the row carries a Portuguese key of 1.x
     */
    public static function fromArray(array $row): self
    {
        LegacyVocabularyException::assertNoLegacyKeys($row, self::LEGACY_KEYS, sprintf(
            'document requirement row "%s" key',
            is_scalar($row['document_type_code'] ?? null) ? (string) $row['document_type_code'] : '?'
        ));

        $modeRaw = $row['requirement_mode'] ?? null;
        $mode = $modeRaw instanceof DocumentRequirementMode
            ? $modeRaw
            : DocumentRequirementMode::fromOrDefault(is_string($modeRaw) ? $modeRaw : null);

        $activeRaw = $row['active'] ?? true;
        $active = is_bool($activeRaw)
            ? $activeRaw
            : ($activeRaw === 'Y' || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'true');

        $setCode = $row['alternative_set_code'] ?? null;
        $condition = $row['condition_expression'] ?? null;
        $scenario = $row['scenario'] ?? null;

        return new self(
            groupCode: (string) ($row['group_code'] ?? ''),
            documentTypeCode: (string) ($row['document_type_code'] ?? ''),
            scope: (string) ($row['scope'] ?? ''),
            requirementMode: $mode,
            alternativeSetCode: $setCode !== null ? (string) $setCode : null,
            conditionExpression: $condition !== null ? (string) $condition : null,
            sortOrder: (int) ($row['sort_order'] ?? 0),
            active: $active,
            scenario: $scenario !== null && $scenario !== '' ? (string) $scenario : null
        );
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
}
