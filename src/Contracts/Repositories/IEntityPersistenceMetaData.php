<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

use DateTimeInterface;

/**
 * IEntityPersistenceMetaData - Contrato de metadados de ciclo de vida e persistência
 *
 * Contrato para metadados de persistência associados a uma entidade (IEntity).
 * Mantém contadores e registros temporais de ciclos de leitura e gravação da entidade
 * em mídias de armazenamento. Não possui identidade própria e não é uma entidade consultável.
 *
 * Funcionalidades:
 * - Contagem total de leituras e gravações
 * - Registro de timestamps da primeira e última leitura
 * - Registro de timestamps da primeira e última gravação
 * - Métodos para registro de eventos de ciclo de vida
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IEntityPersistenceMetaData
{
    /**
     * Retorna a quantidade total de vezes que a entidade foi lida/carregada.
     */
    public function getReadCount(): int;

    /**
     * Retorna a quantidade total de vezes que a entidade foi gravada/persistida.
     */
    public function getWriteCount(): int;

    /**
     * Retorna a data e hora da primeira operação de leitura registrada, ou null se nunca lida.
     */
    public function getFirstRead(): ?DateTimeInterface;

    /**
     * Retorna a data e hora da última operação de leitura registrada, ou null se nunca lida.
     */
    public function getLastRead(): ?DateTimeInterface;

    /**
     * Retorna a data e hora da primeira operação de gravação registrada.
     */
    public function getFirstWrite(): ?DateTimeInterface;

    /**
     * Retorna a data e hora da última operação de gravação registrada.
     */
    public function getLastWrite(): ?DateTimeInterface;

    /**
     * Registra uma ocorrência de leitura da entidade, incrementando o contador
     * e atualizando os timestamps de primeira/última leitura.
     *
     * @param DateTimeInterface|null $timestamp Data/hora do evento (ou atual se null)
     */
    public function registerRead(?DateTimeInterface $timestamp = null): void;

    /**
     * Registra uma ocorrência de gravação da entidade, incrementando o contador
     * e atualizando os timestamps de primeira/última gravação.
     *
     * @param DateTimeInterface|null $timestamp Data/hora do evento (ou atual se null)
     */
    public function registerWrite(?DateTimeInterface $timestamp = null): void;
}
