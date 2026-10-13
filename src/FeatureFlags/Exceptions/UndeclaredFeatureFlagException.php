<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\FeatureFlags\Exceptions;

/**
 * UndeclaredFeatureFlagException - The Flag Is Not Declared in the Registry
 *
 * Turning on (or targeting) a flag that no code declares would have no effect: nothing reads it
 * (forward 034, D5). Turning it off and removing its subjects stay allowed, so that an orphan row of
 * the database can be switched off.
 *
 * @version    2.1.0
 * @package    Antevemus\ASpecification
 * @subpackage FeatureFlags\Exceptions
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class UndeclaredFeatureFlagException extends FeatureFlagException
{
    /**
     * @param string $flagCode Code of the flag
     * @param string $message Human-readable message
     */
    public function __construct(private readonly string $flagCode, string $message)
    {
        parent::__construct($message);
    }

    /** Code of the undeclared flag. */
    public function getFlagCode(): string
    {
        return $this->flagCode;
    }
}
