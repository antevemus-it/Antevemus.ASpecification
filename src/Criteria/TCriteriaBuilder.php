<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Criteria;

use Adianti\Database\TCriteria;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Sql\IFieldMapper;
use Antevemus\ASpecification\Criteria\Exceptions\CriteriaBuilderException;

/**
 * TCriteriaBuilder - Builder and Compiler for TCriteria Instances from Specifications
 *
 * Provides a fluent API and static factory shortcuts to compile domain specification trees
 * into Adianti Framework TCriteria objects, supporting pagination, sorting, and column mapping.
 *
 * Features:
 * - Direct static compilation via fromSpecification() and create()
 * - Fluent pagination configuration (limit, offset)
 * - Fluent sorting specification (orderBy, direction)
 * - Fluent grouping configuration (groupBy)
 * - Support for object-relational property name mapping
 *
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Criteria
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class TCriteriaBuilder
{
    private readonly CriteriaSpecificationVisitor $visitor;
    private array $properties = [];

    /**
     * @param ISpecification $specification Domain specification to compile
     * @param IFieldMapper|array<string, string>|callable(string): string|null $fieldMapper Optional field mapper
     */
    public function __construct(
        private readonly ISpecification $specification,
        IFieldMapper|array|callable|null $fieldMapper = null
    ) {
        $this->visitor = new CriteriaSpecificationVisitor($fieldMapper);
    }

    /**
     * Direct static factory to compile a specification into a TCriteria instance.
     *
     * @param ISpecification $specification
     * @param IFieldMapper|array<string, string>|callable(string): string|null $fieldMapper
     * @param array<string, mixed> $properties Criteria properties ('order', 'limit', 'offset', 'direction', 'group')
     * @return TCriteria
     */
    public static function fromSpecification(
        ISpecification $specification,
        IFieldMapper|array|callable|null $fieldMapper = null,
        array $properties = []
    ): TCriteria {
        $builder = new self($specification, $fieldMapper);
        foreach ($properties as $property => $value) {
            $builder->setProperty((string)$property, $value);
        }
        return $builder->build();
    }

    /**
     * Static shortcut alias for fromSpecification().
     *
     * @param ISpecification $specification
     * @param IFieldMapper|array<string, string>|callable(string): string|null $fieldMapper
     * @param array<string, mixed> $properties
     * @return TCriteria
     */
    public static function create(
        ISpecification $specification,
        IFieldMapper|array|callable|null $fieldMapper = null,
        array $properties = []
    ): TCriteria {
        return self::fromSpecification($specification, $fieldMapper, $properties);
    }

    /**
     * Set a configuration property on the resulting TCriteria instance.
     *
     * @param string $property Property name ('order', 'limit', 'offset', 'direction', 'group')
     * @param mixed $value
     * @return self
     */
    public function setProperty(string $property, mixed $value): self
    {
        $this->properties[$property] = $value;
        return $this;
    }

    /**
     * Define the query sorting column and direction.
     *
     * @param string $column Column name to sort by
     * @param string $direction Sort direction ('asc' or 'desc')
     * @return self
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->properties['order'] = $column;
        $this->properties['direction'] = strtolower($direction);
        return $this;
    }

    /**
     * Set query pagination boundaries.
     *
     * @param int $limit Maximum number of records
     * @param int $offset Starting zero-based offset
     * @return self
     */
    public function limit(int $limit, int $offset = 0): self
    {
        $this->properties['limit'] = $limit;
        $this->properties['offset'] = $offset;
        return $this;
    }

    /**
     * Define query grouping (GROUP BY).
     *
     * @param string $column Grouping column
     * @return self
     */
    public function groupBy(string $column): self
    {
        $this->properties['group'] = $column;
        return $this;
    }

    /**
     * Compile and return the configured TCriteria instance.
     *
     * @return TCriteria
     */
    public function build(): TCriteria
    {
        $criteria = $this->visitor->buildCriteria($this->specification);

        foreach ($this->properties as $property => $value) {
            if ($value !== null && $value !== '') {
                $criteria->setProperty($property, $value);
            }
        }

        return $criteria;
    }
}
