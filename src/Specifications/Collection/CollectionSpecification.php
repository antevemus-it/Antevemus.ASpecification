<?php
declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Collection;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;

/**
 * CollectionSpecification - Leaf specification validating items within an iterable collection.
 *
 * Iterates over an iterable candidate (arrays or Traversable instances) and asserts
 * that ALL contained items satisfy the embedded item specification.
 *
 * Features:
 * - Universal iterable validation (arrays and Traversables)
 * - Short-circuit evaluation on the first unsatisfied item
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Collection
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class CollectionSpecification extends AbstractSpecification
{
    /**
     * Initializes the collection specification with an item rule.
     *
     * @param ISpecification<mixed> $itemSpecification Rule applied to each item in the collection
     */
    public function __construct(private readonly ISpecification $itemSpecification)
    {
    }

    /**
     * Verifies whether the provided candidate satisfies this collection specification.
     *
     * @param mixed $candidate Target iterable to validate
     * @return bool True if candidate is iterable and all items satisfy the rule
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!is_iterable($candidate)) {
            return false;
        }

        foreach ($candidate as $item) {
            if (!$this->itemSpecification->isSatisfiedBy($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns the type of candidate validated by this specification.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'iterable';
    }
}
