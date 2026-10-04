<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

use Closure;
use Antevemus\ASpecification\Contracts\Engine\IRuleDefinition;
use Antevemus\ASpecification\Contracts\Engine\IRuleSpecificationHandler;
use Antevemus\ASpecification\Contracts\Engine\IRuleSpecificationRegistry;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Engine\Exceptions\MissingRuleHandlerException;

/**
 * RuleSpecificationRegistry - Registro Central e Configurador de Handlers de Regras de Negócio
 *
 * Gerencia o catálogo de estratégias de compilação de especificações, mapeando cada `tipo_regra`
 * para sua respectiva fábrica especializada (classes dedicadas ou Closures concisas).
 *
 * Funcionalidades:
 * - Registro fluido de instâncias de IRuleSpecificationHandler
 * - Registro direto de manipuladores baseados em Closures
 * - Resolução rápida de handlers por chave técnica (tipo_regra)
 * - Lançamento de MissingRuleHandlerException caso um tipo solicitado não possua handler registrado
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class RuleSpecificationRegistry implements IRuleSpecificationRegistry
{
    /** @var array<string, IRuleSpecificationHandler> */
    private array $handlers = [];

    /** @var array<string, Closure(IRuleDefinition): ISpecification> */
    private array $closures = [];

    /**
     * Instancia um novo registro de handlers de especificações.
     *
     * @param list<IRuleSpecificationHandler> $initialHandlers Handlers iniciais opcionais
     */
    public function __construct(array $initialHandlers = [])
    {
        foreach ($initialHandlers as $handler) {
            $this->register($handler);
        }
    }

    /** {@inheritdoc} */
    public function register(IRuleSpecificationHandler $handler): self
    {
        // Usa um registro temporário para descobrir os tipos suportados ou armazena na lista
        $this->handlers[] = $handler;
        return $this;
    }

    /** {@inheritdoc} */
    public function registerClosure(string $tipoRegra, Closure $factory): self
    {
        $this->closures[trim($tipoRegra)] = $factory;
        return $this;
    }

    /** {@inheritdoc} */
    public function getHandler(string $tipoRegra): ?IRuleSpecificationHandler
    {
        $key = trim($tipoRegra);
        foreach ($this->handlers as $handler) {
            if ($handler->supports($key)) {
                return $handler;
            }
        }
        return null;
    }

    /** {@inheritdoc} */
    public function hasHandler(string $tipoRegra): bool
    {
        $key = trim($tipoRegra);
        if (isset($this->closures[$key])) {
            return true;
        }

        return $this->getHandler($key) !== null;
    }

    /** {@inheritdoc} */
    public function buildSpecification(IRuleDefinition $rule): ISpecification
    {
        $tipo = $rule->getTipoRegra();

        // 1. Prioridade para handlers registrados via Closure
        if (isset($this->closures[$tipo])) {
            $closure = $this->closures[$tipo];
            return $closure($rule);
        }

        // 2. Busca em handlers de classe
        $handler = $this->getHandler($tipo);
        if ($handler !== null) {
            return $handler->build($rule);
        }

        throw new MissingRuleHandlerException($tipo, $rule->getCodigo());
    }
}
