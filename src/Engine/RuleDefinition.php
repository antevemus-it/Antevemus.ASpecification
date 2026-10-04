<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;

/**
 * RuleDefinition - Implementação Canônica e Imutável da Definição de Regra de Negócio
 *
 * Representa os metadados e parâmetros de uma regra configurável carregada do banco de dados,
 * suportando desserialização fluente a partir de arrays associativos (PDO, ORM).
 *
 * Funcionalidades:
 * - Estrutura imutável tipada (readonly)
 * - Fábrica estática a partir de arrays/registros de banco de dados (fromArray)
 * - Normalização automática de tipos (RuleAction, int, float, string)
 * - Suporte a metadados livres adicionais para parametrização avançada
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class RuleDefinition implements IRuleDefinition
{
    /**
     * @param string $codigo Código único da regra no catálogo
     * @param string $nome Nome descritivo da regra
     * @param string $tipoRegra Chave técnica identificadora do tipo de validação
     * @param RuleAction $acaoAoViolar Ação prescrita ao violar (bloquear, alertar, apenas_log)
     * @param string|null $descricao Descrição detalhada da finalidade da regra
     * @param int|null $valorInteiro Parâmetro numérico inteiro configurado
     * @param float|null $valorDecimal Parâmetro numérico decimal configurado
     * @param string|null $valorTexto Parâmetro textual configurado
     * @param string|null $fundamentoLegal Artigo de lei ou embasamento jurídico
     * @param string|null $mensagemViolacao Mensagem explicativa em caso de reprovação
     * @param string|null $condicionalExpressao Expressão condicional de ativação
     * @param int $prioridade Ordem de prioridade na avaliação
     * @param string|null $escopo Escopo operacional (ex: 'contrato_locacao', 'sinistro')
     * @param string|null $cenario Cenário específico de negócio
     * @param array<string, mixed> $parametros Metadados e parâmetros adicionais
     * @param bool $active Indicador de regra ativa
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
     * Instancia uma definição de regra a partir de um registro associativo de banco de dados.
     *
     * @param array<string, mixed> $row Registro relacional (ex: tabela rental_guarantee.regra_negocio)
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
