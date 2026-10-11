<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution;

/**
 * SkippedData - Marker for Data the Tiered Policy Did Not Fetch
 *
 * Returned by ResolvedData::valueFor() to a leaf whose request was skipped because the verdict was
 * already decided. The leaf answers with the neutral outcome of its position in the tree (satisfied
 * under an even number of negations, not satisfied under an odd number), so a branch that could
 * not change the verdict adds no failure to the result.
 *
 * @internal
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class SkippedData
{
    /**
     * @param bool $neutral Outcome the leaf reports: true = satisfied, false = not satisfied
     */
    public function __construct(public readonly bool $neutral)
    {
    }
}
