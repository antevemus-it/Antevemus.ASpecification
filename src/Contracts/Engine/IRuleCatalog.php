<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Engine;

/**
 * IRuleCatalog - Repositório / Provedor de Definições de Regras e Documentos
 *
 * Abstrai o acesso à fonte de persistência (PostgreSQL, cache, arquivos JSON) onde
 * o catálogo de regras de negócio e os grupos documentais estão armazenados.
 *
 * Funcionalidades:
 * - Recuperação filtrada de regras de negócio ativas por escopo e cenário
 * - Recuperação filtrada de grupos documentais por escopo e cenário
 * - Suporte a filtros adicionais (produto, plano, data de vigência)
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IRuleCatalog
{
    /**
     * Recupera a coleção de regras de negócio ativas para o escopo e cenário dados.
     *
     * @param string $escopo Escopo operacional (ex.: 'contrato_locacao', 'sinistro')
     * @param string|null $cenario Cenário específico (ex.: 'sinistro:ocupado')
     * @param array<string, mixed> $filters Filtros adicionais (codigo_produto, codigo_plano, etc.)
     * @return list<IRuleDefinition>
     */
    public function findRules(string $escopo, ?string $cenario = null, array $filters = []): array;

    /**
     * Recupera a coleção de requisitos documentais para o escopo e cenário dados.
     *
     * @param string $escopo Escopo operacional (ex.: 'contrato_locacao', 'sinistro')
     * @param string|null $cenario Cenário específico (ex.: 'sinistro:ocupado')
     * @return list<IDocumentRuleDefinition>
     */
    public function findDocumentRules(string $escopo, ?string $cenario = null): array;
}
