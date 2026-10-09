<?php
declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\String;

use Antevemus\ASpecification\AbstractSpecification;

/**
 * EqualIgnoreCaseStringSpecification - Leaf specification for case-insensitive string equality.
 *
 * Compares both strings lowered with `mb_strtolower(..., 'UTF-8')`, so the comparison ignores the
 * case of any Unicode letter (`'ÀGUA'` matches `'água'`, `'ẞ'` matches `'ß'`). Before 1.5.0 the
 * leaf used `strcasecmp()`, which folds ASCII letters only (breaking change RN-03). Requires
 * `ext-mbstring`. The SQL and TCriteria visitors are unchanged: case in the database is a
 * matter of collation.
 *
 * Features:
 * - Unicode case-insensitive string comparison (`mb_strtolower`, UTF-8)
 * - Safe handling of non-string candidates
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @version    1.5.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\String
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class EqualIgnoreCaseStringSpecification extends AbstractSpecification
{
    /**
     * Initializes the specification with the target reference string.
     *
     * @param string $value Target string for comparison
     */
    public function __construct(private readonly string $value)
    {
    }

    /**
     * Returns the target comparison string value.
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Verifies whether the provided candidate satisfies this specification.
     *
     * @param mixed $candidate Target value to validate
     * @return bool True if candidate is a string matching value case-insensitively
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!is_string($candidate)) {
            return false;
        }

        // Unicode-aware (1.5.0, RN-03): 'ÀGUA' matches 'água'; strcasecmp() only folded ASCII.
        return mb_strtolower($candidate, 'UTF-8') === mb_strtolower($this->value, 'UTF-8');
    }

    /**
     * Returns the type of candidate validated by this specification.
     *
     * @return string
     */
    public function getType(): string
    {
        return 'string';
    }
}
