<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Repositories\IEntityPersistenceMetaData;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * EntityPersistenceMetaData - Metadados de persistência de entidade
 *
 * Implementação padrão dos metadados de ciclo de vida e persistência de uma entidade.
 * Mantém histórico temporal e contadores de operações de leitura e gravação.
 *
 * Funcionalidades:
 * - Contagem precisa de leituras e gravações
 * - Rastreamento da primeira e última ocorrência de leitura
 * - Rastreamento da primeira e última ocorrência de gravação
 * - Inicialização com timestamp de criação
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class EntityPersistenceMetaData implements IEntityPersistenceMetaData
{
    private int $readCount = 0;
    private int $writeCount = 0;
    private ?DateTimeInterface $firstRead = null;
    private ?DateTimeInterface $lastRead = null;
    private ?DateTimeInterface $firstWrite = null;
    private ?DateTimeInterface $lastWrite = null;

    /**
     * Construtor de metadados de persistência.
     * Na criação, a data de gravação informada (ou atual) define a primeira e última gravação inicial,
     * enquanto os contadores iniciam em zero.
     *
     * @param DateTimeInterface|null $initialWriteTime Timestamp inicial de gravação/criação
     */
    public function __construct(?DateTimeInterface $initialWriteTime = null)
    {
        $time = $initialWriteTime ?? new DateTimeImmutable();
        $this->firstWrite = $time;
        $this->lastWrite = $time;
    }

    /**
     * {@inheritdoc}
     */
    public function getReadCount(): int
    {
        return $this->readCount;
    }

    /**
     * {@inheritdoc}
     */
    public function getWriteCount(): int
    {
        return $this->writeCount;
    }

    /**
     * {@inheritdoc}
     */
    public function getFirstRead(): ?DateTimeInterface
    {
        return $this->firstRead;
    }

    /**
     * {@inheritdoc}
     */
    public function getLastRead(): ?DateTimeInterface
    {
        return $this->lastRead;
    }

    /**
     * {@inheritdoc}
     */
    public function getFirstWrite(): ?DateTimeInterface
    {
        return $this->firstWrite;
    }

    /**
     * {@inheritdoc}
     */
    public function getLastWrite(): ?DateTimeInterface
    {
        return $this->lastWrite;
    }

    /**
     * {@inheritdoc}
     */
    public function registerRead(?DateTimeInterface $timestamp = null): void
    {
        $time = $timestamp ?? new DateTimeImmutable();
        $this->readCount++;

        if ($this->firstRead === null) {
            $this->firstRead = $time;
        }

        $this->lastRead = $time;
    }

    /**
     * {@inheritdoc}
     */
    public function registerWrite(?DateTimeInterface $timestamp = null): void
    {
        $time = $timestamp ?? new DateTimeImmutable();
        $this->writeCount++;

        if ($this->firstWrite === null) {
            $this->firstWrite = $time;
        }

        $this->lastWrite = $time;
    }
}
