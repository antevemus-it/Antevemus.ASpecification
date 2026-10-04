<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Engine;

use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * IRuleSpecificationHandler - Contrato para Fábrica Especializada de Especificação de Regra
 *
 * Define o mecanismo plugável que traduz uma definição de catálogo (RuleDefinition)
 * em uma especificação executável (ISpecification) do ecossistema ASpecification.
 *
 * Funcionalidades:
 * - Validação de suporte a chaves técnicas de tipo de regra (supports)
 * - Compilação da regra em uma especificação de domínio com metadados
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IRuleSpecificationHandler
{
    /**
     * Verifica se este handler é capaz de compilar o tipo de regra fornecido.
     *
     * @param string $tipoRegra Chave técnica do tipo de validação
     * @return bool
     */
    public function supports(string $tipoRegra): bool;

    /**
     * Constrói a especificação correspondente com base nos parâmetros da regra.
     *
     * @param IRuleDefinition $rule Definição da regra carregada do catálogo
     * @return ISpecification
     */
    public function build(IRuleDefinition $rule): ISpecification;
}
