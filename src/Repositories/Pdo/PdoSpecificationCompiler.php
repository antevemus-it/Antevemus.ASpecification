<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Repositories\Pdo;

use Antevemus\ASpecification\Contracts\Entities\IEntity;
use Antevemus\ASpecification\Contracts\ICompositeSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Contracts\Sql\ISqlDialect;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\BooleanStorage;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnMapping;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\ColumnType;
use Antevemus\ASpecification\Repositories\Pdo\Mapping\EntityMapping;
use Antevemus\ASpecification\Specifications\Collection\AllEntitiesSpecification;
use Antevemus\ASpecification\Specifications\Collection\UniqueEntitySpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysFalseSpecification;
use Antevemus\ASpecification\Specifications\Logical\AlwaysTrueSpecification;
use Antevemus\ASpecification\Specifications\PropertySpecification;
use Antevemus\ASpecification\Specifications\Subquery\AbstractSubquerySpecification;
use Antevemus\ASpecification\Sql\Exceptions\NonTranslatableSpecificationException;
use Antevemus\ASpecification\Sql\SqlQueryVisitor;
use Antevemus\ASpecification\Sql\SqlWhereClause;
use PDO;

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
 * - a boolean compared with a column stored as BooleanStorage::Integer compiles to 1/0 instead of
 *   the dialect's literal (since 1.13.0, forward 031, D2: `smallint = TRUE` is an error in PostgreSQL)
 * - Spec::inQuery() compiles to a subquery of the other repository on the same connection (since
 *   1.13.0, forward 031, D6), with parameters prefixed apart (`:ps1_1`, nested `:ps1_s1_1`)
 * - a boolean compared with a column stored as BooleanStorage::Char compiles to the declared text in
 *   every case form, `col IN ('Y', 'y')` (since 1.14.0, forward 032, D4: a read accepts any case, so
 *   the database must find any case too; past 4 case forms, `LOWER(col) = 'false'`); the texts are
 *   letters and digits, validated by the mapping
 * - whereRelation() through a #[ManyToMany] join table compiles to `id IN (SELECT owner FROM join
 *   WHERE target IN (SELECT ...))` (since 1.14.0, forward 032, D1)
 * - the raw subqueries of Spec::inSubquery()/Spec::existsIn() (2.5.0) are refused as not translatable:
 *   they read a table outside the mapping; inside a repository, Spec::inQuery() is the subquery
 *
 * @internal Used by PdoRepository; not part of the public API.
 * @version    2.5.0
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

    /** The column of the property clause being compiled, when it is a Boolean column. */
    private ?ColumnMapping $booleanColumn = null;

    private int $subqueryCounter = 0;

    /**
     * @param EntityMapping $mapping Mapping of the queried entity
     * @param ISqlDialect $sqlDialect Dialect of the connection
     * @param PdoValueConverter $converter Converter of the connection
     * @param PDO|null $connection Connection of the query: a subquery (Spec::inQuery()) is translated
     *                             only for a repository on this same connection (since 1.13.0)
     * @param string $parameterPrefix Prefix of the generated parameter names (since 1.13.0; a subquery
     *                                gets its own, so the parameters of both queries never collide)
     */
    public function __construct(
        private readonly EntityMapping $mapping,
        private readonly ISqlDialect $sqlDialect,
        private readonly PdoValueConverter $converter,
        private readonly ?PDO $connection = null,
        private readonly string $parameterPrefix = 'p'
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $parameterPrefix) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid parameter prefix "%s".', $parameterPrefix));
        }
        // A static closure: binding $this would make every compiler (one per query) a reference
        // cycle, left for the cycle collector instead of being freed when the query returns
        parent::__construct($sqlDialect, static function (string $property) use ($mapping): string {
            $column = $mapping->findQueryColumn($property);

            // visitComposite() checked the property first; this is only reached for a mapped one
            return $column !== null ? $column->column : throw new \LogicException("Unmapped property {$property}.");
        }, $parameterPrefix);
    }

    /** {@inheritdoc} */
    public function reset(): self
    {
        $this->converted = [];
        $this->identityCounter = 0;
        $this->subqueryCounter = 0;
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

        $previous = $this->booleanColumn;
        $this->booleanColumn = $column->type === ColumnType::Boolean ? $column : null;
        try {
            $clause = parent::visitComposite($specification);
        } finally {
            $this->booleanColumn = $previous;
        }
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
     * 1/0 for a column stored as BooleanStorage::Integer (since 1.13.0); the quoted text for
     * BooleanStorage::Char (since 1.14.0; formatBooleanComparison() answers first).
     */
    protected function formatBooleanLiteral(bool $value): string
    {
        return match ($this->booleanColumn?->booleanStorage) {
            BooleanStorage::Integer => $value ? '1' : '0',
            BooleanStorage::Char => self::quoteText($value ? $this->booleanColumn->trueValue : $this->booleanColumn->falseValue),
            default => parent::formatBooleanLiteral($value),
        };
    }

    /**
     * {@inheritdoc}
     *
     * BooleanStorage::Char (since 1.14.0): every case form of the declared text, `col IN ('Y', 'y')`,
     * since a read accepts the text whatever its case; `LOWER(col) = 'false'` past 4 forms (more than two letters).
     */
    protected function formatBooleanComparison(string $column, bool $value): ?string
    {
        if ($this->booleanColumn === null || $this->booleanColumn->booleanStorage !== BooleanStorage::Char) {
            return null;
        }
        $text = $value ? $this->booleanColumn->trueValue : $this->booleanColumn->falseValue;
        $forms = [''];
        foreach (str_split($text) as $character) {
            $cases = array_unique([strtoupper($character), strtolower($character)]);
            $next = [];
            foreach ($forms as $prefix) {
                foreach ($cases as $case) {
                    $next[] = $prefix . $case;
                }
            }
            $forms = $next;
            if (count($forms) > 4) {
                return 'LOWER(' . $column . ') = ' . self::quoteText(strtolower($text));
            }
        }
        if (count($forms) === 1) {
            return $column . ' = ' . self::quoteText($forms[0]);
        }
        // The declared form first
        usort($forms, static fn(string $a, string $b): int => ($b === $text) <=> ($a === $text));

        return $column . ' IN (' . implode(', ', array_map(self::quoteText(...), $forms)) . ')';
    }

    /** A text literal (the texts are letters and digits, validated by the mapping; quotes doubled anyway). */
    private static function quoteText(string $text): string
    {
        return "'" . str_replace("'", "''", $text) . "'";
    }

    /**
     * {@inheritdoc}
     *
     * Spec::inQuery() (since 1.13.0, forward 031, D6): `col IN (SELECT othercol FROM other WHERE ...)`,
     * built by the other repository (its mapping, its read scope, NULL values left out) with parameter
     * names of its own. Its parameters are already converted for the other columns. Refused (not
     * translatable) when the other repository is on another connection or speaks another dialect.
     */
    protected function translateExtensionLeaf(ISpecification $specification, string $column): ?SqlWhereClause
    {
        if (!$specification instanceof InQuerySpecification) {
            return null;
        }

        $other = $specification->getRepository();
        if ($this->connection === null || $other->getConnection() !== $this->connection) {
            throw new NonTranslatableSpecificationException(
                $specification,
                'the subquery repository is on another connection (a subquery is one statement: both repositories must share the same PDO object)'
            );
        }
        if ($other->getDialect()->getFamily() !== $this->sqlDialect->getFamily()) {
            throw new NonTranslatableSpecificationException(
                $specification,
                sprintf('the subquery repository speaks the %s dialect, this query %s', $other->getDialect()->getFamily(), $this->sqlDialect->getFamily())
            );
        }

        $through = $specification->getThrough();
        if ($through !== null && $through->owner->getConnection() !== $this->connection) {
            throw new NonTranslatableSpecificationException(
                $specification,
                'the join table of the association is on another connection (a subquery is one statement)'
            );
        }

        $prefix = $this->parameterPrefix . 's' . (++$this->subqueryCounter) . '_';
        $subquery = $other->toSubquery($specification->getWhere(), $specification->getProperty(), $prefix);
        foreach (array_keys($subquery->getParameters()) as $name) {
            $this->converted[$name] = true;
        }
        $sql = $through !== null ? $through->wrap($this->sqlDialect, $subquery->toSql()) : $subquery->toSql();

        return new SqlWhereClause($column . ' IN (' . $sql . ')', $subquery->getParameters());
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
        if ($specification instanceof AbstractSubquerySpecification) {
            // 2.5.0 (forward 048, D20): the raw subquery reads a table outside the mapping (its values would
            // be converted as this repository's column); a repository uses Spec::inQuery()
            throw new NonTranslatableSpecificationException(
                $specification,
                'the subquery of Spec::inSubquery()/Spec::existsIn() is for toSql() and toCriteria(); inside a PdoRepository use Spec::inQuery(), which applies the mapping and the read scope of the other repository'
            );
        }

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

        $name = ($this->parameterPrefix === 'p' ? ':uid' : ':' . $this->parameterPrefix . 'uid') . (++$this->identityCounter);

        return new SqlWhereClause(
            $this->sqlDialect->escapeIdentifier($id->column) . ' = ' . $name,
            [$name => $value]
        );
    }
}
