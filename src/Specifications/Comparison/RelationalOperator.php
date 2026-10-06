<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Comparison;

/**
 * RelationalOperator - Enumeration of relational comparison operators.
 *
 * Represents binary relational comparison operators (equality, inequality, magnitude)
 * used in relational specifications, supporting logical inversion and SQL conversion.
 *
 * Features:
 * - Mapping of standard and extended relational binary operators
 * - Strict logical relational inversion (relational algebra)
 * - Canonical SQL-compatible textual symbol representation
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Comparison
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
enum RelationalOperator: string
{
    case EQUAL = '=';
    case NOT_EQUAL = '<>';
    case LESS_THAN = '<';
    case LESS_THAN_OR_EQUAL = '<=';
    case GREATER_THAN = '>';
    case GREATER_THAN_OR_EQUAL = '>=';
    case MUCH_LESS_THAN = '<<';
    case MUCH_GREATER_THAN = '>>';

    /**
     * Returns the inverted binary relation (logical negation of the relational operation).
     *
     * Example:
     * - EQUAL ('=') => NOT_EQUAL ('<>')
     * - LESS_THAN ('<') => GREATER_THAN_OR_EQUAL ('>=')
     *
     * @return self
     */
    public function getInvertedBinaryRelation(): self
    {
        return match ($this) {
            self::EQUAL => self::NOT_EQUAL,
            self::NOT_EQUAL => self::EQUAL,
            self::LESS_THAN => self::GREATER_THAN_OR_EQUAL,
            self::LESS_THAN_OR_EQUAL => self::GREATER_THAN,
            self::GREATER_THAN => self::LESS_THAN_OR_EQUAL,
            self::GREATER_THAN_OR_EQUAL => self::LESS_THAN,
            self::MUCH_LESS_THAN => self::MUCH_GREATER_THAN,
            self::MUCH_GREATER_THAN => self::MUCH_LESS_THAN,
        };
    }

    /**
     * Returns the symbolic operator string compatible with SQL standards.
     *
     * @return string
     */
    public function getSqlSymbol(): string
    {
        return $this->value;
    }
}
