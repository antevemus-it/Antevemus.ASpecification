<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

/**
 * PersistenceDefinition - Persistence Modalities and Capabilities for Repositories
 *
 * Defines persistence modalities and operational characteristics for repositories
 * within the Specification Pattern ecosystem.
 *
 * Features:
 * - Identification of physical file-only storage (FileOnly)
 * - Identification of delegated third-party persistence (DelegatedOnly)
 * - Identification of volatile purely in-memory persistence (MemoryOnly)
 * - Support for periodic asynchronous file persistence (MemoryAsyncFile)
 * - Support for delegated asynchronous persistence (MemoryAsyncDelegated)
 * - Introspection of storage characteristics (file-based, memory-based, asynchronous)
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum PersistenceDefinition: string
{
    /** Repository persisting exclusively to physical disk files. */
    case FileOnly = 'FILE_ONLY';

    /** Repository whose persistence is delegated to an external mechanism. */
    case DelegatedOnly = 'DELEGATED_ONLY';

    /** Purely in-memory, volatile repository. */
    case MemoryOnly = 'MEMORY_ONLY';

    /** Transient, non-persisted repository. */
    case Transient = 'TRANSIENT';

    /** In-memory repository with periodic asynchronous file recording. */
    case MemoryAsyncFile = 'MEMORY_ASYNC_FILE';

    /** In-memory repository with asynchronous persistence delegated externally. */
    case MemoryAsyncDelegated = 'MEMORY_ASYNC_DELEGATED';

    /** Persistent file repository with synchronous read and write enabled. */
    case ReadWrite = 'READ_WRITE';

    /** Persistent file repository restricted exclusively to read operations. */
    case ReadOnly = 'READ_ONLY';

    /** Persistent file repository restricted exclusively to write operations. */
    case WriteOnly = 'WRITE_ONLY';

    /** Persistent repository synchronizing with disk in snapshot mode (load/store/close). */
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
     * Indicates whether persistence is based on physical disk files.
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
     * Indicates whether persistence occurs exclusively via physical files.
     */
    public function isFileOnly(): bool
    {
        return $this === self::FileOnly;
    }

    /**
     * Indicates whether repository operates primarily in-memory.
     */
    public function isMemoryBased(): bool
    {
        return $this === self::MemoryOnly || $this === self::MemoryAsyncFile || $this === self::MemoryAsyncDelegated;
    }

    /**
     * Indicates whether repository operates exclusively in-memory.
     */
    public function isMemoryOnly(): bool
    {
        return $this === self::MemoryOnly;
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
     * Indicates whether asynchronous background persistence is supported.
     */
    public function isAsyncSupported(): bool
    {
        return $this === self::MemoryAsyncFile || $this === self::MemoryAsyncDelegated;
    }

    /**
     * Semantic alias for isAsyncSupported.
     */
    public function supportsAsyncPersistence(): bool
    {
        return $this->isAsyncSupported();
    }
}
