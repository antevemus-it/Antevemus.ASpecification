<?php
declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

use Antevemus\ASpecification\AbstractSpecification;

/**
 * NotNullSpecification - Leaf specification validating that the candidate is not null.
 *
 * Checks directly whether the candidate value or object is strictly different from null.
 *
 * Features:
 * - Direct non-null verification (`!== null`)
 * - Universal candidate acceptance across types ('mixed')
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class NotNullSpecification extends AbstractSpecification
{
    /**
     * Verifies whether the provided candidate satisfies the non-null rule.
     *
     * @param mixed $candidate The value or object to validate
     * @return bool True if candidate is not null, false otherwise
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $candidate !== null;
    }

    /**
     * Returns the type of candidate validated by this specification.
     *
     * @return string Returns 'mixed' as this leaf specification accepts any type
     */
    public function getType(): string
    {
        return 'mixed';
    }
}
