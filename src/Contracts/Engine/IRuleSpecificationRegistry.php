<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Engine;

use Closure;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * IRuleSpecificationRegistry - Registro Central de Handlers de Regras de Negócio
 *
 * Gerencia o ciclo de vida e a resolução de fábricas de especificações para cada
 * tipo de regra suportado pela aplicação.
 *
 * Funcionalidades:
 * - Registro de instâncias especializadas de IRuleSpecificationHandler
 * - Registro dinâmico de handlers baseados em Closures
 * - Resolução transparente e instanciação da especificação alvo
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IRuleSpecificationRegistry
{
    /**
     * Registra um handler de especificação no catálogo do registro.
     *
     * @param IRuleSpecificationHandler $handler
     * @return self
     */
    public function register(IRuleSpecificationHandler $handler): self;

    /**
     * Registra um manipulador conciso através de uma Closure.
     *
     * @param string $tipoRegra Chave técnica do tipo de regra
     * @param Closure(IRuleDefinition): ISpecification $factory
     * @return self
     */
    public function registerClosure(string $tipoRegra, Closure $factory): self;

    /**
     * Obtém o handler responsável por processar o tipo de regra especificado.
     *
     * @param string $tipoRegra Chave técnica
     * @return IRuleSpecificationHandler|null
     */
    public function getHandler(string $tipoRegra): ?IRuleSpecificationHandler;

    /**
     * Verifica se existe um handler registrado para o tipo de regra.
     *
     * @param string $tipoRegra Chave técnica
     * @return bool
     */
    public function hasHandler(string $tipoRegra): bool;

    /**
     * Compila e retorna a especificação para a definição de regra informada.
     *
     * @param IRuleDefinition $rule
     * @return ISpecification
     */
    public function buildSpecification(IRuleDefinition $rule): ISpecification;
}
