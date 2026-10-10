<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;
use InvalidArgumentException;

/**
 * InStateSpecification - Built-in Guard `inState('region.state')`
 *
 * Satisfied when the snapshot BEFORE the event (RN-04) has the region in the state. It is how a
 * guard reads another region (forward 023, D2): `not(inState('pending.open'))` blocks progress
 * while a pending item is open.
 *
 * Algebra: two inState leaves of the same region and different states are disjoint (a region has
 * exactly one current state), which puts this leaf in the validator's decidable fragment.
 *
 * @extends AbstractSpecification<TransitionCandidate>
 * @implements ILeafSpecification<TransitionCandidate>
 * @version    1.8.0
 * @package    Antevemus\ASpecification
 * @subpackage StateMachine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class InStateSpecification extends AbstractSpecification implements ILeafSpecification
{
    /**
     * @param string $region Region name
     * @param string $state State name
     */
    public function __construct(
        private readonly string $region,
        private readonly string $state
    ) {
    }

    /**
     * Creates the leaf from a "region.state" path.
     *
     * @param string $path "region.state"
     * @return self
     * @throws InvalidArgumentException When the path is not "region.state"
     */
    public static function fromPath(string $path): self
    {
        $parts = explode('.', $path);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException(sprintf('inState expects "region.state" (got "%s").', $path));
        }

        return new self($parts[0], $parts[1]);
    }

    /** Region name. */
    public function getRegion(): string
    {
        return $this->region;
    }

    /** State name. */
    public function getState(): string
    {
        return $this->state;
    }

    /** {@inheritdoc} */
    public function getType(): string
    {
        return TransitionCandidate::class;
    }

    /**
     * {@inheritdoc}
     *
     * @throws InvalidArgumentException When the candidate is not a TransitionCandidate
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!$candidate instanceof TransitionCandidate) {
            throw new InvalidArgumentException('inState guards evaluate a TransitionCandidate.');
        }

        return $candidate->snapshot->isIn($this->region, $this->state);
    }

    /** {@inheritdoc} */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        $other = SpecificationAlgebra::resolve($otherSpecification);
        if ($other instanceof self && $other->region === $this->region) {
            return $other->state !== $this->state;
        }

        return SpecificationAlgebra::baseDisjoint($this, $otherSpecification);
    }

    /** {@inheritdoc} */
    protected function getDefaultFailureMessage(mixed $candidate): string
    {
        $current = $candidate instanceof TransitionCandidate
            ? ($candidate->snapshot->getStates()[$this->region] ?? '?')
            : '?';

        return sprintf("Region '%s' is not in state '%s' (current: '%s').", $this->region, $this->state, $current);
    }

    /** {@inheritdoc} */
    public function __toString(): string
    {
        return sprintf("inState('%s.%s')", $this->region, $this->state);
    }
}
