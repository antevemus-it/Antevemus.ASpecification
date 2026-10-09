<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Tests\Support;

use Antevemus\ASpecification\AbstractSpecification;
use Closure;

/**
 * Test specification that counts its evaluations (RN-04 of 1.5.0): proves that a lazy source only
 * evaluates the entities a consumer actually pulls.
 *
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Tests\Support
 */
final class CountingSpecification extends AbstractSpecification
{
    public int $evaluations = 0;

    /**
     * @param Closure(mixed): bool $predicate
     */
    public function __construct(private readonly Closure $predicate)
    {
    }

    public function isSatisfiedBy(mixed $candidate): bool
    {
        $this->evaluations++;
        return ($this->predicate)($candidate);
    }

    public function getType(): string
    {
        return 'mixed';
    }
}
