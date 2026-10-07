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
 *
 * @version    1.3.0
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
     * @param array<string, mixed> $parameters Associative parameter map (:param => value)
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
        $params = array_merge($this->parameters, $other->parameters);

        return new self($sql, $params);
    }

    /**
     * Combine this clause with another using the logical OR operator.
     *
     * @param self $other
     * @return self
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
        $params = array_merge($this->parameters, $other->parameters);

        return new self($sql, $params);
    }

    /** {@inheritdoc} */
    public function __toString(): string
    {
        return $this->sql;
    }
}
