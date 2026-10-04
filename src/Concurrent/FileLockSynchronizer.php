<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Concurrent;

use RuntimeException;
use Throwable;

/**
 * FileLockSynchronizer - Sincronizador de Concorrência Multi-Processo via File Locks (flock)
 *
 * Implementa o padrão Read/Write Lock sobre o sistema de arquivos utilizando a primitiva
 * nativa flock() do sistema operacional. Suporta bloqueios compartilhados (LOCK_SH) para
 * leituras concorrentes em paralelo e bloqueios exclusivos (LOCK_EX) para escritas atômicas,
 * com detecção de reentrância para prevenir auto-deadlocks.
 *
 * Funcionalidades:
 * - Suporte a bloqueios compartilhados (LOCK_SH) e exclusivos (LOCK_EX)
 * - Rastreamento de reentrância para chamadas aninhadas no mesmo processo
 * - Liberação garantida de descritores e travas via blocos try/finally
 * - Timeout configurável com tentativas não-bloqueantes (LOCK_NB) e backoff
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Concurrent
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class FileLockSynchronizer extends AbstractSynchronizer
{
    private readonly string $lockFilePath;
    private readonly int $lockTimeoutMs;
    private int $concurrentDepth = 0;
    private int $exclusiveDepth = 0;

    /**
     * Inicializa o sincronizador com o arquivo de lock de destino.
     *
     * @param string|null $lockFilePath Caminho para o arquivo de trava (null para arquivo temporário padrão)
     * @param int $lockTimeoutMs Tempo limite em milissegundos para obtenção do lock (padrão 3000ms)
     */
    public function __construct(?string $lockFilePath = null, int $lockTimeoutMs = 3000)
    {
        $this->lockFilePath = $lockFilePath ?? (sys_get_temp_dir() . '/aspecification_concurrency.lock');
        $this->lockTimeoutMs = max(100, $lockTimeoutMs);
    }

    /**
     * {@inheritdoc}
     */
    public function callConcurrently(callable $action): mixed
    {
        // Reentrância: se já detém o lock exclusivo ou concorrente, executa diretamente
        if ($this->exclusiveDepth > 0 || $this->concurrentDepth > 0) {
            $this->concurrentDepth++;
            try {
                return $action();
            } finally {
                $this->concurrentDepth--;
            }
        }

        return $this->executeWithLock(LOCK_SH, function () use ($action): mixed {
            $this->concurrentDepth++;
            try {
                return $action();
            } finally {
                $this->concurrentDepth--;
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function callExclusively(callable $action): mixed
    {
        // Reentrância: se já detém o lock exclusivo, executa diretamente
        if ($this->exclusiveDepth > 0) {
            $this->exclusiveDepth++;
            try {
                return $action();
            } finally {
                $this->exclusiveDepth--;
            }
        }

        return $this->executeWithLock(LOCK_EX, function () use ($action): mixed {
            $this->exclusiveDepth++;
            try {
                return $action();
            } finally {
                $this->exclusiveDepth--;
            }
        });
    }

    /**
     * Executa a ação adquirindo e liberando a trava no arquivo com timeout.
     *
     * @template T
     * @param int $lockType LOCK_SH ou LOCK_EX
     * @param callable(): T $action
     * @return T
     * @throws RuntimeException
     */
    private function executeWithLock(int $lockType, callable $action): mixed
    {
        $handle = @fopen($this->lockFilePath, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Não foi possível abrir o arquivo de sincronização: {$this->lockFilePath}");
        }

        $startTime = microtime(true);
        $acquired = false;

        while ((microtime(true) - $startTime) * 1000 < $this->lockTimeoutMs) {
            if (flock($handle, $lockType | LOCK_NB)) {
                $acquired = true;
                break;
            }
            usleep(5000); // 5ms backoff
        }

        if (!$acquired) {
            fclose($handle);
            throw new RuntimeException(
                "Timeout de {$this->lockTimeoutMs}ms excedido ao tentar obter lock para: {$this->lockFilePath}"
            );
        }

        try {
            return $action();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Retorna o caminho do arquivo de lock utilizado.
     *
     * @return string
     */
    public function getLockFilePath(): string
    {
        return $this->lockFilePath;
    }
}
