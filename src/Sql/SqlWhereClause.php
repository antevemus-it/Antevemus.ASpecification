<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql;

use Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause;

/**
 * SqlWhereClause - Immutable Value Object for Parameterized WHERE Clauses
 *
 * Encapsulates safe SQL expressions and parameter bindings generated
 * by visiting specification trees, ready for consumption in PDO, Adianti, or Doctrine DBAL.
 *
 * Features:
 * - Immutable typed structure (readonly)
 * - Fluent boolean composition (and, or) between generated clauses
 * - Null/empty clause detection
 * - Direct textual representation via Stringable
 * - README aliases getSql() / getBindings() delegating to toSql() / getParameters()
 * - and()/or() refuse to merge positional with named parameters, or one name bound to two
 *   different values (1.13.0)
 *
 * @version    1.13.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final readonly class SqlWhereClause implements ISqlWhereClause
{
    /**
     * @param string $sql SQL fragment of the clause
     * @param array<int|string, mixed> $parameters Associative parameter map (:param => value), or a list
     *                                             for positional (?) placeholders
     */
    public function __construct(
        public string $sql = '',
        public array $parameters = []
    ) {
    }

    /**
     * Create an empty clause.
     *
     * @return self
     */
    public static function empty(): self
    {
        return new self('', []);
    }

    /** {@inheritdoc} */
    public function toSql(): string
    {
        return $this->sql;
    }

    /** {@inheritdoc} */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /** {@inheritdoc} */
    public function getSql(): string
    {
        return $this->toSql();
    }

    /** {@inheritdoc} */
    public function getBindings(): array
    {
        return $this->getParameters();
    }

    /** {@inheritdoc} */
    public function isEmpty(): bool
    {
        return trim($this->sql) === '';
    }

    /**
     * Combine this clause with another using the logical AND operator.
     *
     * @param self $other
     * @return self
     * @throws \LogicException When the parameters mix positional and named styles, or bind one name to two values (1.13.0)
     */
    public function and(self $other): self
    {
        if ($this->isEmpty()) {
            return $other;
        }
        if ($other->isEmpty()) {
            return $this;
        }

        $sql = "({$this->sql} AND {$other->sql})";
        $params = self::mergeParameters($this->parameters, $other->parameters);

        return new self($sql, $params);
    }

    /**
     * Combine this clause with another using the logical OR operator.
     *
     * @param self $other
     * @return self
     * @throws \LogicException When the parameters mix positional and named styles, or bind one name to two values (1.13.0)
     */
    public function or(self $other): self
    {
        if ($this->isEmpty()) {
            return $other;
        }
        if ($other->isEmpty()) {
            return $this;
        }

        $sql = "({$this->sql} OR {$other->sql})";
        $params = self::mergeParameters($this->parameters, $other->parameters);

        return new self($sql, $params);
    }

    /**
     * Merges the parameters of two clauses, refusing the combinations a driver would bind wrongly
     * without an error (1.13.0): positional (`?`) mixed with named (`:name`) parameters — SQLite,
     * for one, binds such a statement silently wrong — and the same name bound to two different
     * values, which array_merge() would overwrite. The same name with the same value is kept once.
     *
     * @param array<int|string, mixed> $left
     * @param array<int|string, mixed> $right
     * @return array<int|string, mixed>
     * @throws \LogicException On mixed styles or a conflicting name
     */
    private static function mergeParameters(array $left, array $right): array
    {
        if ($left === []) {
            return $right;
        }
        if ($right === []) {
            return $left;
        }
        $leftPositional = array_is_list($left);
        $rightPositional = array_is_list($right);
        if ($leftPositional !== $rightPositional) {
            throw new \LogicException('Cannot combine SQL clauses with positional (?) and named (:name) parameters: the driver would bind them wrongly.');
        }
        if ($leftPositional) {
            return array_merge($left, $right);
        }
        foreach ($right as $name => $value) {
            if (array_key_exists($name, $left) && $left[$name] !== $value) {
                throw new \LogicException(sprintf('Cannot combine SQL clauses: the parameter "%s" is bound to two different values.', (string) $name));
            }
            $left[$name] = $value;
        }

        return $left;
    }

    /** {@inheritdoc} */
    public function __toString(): string
    {
        return $this->sql;
    }
}
