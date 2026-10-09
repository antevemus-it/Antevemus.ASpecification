<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\Repositories\IVolatileRepository;

/**
 * VolatilePartitionRepository - Volatile partitioned entity repository
 *
 * Specialization of PartitionRepository preserving the IVolatileRepository contract.
 *
 * Features:
 * - Strict implementation of IVolatileRepository
 * - Semantic preservation of in-memory repository partitions
 * - Lazy iterate*() inherited from PartitionRepository: the node and each partition are consumed
 *   through their own generators, nothing is materialized before the first entity (1.4.4, checked
 *   by the 1.5.0 RN-04 suite)
 * - Time-to-live over the whole graph (1.6.0, RN-05): withTtl() sets the TTL of the volatile repository
 *   of this node and of every descendant partition; partitions added later through addPartition() or
 *   addPartitionWithId() inherit it (and the clock) from their parent's repository. prune() evicts the
 *   expired entries of every repository of the subtree once. An entity copied or migrated into another
 *   partition is a write into that repository, so its TTL restarts there.
 *
 * @template T of IEntity
 * @extends PartitionRepository<T>
 * @implements IVolatileRepository<T>
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class VolatilePartitionRepository extends PartitionRepository implements IVolatileRepository
{
    /**
     * {@inheritdoc}
     *
     * Applies to the repository of this node and of every descendant partition that is volatile.
     *
     * @throws \InvalidArgumentException When $seconds is negative
     */
    public function withTtl(int $seconds): static
    {
        if ($seconds < 0) {
            throw new \InvalidArgumentException("TTL must be zero (no expiry) or a positive number of seconds, {$seconds} given.");
        }

        foreach ($this->volatileRepositoriesOfSubtree() as $repository) {
            $repository->withTtl($seconds);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * Sums the evictions of every volatile repository of the subtree, each repository once.
     */
    public function prune(): int
    {
        $pruned = 0;
        foreach ($this->volatileRepositoriesOfSubtree() as $repository) {
            $pruned += $repository->prune();
        }

        return $pruned;
    }

    /**
     * The volatile repositories of this node and of its descendant partitions, each once.
     *
     * @return array<IVolatileRepository<T>>
     */
    private function volatileRepositoriesOfSubtree(): array
    {
        $nodes = $this->getAllPartitions();
        array_unshift($nodes, $this);

        $result = [];
        foreach ($nodes as $node) {
            $repository = $node->getUnderlyingRepository();
            if ($repository instanceof IVolatileRepository && !in_array($repository, $result, true)) {
                $result[] = $repository;
            }
        }

        return $result;
    }
}
