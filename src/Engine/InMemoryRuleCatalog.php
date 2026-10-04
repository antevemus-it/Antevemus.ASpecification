<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IDocumentRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleCatalog;
use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;

/**
 * InMemoryRuleCatalog - Catálogo de Regras e Documentos Residente em Memória
 *
 * Implementação em memória de IRuleCatalog projetada para testes unitários, ambientes
 * de desenvolvimento isolados e compilação rápida de regras dinâmicas.
 *
 * Funcionalidades:
 * - Armazenamento fluido de regras e requisitos documentais
 * - Filtragem por escopo, cenário de negócio e status ativo
 * - Ordenação nativa por prioridade decrescente
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class InMemoryRuleCatalog implements IRuleCatalog
{
    /** @var list<IRuleDefinition> */
    private array $rules = [];

    /** @var list<IDocumentRuleDefinition> */
    private array $documentRules = [];

    /**
     * @param list<IRuleDefinition> $rules Regras iniciais
     * @param list<IDocumentRuleDefinition> $documentRules Requisitos documentais iniciais
     */
    public function __construct(array $rules = [], array $documentRules = [])
    {
        foreach ($rules as $rule) {
            $this->addRule($rule);
        }
        foreach ($documentRules as $docRule) {
            $this->addDocumentRule($docRule);
        }
    }

    /**
     * Adiciona uma regra de negócio ao catálogo.
     *
     * @param IRuleDefinition $rule
     * @return self
     */
    public function addRule(IRuleDefinition $rule): self
    {
        $this->rules[] = $rule;
        return $this;
    }

    /**
     * Adiciona um requisito documental ao catálogo.
     *
     * @param IDocumentRuleDefinition $documentRule
     * @return self
     */
    public function addDocumentRule(IDocumentRuleDefinition $documentRule): self
    {
        $this->documentRules[] = $documentRule;
        return $this;
    }

    /** {@inheritdoc} */
    public function findRules(string $escopo, ?string $cenario = null, array $filters = []): array
    {
        $matched = [];

        foreach ($this->rules as $rule) {
            if (!$rule->isActive()) {
                continue;
            }

            $ruleEscopo = $rule->getEscopo();
            // Regra global (escopo null) ou com mesmo escopo
            if ($ruleEscopo !== null && $ruleEscopo !== '' && $ruleEscopo !== $escopo) {
                continue;
            }

            $ruleCenario = $rule->getCenario();
            // Regra sem cenário (aplicável a todos do escopo) ou com mesmo cenário
            if ($ruleCenario !== null && $ruleCenario !== '' && $cenario !== null && $ruleCenario !== $cenario) {
                continue;
            }

            $matched[] = $rule;
        }

        // Ordena por prioridade decrescente
        usort($matched, fn(IRuleDefinition $a, IRuleDefinition $b) => $b->getPrioridade() <=> $a->getPrioridade());

        return $matched;
    }

    /** {@inheritdoc} */
    public function findDocumentRules(string $escopo, ?string $cenario = null): array
    {
        $matched = [];

        foreach ($this->documentRules as $docRule) {
            if (!$docRule->isActive()) {
                continue;
            }

            // O grupo_codigo pode representar o escopo ou cenario
            $grupo = $docRule->getGrupoCodigo();
            if ($cenario !== null && $grupo === $cenario) {
                $matched[] = $docRule;
                continue;
            }

            if ($grupo === $escopo) {
                $matched[] = $docRule;
                continue;
            }

            // Se for prefixado por escopo (ex: "contrato_locacao_ativacao" para escopo "contrato_locacao")
            if (str_starts_with($grupo, $escopo)) {
                $matched[] = $docRule;
            }
        }

        // Ordena pela coluna de ordem
        usort($matched, fn(IDocumentRuleDefinition $a, IDocumentRuleDefinition $b) => $a->getOrdem() <=> $b->getOrdem());

        return $matched;
    }
}
