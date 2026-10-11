<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Sql\ISqlDialect;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\EntityMapping;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;
use Antevemus\ASpecification\Sql\SqlQueryVisitor;
use Antevemus\ASpecification\Sql\SqlWhereClause;

/**
 * PdoSpecificationCompiler - Specification to WHERE Clause over an Entity Mapping
 *
 * The SqlQueryVisitor of the library, bound to one EntityMapping (forward 028, D4):
 * - a property name of the specification becomes the column of the mapping, and only when the
 *   mapping allows a specification to read that property by name (EntityMapping::findQueryColumn());
 *   any other name raises NonTranslatableSpecificationException, so no name from a specification
 *   ever reaches the SQL text (RN-04)
 * - the values bound for a property are converted like a written value of its column (dates in the
 *   stored format and time zone, enums by value, booleans per driver), each parameter by the column
 *   it is compared with: the parameters of a property clause are exactly those of the clause the
 *   parent visitor returns for it
 * - null values follow the in-memory semantics through the parent visitor (1.11.0): each comparison on
 *   a column is guarded with `column IS NOT NULL AND ...`, so it is false for a NULL column (never
 *   unknown) and a NOT over it is true, as in memory; `isNull()` and `isNotNull()` inside a property
 *   are the plain null checks; the identifier column never holds NULL and is not guarded (canBeNull()).
 *   Before 1.11.0 this compiler guarded the whole property clause, matching the earlier in-memory
 *   rule "a null property value never satisfies", which 1.11.0 dropped
 * - the entity type leaves are answered from the mapping: AllEntitiesSpecification is the dialect's
 *   true condition when the mapped class is an instance of its type and the false condition
 *   otherwise; UniqueEntitySpecification compares the identifier column, as the in-memory leaf
 *   compares the identifiers' string forms; the constant leaves (alwaysTrue()/alwaysFalse()) at the
 *   top of a tree are the dialect's true and false conditions
 * - everything else (closures, method calls, external data) raises the exceptions the visitor
 *   already uses
 *
 * @internal Used by PdoRepository; not part of the public API.
 * @version    1.12.0
 * @package    Antevemus\ASpecification
 * @subpackage Repositories\Pdo
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class PdoSpecificationCompiler extends SqlQueryVisitor
{
    /** @var array<string, true> Parameters already converted by an inner property clause */
    private array $converted = [];

    private int $identityCounter = 0;

    public function __construct(
        private readonly EntityMapping $mapping,
        private readonly ISqlDialect $sqlDialect,
        private readonly PdoValueConverter $converter
    ) {
        // A static closure: binding $this would make every compiler (one per query) a reference
        // cycle, left for the cycle collector instead of being freed when the query returns
        parent::__construct($sqlDialect, static function (string $property) use ($mapping): string {
            $column = $mapping->findQueryColumn($property);

            // visitComposite() checked the property first; this is only reached for a mapped one
            return $column !== null ? $column->column : throw new \LogicException("Unmapped property {$property}.");
        });
    }

    /** {@inheritdoc} */
    public function reset(): self
    {
        $this->converted = [];
        $this->identityCounter = 0;
        parent::reset();

        return $this;
    }

    /** {@inheritdoc} */
    public function visitComposite(ICompositeSpecification $specification): SqlWhereClause
    {
        if (!$specification instanceof PropertySpecification) {
            return parent::visitComposite($specification);
        }

        $reason = null;
        $column = $this->mapping->findQueryColumn($specification->getPropertyName(), $reason);
        if ($column === null) {
            throw new NonTranslatableSpecificationException($specification, (string) $reason);
        }

        $clause = parent::visitComposite($specification);
        $parameters = $clause->getParameters();
        foreach ($parameters as $name => $value) {
            if (isset($this->converted[$name])) {
                continue;
            }
            $parameters[$name] = $this->converter->toQueryParameter($column, $value);
            $this->converted[$name] = true;
        }

        // The null guards were added by the parent visitor, comparison by comparison (RN-02: same results
        // as InMemoryRepository); this clause only gets its parameters converted
        return new SqlWhereClause($clause->toSql(), $parameters);
    }

    /**
     * {@inheritdoc}
     *
     * The identifier column never holds NULL; every other mapped column may.
     */
    protected function canBeNull(string $property): bool
    {
        return !($this->mapping->findQueryColumn($property)?->isId ?? false);
    }

    /** {@inheritdoc} */
    public function visitLeaf(ISpecification $specification): SqlWhereClause
    {
        if ($specification instanceof AllEntitiesSpecification) {
            return new SqlWhereClause($this->typeCondition($specification->getType()));
        }

        if ($specification instanceof UniqueEntitySpecification) {
            return $this->identityClause($specification);
        }

        // The constant leaves (they are not composites, so they reach visitLeaf()) mean the whole table
        // or nothing; since 1.11.0 the parent visitor answers them the same way
        if ($specification instanceof AlwaysTrueSpecification) {
            return new SqlWhereClause($this->sqlDialect->getTrueCondition());
        }
        if ($specification instanceof AlwaysFalseSpecification) {
            return new SqlWhereClause($this->sqlDialect->getFalseCondition());
        }

        return parent::visitLeaf($specification);
    }

    private function typeCondition(string $type): string
    {
        return is_a($this->mapping->getEntityClass(), $type, true)
            ? $this->sqlDialect->getTrueCondition()
            : $this->sqlDialect->getFalseCondition();
    }

    private function identityClause(UniqueEntitySpecification $specification): SqlWhereClause
    {
        $type = $specification->getType();
        if ($type !== IEntity::class && !is_a($this->mapping->getEntityClass(), $type, true)) {
            return new SqlWhereClause($this->sqlDialect->getFalseCondition());
        }

        $id = $this->mapping->getId();
        $expected = $specification->getExpectedId();
        if (!is_scalar($expected) && !$expected instanceof \Stringable) {
            throw new NonTranslatableSpecificationException(
                $specification,
                'the expected identifier is not a scalar, so it cannot be compared with the identifier column'
            );
        }
        $text = (string) $expected;

        // The in-memory leaf compares the string forms of the identifiers
        if ($id->type === ColumnType::Integer) {
            if (preg_match('/^-?[0-9]+$/', $text) !== 1) {
                return new SqlWhereClause($this->sqlDialect->getFalseCondition());
            }
            $value = (int) $text;
        } else {
            $value = $text;
        }

        $name = ':uid' . (++$this->identityCounter);

        return new SqlWhereClause(
            $this->sqlDialect->escapeIdentifier($id->column) . ' = ' . $name,
            [$name => $value]
        );
    }
}
