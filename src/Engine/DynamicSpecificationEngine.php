<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Antevemus\ASpecification\Contracts\Engine\IDynamicSpecificationEngine;
use Antevemus\ASpecification\Contracts\Engine\IRuleCatalog;
use Antevemus\ASpecification\Contracts\Engine\IRuleSpecificationRegistry;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Spec;

/**
 * DynamicSpecificationEngine - Motor de Execução e Compilação Dinâmica de Especificações
 *
 * Ponto focal que orquestra a consulta ao catálogo de regras/documentos, a compilação
 * em especificações de alta performance e a avaliação rica através do Notification Pattern.
 *
 * Funcionalidades:
 * - Compilação unificada de regras de negócio e de documentos obrigatórios
 * - Avaliação rica de entidades de domínio retornando RuleEngineVerdict tipado
 * - Triagem automatizada de falhas operacionais (bloqueios HTTP 403, alertas e logs)
 * - Integração transparente com a álgebra e o SpecificationResult do ASpecification
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class DynamicSpecificationEngine implements IDynamicSpecificationEngine
{
    private DocumentGroupSpecificationBuilder $documentBuilder;

    /**
     * @param IRuleCatalog $catalog Provedor/repositório do catálogo de regras
     * @param IRuleSpecificationRegistry $registry Registro de handlers de regras
     * @param DocumentGroupSpecificationBuilder|null $documentBuilder Compilador documental opcional
     */
    public function __construct(
        private readonly IRuleCatalog $catalog,
        private readonly IRuleSpecificationRegistry $registry,
        ?DocumentGroupSpecificationBuilder $documentBuilder = null
    ) {
        $this->documentBuilder = $documentBuilder ?? new DocumentGroupSpecificationBuilder();
    }

    /** {@inheritdoc} */
    public function validate(
        object|array $target,
        string $escopo,
        ?string $cenario = null,
        array $context = []
    ): RuleEngineVerdict {
        $specification = $this->compileSpecification($escopo, $cenario, $context);
        $result = $specification->evaluate($target);

        return RuleEngineVerdict::fromSpecificationResult($result);
    }

    /** {@inheritdoc} */
    public function compileSpecification(
        string $escopo,
        ?string $cenario = null,
        array $context = []
    ): ISpecification {
        $specs = [];

        // 1. Carrega e compila as regras de negócio
        $rules = $this->catalog->findRules($escopo, $cenario, $context);
        foreach ($rules as $rule) {
            $specs[] = $this->registry->buildSpecification($rule);
        }

        // 2. Carrega e compila os requisitos de documentos obrigatórios
        $docRules = $this->catalog->findDocumentRules($escopo, $cenario);
        if (!empty($docRules)) {
            $specs[] = $this->documentBuilder->build($docRules, $context);
        }

        if (empty($specs)) {
            return Spec::alwaysTrue();
        }

        if (count($specs) === 1) {
            return $specs[0];
        }

        return Spec::allOf(...$specs);
    }

    /** {@inheritdoc} */
    public function getCatalog(): IRuleCatalog
    {
        return $this->catalog;
    }

    /** {@inheritdoc} */
    public function getRegistry(): IRuleSpecificationRegistry
    {
        return $this->registry;
    }
}
