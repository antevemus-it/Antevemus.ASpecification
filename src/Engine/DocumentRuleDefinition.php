<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IDocumentRuleDefinition;

/**
 * DocumentRuleDefinition - Implementação Canônica da Definição de Requisito Documental
 *
 * Representa os critérios de obrigatoriedade de um tipo documental vinculado a um grupo
 * operacional de validação, mapeando regras relacionais para objetos de valor tipados.
 *
 * Funcionalidades:
 * - Suporte a regras de obrigatoriedade universal (all), alternativa (any) ou exclusiva (one_of_set)
 * - Agrupamento por set de alternativas (ex: documento de identidade: CPF, RG ou CNH)
 * - Hidratação direta a partir de registros de banco de dados (fromArray)
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class DocumentRuleDefinition implements IDocumentRuleDefinition
{
    /**
     * @param string $grupoCodigo Código estável do grupo documental
     * @param string $codigoTipoDocumento Código do tipo de documento exigido
     * @param DocumentRequirementMode $regraObrigatoriedade Modo de exigência (ALL, ANY, ONE_OF_SET)
     * @param string|null $codigoSetAlternativas Chave do set alternativo para regras ANY ou ONE_OF_SET
     * @param string|null $condicionalExpressao Expressão de ativação condicional
     * @param int $ordem Ordem sequencial
     * @param bool $active Indicador de status ativo
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
     * Instancia um requisito documental a partir de um registro associativo de banco de dados.
     *
     * @param array<string, mixed> $row Registro relacional
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
