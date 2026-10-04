<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Engine;

use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\RuleEngineVerdict;

/**
 * IDynamicSpecificationEngine - Orquestrador Principal do Motor Dinâmico de Especificações
 *
 * Coordena o carregamento de regras do catálogo, a compilação em árvore booleana de
 * especificações, a execução contra a entidade alvo e a emissão do veredito operacional.
 *
 * Funcionalidades:
 * - Validação integral de regras e obrigatoriedades documentais com um único comando
 * - Compilação isolada de especificações compostas para inspeção ou testes
 * - Acesso aos repositórios de catálogo e registro de handlers
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IDynamicSpecificationEngine
{
    /**
     * Avalia o alvo contra todas as regras e documentos ativos para o escopo e cenário informados.
     *
     * @param object|array $target Entidade de domínio ou estrutura a ser avaliada
     * @param string $escopo Escopo operacional (ex.: 'contrato_locacao', 'sinistro')
     * @param string|null $cenario Cenário de negócio opcional
     * @param array<string, mixed> $context Metadados adicionais de contexto
     * @return RuleEngineVerdict
     */
    public function validate(
        object|array $target,
        string $escopo,
        ?string $cenario = null,
        array $context = []
    ): RuleEngineVerdict;

    /**
     * Compila e retorna a especificação composta unificada sem executá-la imediatamente.
     *
     * @param string $escopo Escopo operacional
     * @param string|null $cenario Cenário de negócio opcional
     * @param array<string, mixed> $context Metadados adicionais de contexto
     * @return ISpecification
     */
    public function compileSpecification(
        string $escopo,
        ?string $cenario = null,
        array $context = []
    ): ISpecification;

    /**
     * Retorna o catálogo de regras associado.
     *
     * @return IRuleCatalog
     */
    public function getCatalog(): IRuleCatalog;

    /**
     * Retorna o registro de handlers de especificação.
     *
     * @return IRuleSpecificationRegistry
     */
    public function getRegistry(): IRuleSpecificationRegistry;
}
