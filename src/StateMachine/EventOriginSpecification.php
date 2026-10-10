<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\StateMachine;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;
use InvalidArgumentException;

/**
 * EventOriginSpecification - Built-in Guard `eventOrigin('internal'|'external')`
 *
 * Satisfied when the event has the given origin (forward 023, D19). Transitions declared
 * `internal` and deadline events already enforce the internal origin implicitly; this guard is
 * for finer rules (e.g. "only the system may take this path when ...").
 *
 * Algebra: two leaves with different origins are disjoint.
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
final class EventOriginSpecification extends AbstractSpecification implements ILeafSpecification
{
    /**
     * @param EventOrigin $origin Expected origin
     */
    public function __construct(private readonly EventOrigin $origin)
    {
    }

    /** Expected origin. */
    public function getOrigin(): EventOrigin
    {
        return $this->origin;
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
            throw new InvalidArgumentException('eventOrigin guards evaluate a TransitionCandidate.');
        }

        return $candidate->event->origin === $this->origin;
    }

    /** {@inheritdoc} */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        $other = SpecificationAlgebra::resolve($otherSpecification);
        if ($other instanceof self) {
            return $other->origin !== $this->origin;
        }

        return SpecificationAlgebra::baseDisjoint($this, $otherSpecification);
    }

    /** {@inheritdoc} */
    protected function getDefaultFailureMessage(mixed $candidate): string
    {
        return sprintf("The event origin must be '%s'.", $this->origin->value);
    }

    /** {@inheritdoc} */
    public function __toString(): string
    {
        return sprintf("eventOrigin('%s')", $this->origin->value);
    }
}
