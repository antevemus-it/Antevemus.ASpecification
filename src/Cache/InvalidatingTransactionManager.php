<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Cache;

use Antevemus\ASpecification\Persistence\ITransactionManager;
use Closure;

/**
 * InvalidatingTransactionManager - The Unit of Work of a Cached Store, Which Invalidates on Its Way Out
 *
 * What CachedFeatureFlagStore::getTransactionManager() returns (internal): it runs the work through the
 * decorated store's manager and counts the depth; when the outermost call returns or throws, the cache
 * keys written to inside it are deleted. ITransactionManager has no post-commit hook, so this is the
 * closest point to the commit the decorator can see: when the manager opened the transaction, the commit
 * already happened; when the work joined a transaction of the caller, that transaction commits later and
 * the caller invalidates again after it (CachedFeatureFlagStore::invalidate()) if other nodes must not
 * read the old value until the TTL ends. On a rollback the keys are deleted too, which is harmless.
 *
 * @internal
 * @version    2.8.0
 * @package    Antevemus\ASpecification
 * @subpackage Cache
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InvalidatingTransactionManager implements ITransactionManager
{
    private int $depth = 0;

    /**
     * @param ITransactionManager $inner The decorated store's manager
     * @param Closure(): void $onExit Deletes the keys written to inside the work
     */
    public function __construct(
        private readonly ITransactionManager $inner,
        private readonly Closure $onExit
    ) {
    }

    /** The decorated store's manager. */
    public function getInner(): ITransactionManager
    {
        return $this->inner;
    }

    /** True while a work runs through this manager. */
    public function isRunning(): bool
    {
        return $this->depth > 0;
    }

    /** {@inheritdoc} */
    public function transactional(Closure $work): mixed
    {
        $this->depth++;
        try {
            return $this->inner->transactional($work);
        } finally {
            if (--$this->depth === 0) {
                ($this->onExit)();
            }
        }
    }
}
