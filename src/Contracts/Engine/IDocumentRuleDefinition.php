<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Engine;

use Antevemus\ASpecification\Engine\DocumentRequirementMode;

/**
 * IDocumentRuleDefinition - Contrato para Requisito Documental em Grupo Operacional
 *
 * Define os parâmetros de obrigatoriedade de um tipo documental em uma operação de domínio,
 * estabelecendo a regra booleana aplicável (all, any, one_of_set) e sets alternativos.
 *
 * Funcionalidades:
 * - Vinculação com o código do tipo de documento
 * - Resolução de semântica booleana (all, any, one_of_set)
 * - Agrupamento por set de alternativas (ex.: 'identidade')
 * - Expressão condicional para ativação do requisito
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IDocumentRuleDefinition
{
    /**
     * Retorna o código do grupo de obrigatoriedade ao qual este documento pertence.
     *
     * @return string
     */
    public function getGrupoCodigo(): string;

    /**
     * Retorna o código técnico do tipo de documento exigido (ex: 'cnpj_imobiliaria', 'cnh_locatario').
     *
     * @return string
     */
    public function getCodigoTipoDocumento(): string;

    /**
     * Retorna o modo de obrigatoriedade documental (ALL, ANY, ONE_OF_SET).
     *
     * @return DocumentRequirementMode
     */
    public function getRegraObrigatoriedade(): DocumentRequirementMode;

    /**
     * Retorna o identificador do conjunto de alternativas para regras ANY ou ONE_OF_SET.
     *
     * @return string|null
     */
    public function getCodigoSetAlternativas(): ?string;

    /**
     * Retorna a expressão condicional que determina se o documento deve ser exigido.
     *
     * @return string|null
     */
    public function getCondicionalExpressao(): ?string;

    /**
     * Retorna a ordem de exibição/avaliação do documento no grupo.
     *
     * @return int
     */
    public function getOrdem(): int;

    /**
     * Verifica se o requisito documental está ativo.
     *
     * @return bool
     */
    public function isActive(): bool;
}
