<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Repositories;

/**
 * IVolatileRepository - Contract for Volatile Repositories
 *
 * A volatile repository does not support durability: all stored entities are lost once execution
 * finishes or the process terminates. Since 1.6.0 (RN-05) the contract also carries an optional
 * time-to-live: withTtl() makes entries expire a fixed number of seconds after their last write and
 * prune() evicts the expired ones. An expired entry is invisible to every read of the IRepository
 * contract (find*, iterate*, count*, findSingle*, contains) whether or not it was evicted yet;
 * implementations evict lazily when a read meets it, or all at once in prune().
 *
 * Breaking for external implementations of this interface that extend neither InMemoryRepository,
 * NullRepository nor VolatilePartitionRepository: implement withTtl() and prune() (a repository
 * without expiry can return $this and 0).
 *
 * @template T of \Antevemus\ASpecification\Contracts\Entities\IEntity
 * @extends IRepository<T>
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IVolatileRepository extends IRepository
{
    /**
     * Sets the time-to-live of the entries, fluently: an entry expires $seconds after its last write
     * (put/update). 0 turns the expiry off.
     *
     * @param int $seconds Time-to-live in seconds (0 = never expire)
     * @return static
     * @throws \InvalidArgumentException When $seconds is negative
     */
    public function withTtl(int $seconds): static;

    /**
     * Evicts every expired entry now.
     *
     * @return int Number of evicted entries (0 when no TTL is set)
     */
    public function prune(): int;
}
