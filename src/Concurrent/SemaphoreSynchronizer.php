<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Concurrent;

use RuntimeException;

/**
 * SemaphoreSynchronizer - Sincronizador Read/Write Lock Baseado em Semáforos Contadores
 *
 * Porta fiel da implementação Java net.sourceforge.domian.util.concurrent.locks.SemaphoreSynchronizer.
 * Utiliza o modelo de contagem de permissões com capacidade alta (10.000 permissões concorrentes)
 * para leituras paralelas não-bloqueantes e aquisição total de permissões para isolamento
 * exclusivo de mutações/escritas, com suporte integral a reentrância.
 *
 * Funcionalidades:
 * - Controle de capacidade de concorrência com 10.000 permissões padrão (MAX_NUMBER_OF_CONCURRENT_PERMITS)
 * - Modo concorrente com consumo unitário de permissões (callConcurrently / runConcurrently)
 * - Modo exclusivo com drenagem total de permissões garantindo acesso atômico único
 * - Tolerância e detecção transparente de reentrância evitando auto-deadlock
 * - Métodos de introspecção estrutural de estado (getAvailablePermits, isExclusiveLocked)
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Concurrent
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class SemaphoreSynchronizer extends AbstractSynchronizer
{
    public const int MAX_NUMBER_OF_CONCURRENT_PERMITS = 10000;

    private readonly int $maxPermits;
    private int $availablePermits;
    private bool $exclusiveLocked = false;
    private int $concurrentDepth = 0;
    private int $exclusiveDepth = 0;

    /**
     * Inicializa o sincronizador com o número máximo de permissões concorrentes.
     *
     * @param int $maxPermits Quantidade máxima de permissões concorrentes simultâneas (padrão 10.000)
     */
    public function __construct(int $maxPermits = self::MAX_NUMBER_OF_CONCURRENT_PERMITS)
    {
        $this->maxPermits = max(1, $maxPermits);
        $this->availablePermits = $this->maxPermits;
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

        $this->acquireConcurrentPermit();
        $this->concurrentDepth++;

        try {
            return $action();
        } finally {
            $this->concurrentDepth--;
            $this->releaseConcurrentPermit();
        }
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

        $this->acquireExclusiveLock();
        $this->exclusiveDepth++;

        try {
            return $action();
        } finally {
            $this->exclusiveDepth--;
            $this->releaseExclusiveLock();
        }
    }

    /**
     * Indica se a thread/contexto corrente já detém permissão de execução (reentrância).
     *
     * @return bool
     */
    public function hasAcquiredPermit(): bool
    {
        return $this->exclusiveDepth > 0 || $this->concurrentDepth > 0;
    }

    /**
     * Retorna a quantidade de permissões concorrentes atualmente disponíveis.
     *
     * @return int
     */
    public function getAvailablePermits(): int
    {
        return $this->availablePermits;
    }

    /**
     * Retorna o total configurado de permissões concorrentes.
     *
     * @return int
     */
    public function getMaxPermits(): int
    {
        return $this->maxPermits;
    }

    /**
     * Indica se o lock exclusivo está ativo no momento.
     *
     * @return bool
     */
    public function isExclusiveLocked(): bool
    {
        return $this->exclusiveLocked;
    }

    /**
     * Adquire 1 permissão concorrente.
     *
     * @return void
     */
    private function acquireConcurrentPermit(): void
    {
        if ($this->exclusiveLocked) {
            throw new RuntimeException("Bloqueio exclusivo ativo: não é possível adquirir permissão concorrente.");
        }

        if ($this->availablePermits <= 0) {
            throw new RuntimeException("Capacidade máxima de permissões concorrentes ({$this->maxPermits}) esgotada.");
        }

        $this->availablePermits--;
    }

    /**
     * Libera 1 permissão concorrente.
     *
     * @return void
     */
    private function releaseConcurrentPermit(): void
    {
        if ($this->availablePermits < $this->maxPermits) {
            $this->availablePermits++;
        }
    }

    /**
     * Adquire lock exclusivo drenando todas as permissões.
     *
     * @return void
     */
    private function acquireExclusiveLock(): void
    {
        if ($this->exclusiveLocked) {
            throw new RuntimeException("Lock exclusivo já adquirido por outra operação.");
        }

        if ($this->availablePermits < $this->maxPermits) {
            throw new RuntimeException("Existem operações concorrentes ativas ({$this->availablePermits}/{$this->maxPermits}): aguarde término antes do lock exclusivo.");
        }

        $this->exclusiveLocked = true;
        $this->availablePermits = 0; // Drena todas as permissões
    }

    /**
     * Libera lock exclusivo restaurando todas as permissões.
     *
     * @return void
     */
    private function releaseExclusiveLock(): void
    {
        $this->exclusiveLocked = false;
        $this->availablePermits = $this->maxPermits; // Restaura permissões
    }
}
