<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo\Traits;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Closure;

/**
 * PdoLifecycleHooks - The Write and Removal Hooks of PdoRepository
 *
 * Since 2.11.0, when the class was split into traits (the methods are the ones of 2.10.0, unchanged):
 * beforePut(), afterPut(), beforeRemove() and afterRemove() register the hooks, runHooks() runs them
 * (PdoWriteOperations and PdoRemoveOperations call it).
 *
 * - hooks (D4): beforePut(), afterPut(), beforeRemove(), afterRemove() register callables
 *   `fn(IEntity $entity, PdoRepository $repository): void`, run inside the write's transaction: an
 *   exception in a hook (before or after) rolls the write back and propagates. beforePut runs before
 *   the identifier is generated and the timestamps are stamped; beforeRemove runs for each removal
 *   attempted, afterRemove only when a row was removed. removeAll*() with remove hooks loads the
 *   matching entities, runs the hooks for each and removes them by identifier (without hooks it stays
 *   one statement); putAll() runs beforePut for every entity of a batch, then the writes, each
 *   followed by its afterPut; insertAll() runs beforePut for every entity of a batch, the batch's
 *   INSERT statements, then afterPut for every entity. Hooks run under the repository's synchronizer: with a
 *   non-reentrant one, a hook must not write through the same repository
 *
 * Like every trait of this folder it holds no state and is used only by PdoRepository, whose private
 * properties it reads and, here, writes.
 *
 * @version    2.11.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo\Traits
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
trait PdoLifecycleHooks
{
    /**
     * Registers a callable run before each entity is written by put(), putAll(), update() or
     * insertAll(), inside the transaction; an exception aborts the write (since 1.13.0, D4).
     *
     * @param callable(T, static): void $hook
     * @return static
     */
    public function beforePut(callable $hook): static
    {
        $this->hooks['beforePut'][] = Closure::fromCallable($hook);

        return $this;
    }

    /**
     * Registers a callable run after each entity was written (identifier, version and timestamps
     * set), inside the transaction; an exception rolls the write back (since 1.13.0, D4).
     *
     * @param callable(T, static): void $hook
     * @return static
     */
    public function afterPut(callable $hook): static
    {
        $this->hooks['afterPut'][] = Closure::fromCallable($hook);

        return $this;
    }

    /**
     * Registers a callable run before each removal (remove(), removeAll*(), forceRemove()), inside the
     * transaction; an exception aborts the removal (since 1.13.0, D4).
     *
     * @param callable(T, static): void $hook
     * @return static
     */
    public function beforeRemove(callable $hook): static
    {
        $this->hooks['beforeRemove'][] = Closure::fromCallable($hook);

        return $this;
    }

    /**
     * Registers a callable run after each entity was removed (marked or deleted), inside the
     * transaction; an exception rolls the removal back (since 1.13.0, D4).
     *
     * @param callable(T, static): void $hook
     * @return static
     */
    public function afterRemove(callable $hook): static
    {
        $this->hooks['afterRemove'][] = Closure::fromCallable($hook);

        return $this;
    }

    /**
     * Runs the hooks of an event for an entity.
     *
     * @param 'beforePut'|'afterPut'|'beforeRemove'|'afterRemove' $event
     */
    private function runHooks(string $event, IEntity $entity): void
    {
        foreach ($this->hooks[$event] as $hook) {
            $hook($entity, $this);
        }
    }
}
