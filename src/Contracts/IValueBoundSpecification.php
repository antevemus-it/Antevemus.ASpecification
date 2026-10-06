<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts;

/**
 * IValueBoundSpecification - Interface for specifications bound to a reference value.
 *
 * Part of the Evans/Fowler Specifications pattern.
 *
 * Represents a binary relational specification bound to a specific reference operand.
 * The candidate passed to isSatisfiedBy() acts as the second operand in the evaluation.
 *
 * An IValueBoundSpecification encapsulates:
 * - A reference value (accessible via getValue())
 * - A comparison or validation operation
 * - A candidate target (provided to isSatisfiedBy())
 *
 * Features:
 * - Inspection of bound target/threshold value
 * - Expression translation for SQL/AST builders and criteria engines
 *
 * Examples:
 * <code>
 * class AgeGreaterThanSpecification implements IValueBoundSpecification
 * {
 *     public function __construct(private int $minimumAge) {}
 *     public function getValue(): int { return $this->minimumAge; }
 *     public function isSatisfiedBy(?object $candidate): bool { return $candidate !== null && $candidate->age > $this->minimumAge; }
 * }
 * </code>
 *
 * @template T
 * @extends ILeafSpecification<T>
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 * @see        https://www.martinfowler.com/apsupp/spec.pdf The Specifications Pattern
 */
interface IValueBoundSpecification extends ILeafSpecification
{
    /**
     * Returns the reference value bound to this specification.
     *
     * This value represents the comparison operand against which candidates are evaluated.
     * Depending on the concrete implementation, it may be a scalar, object, or compound range array.
     *
     * @return mixed Bound reference value
     */
    public function getValue(): mixed;
}
