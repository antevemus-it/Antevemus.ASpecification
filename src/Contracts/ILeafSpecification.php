<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts;

/**
 * ILeafSpecification - Marker interface for atomic leaf specifications.
 *
 * Part of the Evans/Fowler Specifications pattern (sentence from the Domian javadoc, Copyright 2006-2010
 * the original author or authors, Apache License 2.0; see THIRD_PARTY_NOTICES.md).
 *
 * Marks a specification as an atomic "leaf" in the specification composite tree.
 * Leaf specifications represent individual, indivisible business rules.
 *
 * In contrast to ICompositeSpecification (which combines multiple specifications),
 * an ILeafSpecification cannot be decomposed into smaller sub-specifications.
 *
 * Examples of leaf specifications:
 * - AgeGreaterThanSpecification
 * - EmailVerifiedSpecification
 * - PriceInRangeSpecification
 * - StatusEqualsSpecification
 *
 * Features:
 * - Distinguishes atomic rules from composite trees
 * - Acts as an AST terminal node marker for visitor engines
 *
 * @template T
 * @extends ISpecification<T>
 * @version    1.4.3
 * @package    Antevemus\ASpecification
 * @subpackage Contracts
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 * @see        https://www.martinfowler.com/apsupp/spec.pdf The Specifications Pattern
 */
interface ILeafSpecification extends ISpecification
{
    // Marker interface - no additional methods.
    // Leaf specifications implement the base methods of ISpecification.
}
