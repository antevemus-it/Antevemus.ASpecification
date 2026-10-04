<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Concurrent;

/**
 * NullSynchronizer - Implementação No-Op do Padrão Null Object para Sincronização
 *
 * Executa blocos de código imediatamente sem adquirir nenhum lock ou semáforo.
 * Ideal para testes unitários isolados, benchmarks, ambientes estritamente monociclo
 * ou quando a garantia de concorrência é gerenciada por camadas externas.
 *
 * Funcionalidades:
 * - Execução direta e imediata de callables concorrentes
 * - Execução direta e imediata de callables exclusivas
 * - Zero overhead de I/O de arquivo ou chamadas de sistema operacional
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Concurrent
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class NullSynchronizer extends AbstractSynchronizer
{
    /**
     * {@inheritdoc}
     */
    public function callConcurrently(callable $action): mixed
    {
        return $action();
    }

    /**
     * {@inheritdoc}
     */
    public function callExclusively(callable $action): mixed
    {
        return $action();
    }
}
