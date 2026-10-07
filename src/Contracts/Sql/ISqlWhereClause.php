<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts\Sql;

use Stringable;

/**
 * ISqlWhereClause - Contract for Framework-Agnostic Parameterized WHERE Clauses
 *
 * Encapsulates safe SQL expressions and parameter bindings generated
 * by visiting specification trees.
 *
 * Features:
 * - Direct textual SQL expression retrieval
 * - Access to associated named parameter map (:p1 => value)
 * - README aliases getSql() / getBindings() for the two accessors above
 * - Empty clause verification
 * - Stringable implementation for clean string interpolation
 *
 * @version    1.3.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts\Sql
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
interface ISqlWhereClause extends Stringable
{
    /**
     * Return SQL expression for the WHERE clause (without the WHERE keyword).
     *
     * @return string
     */
    public function toSql(): string;

    /**
     * Return associative map of named parameter bindings (:param => value).
     *
     * @return array<string, mixed>
     */
    public function getParameters(): array;

    /**
     * Alias of toSql(): the name used by the README and by PDO-oriented callers.
     *
     * toSql() remains the canonical accessor; this method never diverges from it.
     *
     * @return string
     */
    public function getSql(): string;

    /**
     * Alias of getParameters(): the name used by the README and by PDO-oriented callers.
     *
     * Returns the same associative map, with the same ':param' keys.
     * getParameters() remains the canonical accessor; this method never diverges from it.
     *
     * @return array<string, mixed>
     */
    public function getBindings(): array;

    /**
     * Indicate whether the generated clause is empty (unconstrained).
     *
     * @return bool
     */
    public function isEmpty(): bool;
}
