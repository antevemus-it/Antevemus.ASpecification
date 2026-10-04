<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

/**
 * PersistenceDefinition - Modalidades de persistência de repositórios
 *
 * Define as modalidades e características de persistência para repositórios
 * do ecossistema de especificações (Specification Pattern).
 *
 * Funcionalidades:
 * - Identificação de persistência exclusiva em arquivo (FileOnly)
 * - Identificação de persistência delegada a terceiros (DelegatedOnly)
 * - Identificação de persistência puramente em memória (MemoryOnly)
 * - Suporte a persistência assíncrona periódica em arquivo (MemoryAsyncFile)
 * - Suporte a persistência assíncrona delegada (MemoryAsyncDelegated)
 * - Verificação de características (baseado em arquivo, memória, assíncrono)
 *
 * @version    0.1
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum PersistenceDefinition: string
{
    /** Repositório que persiste exclusivamente em arquivos físicos no disco. */
    case FileOnly = 'FILE_ONLY';

    /** Repositório cuja persistência é delegada a um mecanismo externo. */
    case DelegatedOnly = 'DELEGATED_ONLY';

    /** Repositório puramente em memória, volátil. */
    case MemoryOnly = 'MEMORY_ONLY';

    /** Repositório transitório não persistido. */
    case Transient = 'TRANSIENT';

    /** Repositório em memória com gravação assíncrona periódica em arquivo. */
    case MemoryAsyncFile = 'MEMORY_ASYNC_FILE';

    /** Repositório em memória com gravação assíncrona delegada a mecanismo externo. */
    case MemoryAsyncDelegated = 'MEMORY_ASYNC_DELEGATED';

    /** Repositório persistente em arquivo com leitura e gravação síncronas habilitadas. */
    case ReadWrite = 'READ_WRITE';

    /** Repositório persistente em arquivo restrito exclusivamente a operações de leitura. */
    case ReadOnly = 'READ_ONLY';

    /** Repositório persistente em arquivo restrito exclusivamente a operações de gravação. */
    case WriteOnly = 'WRITE_ONLY';

    /** Repositório persistente que sincroniza com o disco em modo snapshot (load/store/close). */
    case Snapshot = 'SNAPSHOT';

    /**
     * Cria definição em modo Somente Leitura (ReadOnly).
     */
    public static function createReadOnly(): self
    {
        return self::ReadOnly;
    }

    /**
     * Cria definição em modo Somente Escrita (WriteOnly).
     */
    public static function createWriteOnly(): self
    {
        return self::WriteOnly;
    }

    /**
     * Cria definição em modo Leitura e Escrita (ReadWrite).
     */
    public static function createReadWrite(): self
    {
        return self::ReadWrite;
    }

    /**
     * Cria definição em modo Snapshot.
     */
    public static function createSnapshot(): self
    {
        return self::Snapshot;
    }

    /**
     * Indica se a definição bloqueia gravações (ReadOnly).
     */
    public function isReadOnly(): bool
    {
        return $this === self::ReadOnly;
    }

    /**
     * Indica se a definição bloqueia leituras (WriteOnly).
     */
    public function isWriteOnly(): bool
    {
        return $this === self::WriteOnly;
    }

    /**
     * Indica se a persistência é baseada em arquivo físico.
     */
    public function isFileBased(): bool
    {
        return $this === self::FileOnly
            || $this === self::MemoryAsyncFile
            || $this === self::ReadWrite
            || $this === self::ReadOnly
            || $this === self::WriteOnly
            || $this === self::Snapshot;
    }

    /**
     * Indica se a persistência ocorre exclusivamente em arquivo.
     */
    public function isFileOnly(): bool
    {
        return $this === self::FileOnly;
    }

    /**
     * Indica se o repositório opera primariamente em memória.
     */
    public function isMemoryBased(): bool
    {
        return $this === self::MemoryOnly || $this === self::MemoryAsyncFile || $this === self::MemoryAsyncDelegated;
    }

    /**
     * Indica se o repositório opera exclusivamente em memória.
     */
    public function isMemoryOnly(): bool
    {
        return $this === self::MemoryOnly;
    }

    /**
     * Indica se a persistência é delegada a mecanismo externo.
     */
    public function isDelegated(): bool
    {
        return $this === self::DelegatedOnly || $this === self::MemoryAsyncDelegated;
    }

    /**
     * Indica se a persistência é exclusivamente delegada a mecanismo externo.
     */
    public function isDelegatedOnly(): bool
    {
        return $this === self::DelegatedOnly;
    }

    /**
     * Indica se suporta persistência assíncrona em segundo plano.
     */
    public function isAsyncSupported(): bool
    {
        return $this === self::MemoryAsyncFile || $this === self::MemoryAsyncDelegated;
    }

    /**
     * Alias semântico para isAsyncSupported.
     */
    public function supportsAsyncPersistence(): bool
    {
        return $this->isAsyncSupported();
    }
}
