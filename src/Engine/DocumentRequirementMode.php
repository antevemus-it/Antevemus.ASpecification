<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Engine;

/**
 * DocumentRequirementMode - Requirement Mode for Documents in Operational Group
 *
 * Defines document validation semantics for requirement sets in domain operations.
 * Supports universal requirement (all), alternative disjunction (any), or strict exclusivity (one_of_set).
 *
 * Features:
 * - Typed enumeration for document rules (ALL, ANY, ONE_OF_SET)
 * - Safe resolution from strings with configurable default fallback
 * - Support for Boolean specification algebra (And, Or, Xor)
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Engine
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum DocumentRequirementMode: string
{
    case ALL = 'all';
    case ANY = 'any';
    case ONE_OF_SET = 'one_of_set';

    /**
     * Resolves the mode from an input string, returning a default fallback if invalid.
     *
     * @param string|null $mode Rule text mode (e.g. 'all', 'any', 'one_of_set')
     * @param self $default Default mode if value is null or unrecognized
     * @return self
     */
    public static function fromOrDefault(?string $mode, self $default = self::ALL): self
    {
        if ($mode === null || trim($mode) === '') {
            return $default;
        }

        return self::tryFrom(strtolower(trim($mode))) ?? $default;
    }
}
