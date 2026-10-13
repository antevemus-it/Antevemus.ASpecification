<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags\Exceptions;

/**
 * StaleFeatureFlagException - The Flag Was Not in the Expected State
 *
 * A change is a compare-and-set (forward 034, D5): turning a flag on expects it off, turning it off
 * expects it on, adding a subject expects it absent from the list, removing one expects it present.
 * When the stored state is already the target (someone else changed it since the screen was loaded,
 * or the same request is repeated) nothing is written, no history row is added and this exception
 * is thrown: reload and decide again.
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class StaleFeatureFlagException extends FeatureFlagException
{
    /**
     * @param string $flagCode Code of the flag
     * @param string $message Human-readable message
     */
    public function __construct(private readonly string $flagCode, string $message)
    {
        parent::__construct($message);
    }

    /** Code of the flag whose state was not the expected one. */
    public function getFlagCode(): string
    {
        return $this->flagCode;
    }
}
