<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Resolution\Exceptions;

/**
 * DataSourceNotFoundException - No Data Source Registered Under a Name
 *
 * Raised when an external data leaf names a source that the registry in use does not hold. Under
 * `evaluate()` it becomes the evaluation error of that leaf, never a rule failure.
 *
 * @version    1.10.0
 * @package    Antevemus\ASpecification
 * @subpackage Resolution\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class DataSourceNotFoundException extends DataResolutionException
{
    /**
     * @param string $sourceName Name that is not registered
     */
    public function __construct(private readonly string $sourceName)
    {
        parent::__construct(sprintf('No data source is registered under the name "%s".', $sourceName));
    }

    /** Name that is not registered. */
    public function getSourceName(): string
    {
        return $this->sourceName;
    }
}
