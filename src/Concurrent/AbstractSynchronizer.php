<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Concurrent;

use Antevemus\ASpecification\Contracts\Concurrent\ISynchronizer;

/**
 * AbstractSynchronizer - Classe Base Abstrata para Sincronizadores de Concorrência
 *
 * Provê implementação padrão para operações de execução void (runConcurrently e runExclusively)
 * delegando diretamente para suas respectivas operações com retorno (callConcurrently e callExclusively).
 * Subclasses precisam apenas implementar as duas operações primitivas de aquisição e liberação de locks.
 *
 * Funcionalidades:
 * - Delegação transparente de runConcurrently para callConcurrently
 * - Delegação transparente de runExclusively para callExclusively
 * - Base extensível para estratégias de sincronização em memória, arquivos ou semáforos
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Concurrent
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
abstract class AbstractSynchronizer implements ISynchronizer
{
    /**
     * {@inheritdoc}
     */
    public function runConcurrently(callable $action): void
    {
        $this->callConcurrently(static function () use ($action): null {
            $action();
            return null;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function runExclusively(callable $action): void
    {
        $this->callExclusively(static function () use ($action): null {
            $action();
            return null;
        });
    }
}
