<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags;

use Throwable;

/**
 * IFeatureFlagErrorReporter - Where a Flag That Could Not Be Read Is Reported
 *
 * FeatureFlags fails closed (forward 034, D9): when the store cannot be read, or the target of a flag
 * cannot be evaluated, the flag is off with the reason ERROR, and the failure is handed here, so that
 * it is not silent. Plug a logger (a PSR-3 adapter belongs to the application: the library has no
 * runtime dependency for it). A failed load is reported once per load, not once per evaluation: the
 * failure is kept until refresh(). An exception thrown by the reporter itself is ignored, so a broken
 * logger never changes the value of a flag.
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface IFeatureFlagErrorReporter
{
    /**
     * Reports a failure.
     *
     * @param string|null $code Flag being evaluated when it happened (null = a load outside an evaluation)
     * @param Throwable $error The failure (its message may carry data of the database: log it, do not show it)
     */
    public function report(?string $code, Throwable $error): void;
}
