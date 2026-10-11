<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

use InvalidArgumentException;

/**
 * ResolutionPolicy - Cost Policy and Time Limit of a Resolution
 *
 * Immutable. `concurrent()` is the default of DataResolver: it reports every failure, as an
 * evaluation outside the resolver does; `tiered()` is the explicit choice to save calls.
 *
 * <code>
 * $resolver->evaluate($rule, $customer, ResolutionPolicy::concurrent());          // everything at once
 * $resolver->evaluate($rule, $customer, ResolutionPolicy::tiered(timeout: 3.0)); // cheapest first, 3 s overall
 * </code>
 *
 * - concurrent(): every request of the tree in one stage; the fastest wall time, every source paid.
 * - tiered(): one stage per cost (IDataSource::getCost()), cheapest first. After each stage the tree is
 *   evaluated in three-valued logic (true, false, unknown); when the verdict can no longer change
 *   (an `and` with a false operand, an `or` whose left operand is true) the remaining, more
 *   expensive stages are skipped and their sources are not called.
 * - sequential(): nothing fetched beforehand; each leaf fetches when the evaluation reaches it.
 *
 * Time limit: the optional `timeout` bounds the whole resolution, in seconds. Each HTTP call already
 * has the time limit of its source; when the resolution limit is reached first, the calls still
 * running are aborted and the requests not yet made fail, each one an evaluation error of its leaf.
 * There is no retry.
 *
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class ResolutionPolicy
{
    private function __construct(
        private readonly ResolutionMode $mode,
        private readonly ?float $timeout
    ) {
        if ($timeout !== null && (!is_finite($timeout) || $timeout <= 0)) {
            throw new InvalidArgumentException('The time limit of a resolution must be a positive number of seconds.');
        }
    }

    /**
     * Every request of the tree in one stage.
     *
     * @param float|null $timeout Time limit of the whole resolution, in seconds (null: only the sources' own limits)
     */
    public static function concurrent(?float $timeout = null): self
    {
        return new self(ResolutionMode::CONCURRENT, $timeout);
    }

    /**
     * Stages by cost, cheapest first, stopping when the verdict is decided. Same verdict as concurrent(),
     * possibly fewer failures (a skipped branch reports none).
     *
     * @param float|null $timeout Time limit of the whole resolution, in seconds (null: only the sources' own limits)
     */
    public static function tiered(?float $timeout = null): self
    {
        return new self(ResolutionMode::TIERED, $timeout);
    }

    /**
     * Nothing fetched beforehand; each leaf fetches when the evaluation reaches it.
     *
     * @param float|null $timeout Time limit of the whole resolution, in seconds (null: only the sources' own limits)
     */
    public static function sequential(?float $timeout = null): self
    {
        return new self(ResolutionMode::SEQUENTIAL, $timeout);
    }

    /**
     * Copy with another time limit (null removes it).
     */
    public function withTimeout(?float $seconds): self
    {
        return new self($this->mode, $seconds);
    }

    /** Mode of the policy. */
    public function getMode(): ResolutionMode
    {
        return $this->mode;
    }

    /** Time limit of the whole resolution, in seconds, or null. */
    public function getTimeout(): ?float
    {
        return $this->timeout;
    }
}
