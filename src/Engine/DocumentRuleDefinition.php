<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IDocumentRuleDefinition;

/**
 * DocumentRuleDefinition - Canonical Implementation of Document Requirement Definition
 *
 * Represents the requirement criteria of a document type linked to an operational
 * validation group, mapping relational rules to typed value objects.
 *
 * Features:
 * - Support for universal (all), alternative (any), or exclusive (one_of_set) requirement rules
 * - Grouping by alternative sets (e.g. Identity Document: Tax ID, Passport, or Driver License)
 * - Direct hydration from relational database records (fromArray)
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class DocumentRuleDefinition implements IDocumentRuleDefinition
{
    /**
     * @param string $grupoCodigo Stable document group code
     * @param string $codigoTipoDocumento Code of the required document type
     * @param DocumentRequirementMode $regraObrigatoriedade Requirement mode (ALL, ANY, ONE_OF_SET)
     * @param string|null $codigoSetAlternativas Alternative set key for ANY or ONE_OF_SET rules
     * @param string|null $condicionalExpressao Conditional activation expression
     * @param int $ordem Sequential display order
     * @param bool $active Active status indicator
     */
    public function __construct(
        public string $grupoCodigo,
        public string $codigoTipoDocumento,
        public DocumentRequirementMode $regraObrigatoriedade = DocumentRequirementMode::ALL,
        public ?string $codigoSetAlternativas = null,
        public ?string $condicionalExpressao = null,
        public int $ordem = 0,
        public bool $active = true
    ) {
    }

    /**
     * Instantiates a document requirement from an associative database record.
     *
     * @param array<string, mixed> $row Relational database record
     * @return self
     */
    public static function fromArray(array $row): self
    {
        $regraRaw = $row['regra_obrigatoriedade'] ?? $row['regra'] ?? null;
        $modo = $regraRaw instanceof DocumentRequirementMode
            ? $regraRaw
            : DocumentRequirementMode::fromOrDefault(is_string($regraRaw) ? $regraRaw : null);

        $activeRaw = $row['active'] ?? $row['ativo'] ?? true;
        $active = is_bool($activeRaw)
            ? $activeRaw
            : ($activeRaw === 'Y' || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'true');

        return new self(
            grupoCodigo: (string) ($row['grupo_codigo'] ?? $row['grupo'] ?? ''),
            codigoTipoDocumento: (string) ($row['codigo_tipo_documento'] ?? $row['tipo_documento'] ?? ''),
            regraObrigatoriedade: $modo,
            codigoSetAlternativas: isset($row['codigo_set_alternativas']) ? (string) $row['codigo_set_alternativas'] : (isset($row['set_alt']) ? (string) $row['set_alt'] : null),
            condicionalExpressao: isset($row['condicional_expressao']) ? (string) $row['condicional_expressao'] : (isset($row['condicional']) ? (string) $row['condicional'] : null),
            ordem: (int) ($row['ordem'] ?? 0),
            active: $active
        );
    }

    /** {@inheritdoc} */
    public function getGrupoCodigo(): string
    {
        return $this->grupoCodigo;
    }

    /** {@inheritdoc} */
    public function getCodigoTipoDocumento(): string
    {
        return $this->codigoTipoDocumento;
    }

    /** {@inheritdoc} */
    public function getRegraObrigatoriedade(): DocumentRequirementMode
    {
        return $this->regraObrigatoriedade;
    }

    /** {@inheritdoc} */
    public function getCodigoSetAlternativas(): ?string
    {
        return $this->codigoSetAlternativas;
    }

    /** {@inheritdoc} */
    public function getCondicionalExpressao(): ?string
    {
        return $this->condicionalExpressao;
    }

    /** {@inheritdoc} */
    public function getOrdem(): int
    {
        return $this->ordem;
    }

    /** {@inheritdoc} */
    public function isActive(): bool
    {
        return $this->active;
    }
}
