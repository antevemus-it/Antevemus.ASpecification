<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Sql;

use Closure;
use Antevemus\ASpecification\Contracts\Sql\IFieldMapper;

/**
 * FieldMapper - Canonical Property Name to SQL Column Mapper
 *
 * Maps domain model properties to physical database column names,
 * supporting static associative maps, default table prefixes, or dynamic Closures.
 *
 * Features:
 * - Direct dictionary mapping (associative array)
 * - Automatic addition of configurable table alias/prefix (e.g. 'c.')
 * - Automatic conversion from camelCase to snake_case as fallback
 *
 * @version    1.4.0
 * @package    Antevemus\ASpecification
 * @subpackage Sql
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class FieldMapper implements IFieldMapper
{
    /**
     * @param array<string, string>|Closure(string): string|null $mapping Mapping dictionary or callback
     * @param string|null $tableAlias Optional default table alias (e.g. 'c')
     */
    public function __construct(
        private readonly array|Closure|null $mapping = null,
        private readonly ?string $tableAlias = null
    ) {
    }

    /**
     * Resolve an IFieldMapper instance from array, closure, or existing mapper.
     *
     * @param IFieldMapper|array<string, string>|Closure(string): string|null $mapper
     * @param string|null $tableAlias
     * @return IFieldMapper
     */
    public static function from(IFieldMapper|array|Closure|null $mapper, ?string $tableAlias = null): IFieldMapper
    {
        if ($mapper instanceof IFieldMapper) {
            return $mapper;
        }
        return new self($mapper, $tableAlias);
    }

    /**
     * Concise alias for mapField().
     *
     * @param string $propertyName
     * @return string
     */
    public function map(string $propertyName): string
    {
        return $this->mapField($propertyName);
    }

    /** {@inheritdoc} */
    public function mapField(string $propertyName): string
    {
        $target = $propertyName;

        if ($this->mapping instanceof Closure) {
            $closure = $this->mapping;
            $target = $closure($propertyName);
        } elseif (is_array($this->mapping) && isset($this->mapping[$propertyName])) {
            $target = $this->mapping[$propertyName];
        } else {
            // Default fallback: converts camelCase to snake_case
            $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $propertyName));
            $target = $snake;
        }

        // If a table qualifier or parentheses are already present, no alias is added
        if (str_contains($target, '.') || str_contains($target, '(')) {
            return $target;
        }

        if ($this->tableAlias !== null && trim($this->tableAlias) !== '') {
            return rtrim($this->tableAlias, '.') . '.' . $target;
        }

        return $target;
    }
}
