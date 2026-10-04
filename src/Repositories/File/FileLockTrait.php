<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\File;

use Antevemus\ASpecification\Contracts\Repositories\Exceptions\RepositoryException;

/**
 * FileLockTrait - Trait para manipulação segura e não-bloqueante de locks de arquivo
 *
 * Encapsula chamadas ao flock() do PHP provendo suporte a bloqueios compartilhados
 * (LOCK_SH para leitura) e exclusivos (LOCK_EX para escrita), com retry loop
 * e timeout configurável para prevenir travamento perpétuo.
 *
 * Funcionalidades:
 * - Execução atômica sob lock exclusivo (withExclusiveLock)
 * - Execução segura sob lock compartilhado (withSharedLock)
 * - Tentativas com backoff em microssegundos e timeout
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\File
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait FileLockTrait
{
    /**
     * Timeout padrão em milissegundos para obtenção de lock.
     */
    protected int $lockTimeoutMs = 3000;

    /**
     * Executa um callback protegido por bloqueio exclusivo (LOCK_EX).
     *
     * @template R
     * @param string $lockFilePath Caminho do arquivo ou recurso de lock
     * @param callable(): R $callback Função a ser executada com o lock
     * @return R
     * @throws RepositoryException
     */
    protected function withExclusiveLock(string $lockFilePath, callable $callback): mixed
    {
        return $this->executeWithLock($lockFilePath, LOCK_EX, $callback);
    }

    /**
     * Executa um callback protegido por bloqueio compartilhado (LOCK_SH).
     *
     * @template R
     * @param string $lockFilePath Caminho do arquivo ou recurso de lock
     * @param callable(): R $callback Função a ser executada com o lock
     * @return R
     * @throws RepositoryException
     */
    protected function withSharedLock(string $lockFilePath, callable $callback): mixed
    {
        return $this->executeWithLock($lockFilePath, LOCK_SH, $callback);
    }

    /**
     * Executa a rotina com flock respeitando tentativas e timeout.
     *
     * @template R
     * @param string $lockFilePath
     * @param int $lockType LOCK_EX ou LOCK_SH
     * @param callable(): R $callback
     * @return R
     * @throws RepositoryException
     */
    private function executeWithLock(string $lockFilePath, int $lockType, callable $callback): mixed
    {
        $handle = @fopen($lockFilePath, "c+");
        if ($handle === false) {
            throw new RepositoryException("Não foi possível abrir o descritor de lock para o arquivo: {$lockFilePath}");
        }

        $startTime = microtime(true);
        $acquired = false;

        while ((microtime(true) - $startTime) * 1000 < $this->lockTimeoutMs) {
            if (flock($handle, $lockType | LOCK_NB)) {
                $acquired = true;
                break;
            }
            usleep(10000); // aguarda 10ms
        }

        if (!$acquired) {
            fclose($handle);
            throw new RepositoryException("Timeout de {$this->lockTimeoutMs}ms excedido ao tentar obter lock para: {$lockFilePath}");
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
