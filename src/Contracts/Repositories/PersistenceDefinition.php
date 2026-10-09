<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

/**
 * PersistenceDefinition - Persistence Modalities and Capabilities for Repositories
 *
 * Defines persistence modalities and operational characteristics for repositories
 * within the Specification Pattern ecosystem.
 *
 * The five Domian cases (net.sourceforge.domian.repository.PersistenceDefinition, Copyright 2006-2010
 * the original author or authors, Apache License 2.0; see THIRD_PARTY_NOTICES.md) map directly:
 * FILE -> FileOnly, DELEGATED -> DelegatedOnly, INMEMORY -> MemoryOnly, INMEMORY_AND_FILE -> MemoryAsyncFile,
 * INMEMORY_AND_DELEGATED -> MemoryAsyncDelegated. The five extra cases of this library are the modes the
 * file repositories actually run in, and answer the predicates by the Domian definition they implement:
 * - Transient: no persistence at all, like INMEMORY ("should really be denominated NO_PERSISTENCE");
 * - ReadWrite, ReadOnly, WriteOnly: every operation goes to the file, like FILE, with an access restriction;
 * - Snapshot: in-memory working area synchronized to disk at load()/store()/close(), like INMEMORY_AND_FILE.
 *
 * Features:
 * - Identification of physical file-only storage (FileOnly)
 * - Identification of delegated third-party persistence (DelegatedOnly)
 * - Identification of volatile purely in-memory persistence (MemoryOnly)
 * - Support for periodic asynchronous file persistence (MemoryAsyncFile)
 * - Support for delegated asynchronous persistence (MemoryAsyncDelegated)
 * - Introspection of storage characteristics (file-based, memory-based, asynchronous)
 *
 * @version    1.4.4
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum PersistenceDefinition: string
{
    /** Repository persisting exclusively to physical disk files (Domian FILE). */
    case FileOnly = 'FILE_ONLY';

    /** Repository whose persistence is delegated to an external mechanism (Domian DELEGATED). */
    case DelegatedOnly = 'DELEGATED_ONLY';

    /** Purely in-memory, volatile repository (Domian INMEMORY). */
    case MemoryOnly = 'MEMORY_ONLY';

    /** Transient, non-persisted repository: in-memory only, nothing ever reaches the disk (Domian INMEMORY). */
    case Transient = 'TRANSIENT';

    /** In-memory repository with periodic asynchronous file recording (Domian INMEMORY_AND_FILE). */
    case MemoryAsyncFile = 'MEMORY_ASYNC_FILE';

    /** In-memory repository with asynchronous persistence delegated externally (Domian INMEMORY_AND_DELEGATED). */
    case MemoryAsyncDelegated = 'MEMORY_ASYNC_DELEGATED';

    /** Persistent file repository with synchronous read and write enabled (Domian FILE). */
    case ReadWrite = 'READ_WRITE';

    /** Persistent file repository restricted exclusively to read operations (Domian FILE, read-only). */
    case ReadOnly = 'READ_ONLY';

    /** Persistent file repository restricted exclusively to write operations (Domian FILE, write-only). */
    case WriteOnly = 'WRITE_ONLY';

    /** Persistent repository synchronizing with disk in snapshot mode (load/store/close) (Domian INMEMORY_AND_FILE). */
    case Snapshot = 'SNAPSHOT';

    /**
     * Creates definition in ReadOnly mode.
     */
    public static function createReadOnly(): self
    {
        return self::ReadOnly;
    }

    /**
     * Creates definition in WriteOnly mode.
     */
    public static function createWriteOnly(): self
    {
        return self::WriteOnly;
    }

    /**
     * Creates definition in ReadWrite mode.
     */
    public static function createReadWrite(): self
    {
        return self::ReadWrite;
    }

    /**
     * Creates definition in Snapshot mode.
     */
    public static function createSnapshot(): self
    {
        return self::Snapshot;
    }

    /**
     * Indicates whether definition blocks write operations (ReadOnly).
     */
    public function isReadOnly(): bool
    {
        return $this === self::ReadOnly;
    }

    /**
     * Indicates whether definition blocks read operations (WriteOnly).
     */
    public function isWriteOnly(): bool
    {
        return $this === self::WriteOnly;
    }

    /**
     * Indicates whether persistence is based on physical disk files: file-only, or in-memory with
     * asynchronous file persistence (Domian isFileBased()).
     */
    public function isFileBased(): bool
    {
        return $this->isFileOnly()
            || $this === self::MemoryAsyncFile
            || $this === self::Snapshot;
    }

    /**
     * Indicates whether persistence occurs exclusively via physical files, every operation reaching the
     * disk (Domian FILE): FileOnly and the access-restricted file modes ReadWrite, ReadOnly, WriteOnly.
     */
    public function isFileOnly(): bool
    {
        return $this === self::FileOnly
            || $this === self::ReadWrite
            || $this === self::ReadOnly
            || $this === self::WriteOnly;
    }

    /**
     * Java name of isFileOnly().
     */
    public function isFileBasedOnly(): bool
    {
        return $this->isFileOnly();
    }

    /**
     * Indicates whether the repository's working area is memory: memory-only, or memory with
     * asynchronous persistence (Domian isMemoryBased()).
     */
    public function isMemoryBased(): bool
    {
        return $this->isMemoryOnly() || $this->isAsyncSupported();
    }

    /**
     * Indicates whether the repository operates exclusively in memory, nothing ever persisted
     * (Domian INMEMORY): MemoryOnly and Transient.
     */
    public function isMemoryOnly(): bool
    {
        return $this === self::MemoryOnly || $this === self::Transient;
    }

    /**
     * Java name of isMemoryOnly().
     */
    public function isMemoryBasedOnly(): bool
    {
        return $this->isMemoryOnly();
    }

    /**
     * Indicates whether the working area is NOT memory (Domian isNotMemoryBased()): every operation
     * goes straight to the file or to the delegated mechanism.
     */
    public function isNotMemoryBased(): bool
    {
        return !$this->isMemoryBased();
    }

    /**
     * Indicates whether persistence is delegated to an external mechanism.
     */
    public function isDelegated(): bool
    {
        return $this === self::DelegatedOnly || $this === self::MemoryAsyncDelegated;
    }

    /**
     * Indicates whether persistence is exclusively delegated to an external mechanism.
     */
    public function isDelegatedOnly(): bool
    {
        return $this === self::DelegatedOnly;
    }

    /**
     * Indicates whether asynchronous (write-behind) persistence is supported: the working area is memory
     * and the store is written at chosen points in time (Domian supportsAsynchronousPersistence()):
     * MemoryAsyncFile, MemoryAsyncDelegated and Snapshot.
     */
    public function isAsyncSupported(): bool
    {
        return $this === self::MemoryAsyncFile
            || $this === self::MemoryAsyncDelegated
            || $this === self::Snapshot;
    }

    /**
     * Semantic alias for isAsyncSupported.
     */
    public function supportsAsyncPersistence(): bool
    {
        return $this->isAsyncSupported();
    }

    /**
     * Java name of isAsyncSupported().
     */
    public function supportsAsynchronousPersistence(): bool
    {
        return $this->isAsyncSupported();
    }
}
