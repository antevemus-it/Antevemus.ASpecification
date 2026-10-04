<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Helpers;

/**
 * IStopWatch - Contrato para cronometros de medicao de tempo e benchmarking
 *
 * Define a interface de alta precisao para medicao de desempenho em execucao
 * de consultas em repositorios, particionamento e avaliacao de especificacoes.
 *
 * Funcionalidades:
 * - Controle de estados (READY, STARTED, STOPPED)
 * - Registro de voltas/intervalos parciais (laps)
 * - Extracao de tempos em nanossegundos, milissegundos e segundos
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Helpers
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IStopWatch
{
    public const STATE_READY = 'READY';
    public const STATE_STARTED = 'STARTED';
    public const STATE_STOPPED = 'STOPPED';

    /**
     * Inicia ou reinicia o cronometro.
     *
     * @return self
     */
    public function start(): self;

    /**
     * Interrompe o cronometro.
     *
     * @return self
     */
    public function stop(): self;

    /**
     * Reseta o cronometro para o estado inicial READY.
     *
     * @return self
     */
    public function reset(): self;

    /**
     * Registra uma volta intermediaria (lap) e retorna a duracao em nanossegundos desde a ultima volta.
     *
     * @return int
     */
    public function lap(): int;

    /**
     * Retorna o estado atual do cronometro.
     *
     * @return string
     */
    public function getState(): string;

    /**
     * Retorna o tempo decorrido total em nanossegundos.
     *
     * @return int
     */
    public function getElapsedNanoseconds(): int;

    /**
     * Retorna o tempo decorrido total em milissegundos.
     *
     * @return float
     */
    public function getElapsedMilliseconds(): float;

    /**
     * Retorna o tempo decorrido total em segundos.
     *
     * @return float
     */
    public function getElapsedSeconds(): float;

    /**
     * Retorna todos os laps registrados em nanossegundos.
     *
     * @return array<int, int>
     */
    public function getLaps(): array;

    /**
     * Retorna uma representacao textual legivel do tempo decorrido (ex: '12.34ms', '1.50s').
     *
     * @return string
     */
    public function formatElapsed(): string;
}
