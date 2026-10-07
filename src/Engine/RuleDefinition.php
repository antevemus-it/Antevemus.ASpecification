<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;

/**
 * RuleDefinition - Canonical Immutable Implementation of Business Rule Definition
 *
 * Represents the metadata and parameters of a configurable business rule loaded
 * from the database, supporting fluent hydration from associative arrays (PDO, ORM).
 *
 * Features:
 * - Immutable typed structure (readonly)
 * - Static factory from relational database records/arrays (fromArray)
 * - Automatic type normalization (RuleAction, int, float, string)
 * - Support for open-ended parameters and contextual metadata
 * - Applicability columns of the catalog row (product, plan, validity window) hydrated into parametros (RN-17)
 *
 * @version    1.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class RuleDefinition implements IRuleDefinition
{
    /**
     * Catalog row columns that restrict where a rule applies. They travel in `parametros` under the
     * same keys, where IRuleCatalog implementations read them (RN-17, BUG-20261007-ZB6A).
     */
    public const APPLICABILITY_COLUMNS = ['codigo_produto', 'codigo_plano', 'data_inicio_vigencia', 'data_fim_vigencia'];

    /**
     * @param string $codigo Unique rule identifier in the catalog
     * @param string $nome Descriptive rule name
     * @param string $tipoRegra Technical key identifying the validation type
     * @param RuleAction $acaoAoViolar Prescribed violation action (block, warn, log)
     * @param string|null $descricao Detailed description of the rule purpose
     * @param int|null $valorInteiro Configured integer numeric parameter
     * @param float|null $valorDecimal Configured decimal numeric parameter
     * @param string|null $valorTexto Configured string parameter
     * @param string|null $fundamentoLegal Statutory article or regulatory foundation
     * @param string|null $mensagemViolacao Human-readable explanatory failure message
     * @param string|null $condicionalExpressao Conditional activation expression
     * @param int $prioridade Evaluation priority order
     * @param string|null $escopo Operational scope (e.g. 'rental_contract', 'claim')
     * @param string|null $cenario Specific business scenario
     * @param array<string, mixed> $parametros Additional parameters and metadata
     * @param bool $active Active rule indicator
     */
    public function __construct(
        public string $codigo,
        public string $nome,
        public string $tipoRegra,
        public RuleAction $acaoAoViolar = RuleAction::BLOCK,
        public ?string $descricao = null,
        public ?int $valorInteiro = null,
        public ?float $valorDecimal = null,
        public ?string $valorTexto = null,
        public ?string $fundamentoLegal = null,
        public ?string $mensagemViolacao = null,
        public ?string $condicionalExpressao = null,
        public int $prioridade = 0,
        public ?string $escopo = null,
        public ?string $cenario = null,
        public array $parametros = [],
        public bool $active = true
    ) {
    }

    /**
     * Instantiates a rule definition from an associative database record.
     *
     * @param array<string, mixed> $row Relational record (e.g. rule catalog table row)
     * @return self
     */
    public static function fromArray(array $row): self
    {
        $acaoRaw = $row['acao_ao_violar'] ?? $row['acao'] ?? null;
        $acao = $acaoRaw instanceof RuleAction
            ? $acaoRaw
            : RuleAction::fromOrDefault(is_string($acaoRaw) ? $acaoRaw : null);

        $activeRaw = $row['active'] ?? $row['ativo'] ?? true;
        $active = is_bool($activeRaw)
            ? $activeRaw
            : ($activeRaw === 'Y' || $activeRaw === '1' || $activeRaw === 1 || $activeRaw === 'true');

        $valorInteiro = isset($row['valor_inteiro']) && $row['valor_inteiro'] !== null && $row['valor_inteiro'] !== ''
            ? (int) $row['valor_inteiro']
            : null;

        $valorDecimal = isset($row['valor_decimal']) && $row['valor_decimal'] !== null && $row['valor_decimal'] !== ''
            ? (float) $row['valor_decimal']
            : null;

        $parametros = isset($row['parametros']) && is_array($row['parametros'])
            ? $row['parametros']
            : [];

        // RN-17: applicability columns are copied into parametros; an explicit entry prevails,
        // a null or empty column means "no restriction" and is not copied.
        foreach (self::APPLICABILITY_COLUMNS as $column) {
            if (!array_key_exists($column, $parametros) && isset($row[$column]) && $row[$column] !== '') {
                $parametros[$column] = $row[$column];
            }
        }

        return new self(
            codigo: (string) ($row['codigo'] ?? ''),
            nome: (string) ($row['nome'] ?? ''),
            tipoRegra: (string) ($row['tipo_regra'] ?? $row['tipo'] ?? ''),
            acaoAoViolar: $acao,
            descricao: isset($row['descricao']) ? (string) $row['descricao'] : null,
            valorInteiro: $valorInteiro,
            valorDecimal: $valorDecimal,
            valorTexto: isset($row['valor_texto']) ? (string) $row['valor_texto'] : null,
            fundamentoLegal: isset($row['fundamento_legal']) ? (string) $row['fundamento_legal'] : null,
            mensagemViolacao: isset($row['mensagem_violacao']) ? (string) $row['mensagem_violacao'] : null,
            condicionalExpressao: isset($row['condicional_expressao']) ? (string) $row['condicional_expressao'] : null,
            prioridade: (int) ($row['prioridade'] ?? 0),
            escopo: isset($row['escopo']) ? (string) $row['escopo'] : null,
            cenario: isset($row['cenario']) ? (string) $row['cenario'] : null,
            parametros: $parametros,
            active: $active
        );
    }

    /** {@inheritdoc} */
    public function getCodigo(): string
    {
        return $this->codigo;
    }

    /** {@inheritdoc} */
    public function getNome(): string
    {
        return $this->nome;
    }

    /** {@inheritdoc} */
    public function getDescricao(): ?string
    {
        return $this->descricao;
    }

    /** {@inheritdoc} */
    public function getTipoRegra(): string
    {
        return $this->tipoRegra;
    }

    /** {@inheritdoc} */
    public function getAcaoAoViolar(): RuleAction
    {
        return $this->acaoAoViolar;
    }

    /** {@inheritdoc} */
    public function getValorInteiro(): ?int
    {
        return $this->valorInteiro;
    }

    /** {@inheritdoc} */
    public function getValorDecimal(): ?float
    {
        return $this->valorDecimal;
    }

    /** {@inheritdoc} */
    public function getValorTexto(): ?string
    {
        return $this->valorTexto;
    }

    /** {@inheritdoc} */
    public function getFundamentoLegal(): ?string
    {
        return $this->fundamentoLegal;
    }

    /** {@inheritdoc} */
    public function getMensagemViolacao(): ?string
    {
        return $this->mensagemViolacao;
    }

    /** {@inheritdoc} */
    public function getCondicionalExpressao(): ?string
    {
        return $this->condicionalExpressao;
    }

    /** {@inheritdoc} */
    public function getPrioridade(): int
    {
        return $this->prioridade;
    }

    /** {@inheritdoc} */
    public function getEscopo(): ?string
    {
        return $this->escopo;
    }

    /** {@inheritdoc} */
    public function getCenario(): ?string
    {
        return $this->cenario;
    }

    /** {@inheritdoc} */
    public function getParametros(): array
    {
        return $this->parametros;
    }

    /** {@inheritdoc} */
    public function isActive(): bool
    {
        return $this->active;
    }
}
