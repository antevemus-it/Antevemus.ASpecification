<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Engine;

use Antevemus\ASpecification\Engine\RuleAction;

/**
 * IRuleDefinition - Contrato para Definição Abstrata de Regra de Negócio de Catálogo
 *
 * Encapsula os atributos fundamentais de uma especificação configurável de banco de dados,
 * contendo identificação, parâmetros tipados, fundamentos legais e diretrizes de ação.
 *
 * Funcionalidades:
 * - Acesso tipado a chaves estáveis e tipo técnico de validação
 * - Resolução de parâmetros polimórficos (inteiro, decimal, texto, metadados)
 * - Identificação de severidade e ação operacional de violação (RuleAction)
 * - Fundamentação legal para auditoria e emissão contratual
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IRuleDefinition
{
    /**
     * Retorna o código único e estável da regra.
     *
     * @return string
     */
    public function getCodigo(): string;

    /**
     * Retorna o nome amigável da regra.
     *
     * @return string
     */
    public function getNome(): string;

    /**
     * Retorna a descrição detalhada da finalidade da regra.
     *
     * @return string|null
     */
    public function getDescricao(): ?string;

    /**
     * Retorna a chave técnica que identifica o tipo de validação (ex: 'max_ocorrencias_por_contrato').
     *
     * @return string
     */
    public function getTipoRegra(): string;

    /**
     * Retorna a ação operacional prescrita ao violar a regra.
     *
     * @return RuleAction
     */
    public function getAcaoAoViolar(): RuleAction;

    /**
     * Retorna o parâmetro inteiro configurado (ex: dias de aviso prévio, quantidade máxima).
     *
     * @return int|null
     */
    public function getValorInteiro(): ?int;

    /**
     * Retorna o parâmetro decimal configurado (ex: percentual de multa, valor mínimo em reais).
     *
     * @return float|null
     */
    public function getValorDecimal(): ?float;

    /**
     * Retorna o parâmetro textual configurado (ex: expressão, código secundário).
     *
     * @return string|null
     */
    public function getValorTexto(): ?string;

    /**
     * Retorna o artigo de lei ou base normativa correspondente (ex: 'Lei 8.245/91 Art. 43, II').
     *
     * @return string|null
     */
    public function getFundamentoLegal(): ?string;

    /**
     * Retorna a mensagem amigável a ser exibida em caso de violação.
     *
     * @return string|null
     */
    public function getMensagemViolacao(): ?string;

    /**
     * Retorna a expressão ou predicado de guarda condicional para ativação da regra.
     *
     * @return string|null
     */
    public function getCondicionalExpressao(): ?string;

    /**
     * Retorna a prioridade de execução da regra (maior prioridade avaliada primeiro).
     *
     * @return int
     */
    public function getPrioridade(): int;

    /**
     * Retorna o escopo de aplicação da regra (ex: 'contrato_locacao', 'sinistro', 'endosso').
     *
     * @return string|null
     */
    public function getEscopo(): ?string;

    /**
     * Retorna o cenário de negócio aplicável (ex: 'sinistro:ocupado', 'sinistro:desocupado').
     *
     * @return string|null
     */
    public function getCenario(): ?string;

    /**
     * Retorna metadados ou parâmetros complementares em formato associativo.
     *
     * @return array<string, mixed>
     */
    public function getParametros(): array;

    /**
     * Verifica se a regra está ativa para avaliação.
     *
     * @return bool
     */
    public function isActive(): bool;
}
