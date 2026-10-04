<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Concurrent;

/**
 * ISynchronizer - Contrato para Sincronização de Execução Concorrente e Exclusiva
 *
 * Define operações de controle de concorrência baseadas no padrão Read/Write Lock.
 * Permite que blocos de código (callables) sejam executados em modo CONCURRENT
 * (compartilhado para múltiplas leituras simultâneas) ou em modo EXCLUSIVE
 * (acesso atômico e isolado para mutações/escritas críticas).
 *
 * Funcionalidades:
 * - Execução concorrente void (runConcurrently) e com retorno de valor (callConcurrently)
 * - Execução exclusiva void (runExclusively) e com retorno de valor (callExclusively)
 * - Garantia de liberação de travas/permissões via blocos protegidos
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Concurrent
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISynchronizer
{
    /**
     * Executa uma ação de forma concorrente (compartilhada).
     *
     * Ideal para leituras paralelas. Não bloqueia outras operações concorrentes,
     * mas cede passagem para operações exclusivas pendentes ou ativas.
     *
     * @param callable(): void $action Ação a ser executada
     * @return void
     */
    public function runConcurrently(callable $action): void;

    /**
     * Executa uma ação com retorno de valor de forma concorrente (compartilhada).
     *
     * @template T
     * @param callable(): T $action Ação a ser executada
     * @return T Resultado retornado pela ação
     */
    public function callConcurrently(callable $action): mixed;

    /**
     * Executa uma ação de forma exclusiva (isolada).
     *
     * Aguarda todas as operações concorrentes em andamento terminarem,
     * bloqueia novas operações concorrentes e exclusivas, e executa a ação de forma atômica.
     *
     * @param callable(): void $action Ação a ser executada
     * @return void
     */
    public function runExclusively(callable $action): void;

    /**
     * Executa uma ação com retorno de valor de forma exclusiva (isolada).
     *
     * @template T
     * @param callable(): T $action Ação a ser executada
     * @return T Resultado retornado pela ação
     */
    public function callExclusively(callable $action): mixed;
}
