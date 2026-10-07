<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Contracts;

use Antevemus\ASpecification\Results\SpecificationResult;

/**
 * ISpecification - Core interface for the Evans/Fowler Specification pattern.
 *
 * Encapsulates business logic rules into reusable, composable predicate objects
 * that can be chained using boolean algebraic operators (AND, OR, NOT).
 *
 * Note on type parameterization:
 * Domain specifications are typed. Only send candidate objects of the correct type
 * to a specification for validation. PHP generics (via PHPDoc @template) ensure static analysis
 * correctness.
 *
 * Features:
 * - Candidate evaluation (isSatisfiedBy, evaluate with Notification Pattern)
 * - Boolean composition (and, or, not, andNot, orNot, where)
 * - Subsumption algebra (isGeneralizationOf, isSpecialCaseOf, isDisjointWith, isIntersectionOf, intersectsWith)
 * - AST Visitor support (accept, toSql, toCriteria)
 * - Failure notification context (because, withCode)
 *
 * @template T
 * @version    1.1.0
 * @package    Antevemus\ASpecification
 * @subpackage Contracts
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 * @see        https://www.martinfowler.com/apsupp/spec.pdf The Specifications Pattern
 */
interface ISpecification
{
    /**
     * Creates a parameterized specification targeting an accessible property or getter of the candidate.
     *
     * This method acts as an entry point for fluent property-level specifications.
     *
     * Example:
     * <code>
     * $spec = $baseSpec->where('address', new CitySpecification('São Paulo'))
     *                  ->and(new AgeSpecification(18));
     * </code>
     *
     * @template F
     * @param string $accessibleObjectName Name of the accessible property or method
     * @param ISpecification<F> $accessibleObjectSpecification Specification coupled to the property value
     * @return ICompositeSpecification<T> Conjunction of this specification with the property specification
     * @throws \InvalidArgumentException If any parameter is invalid or null
     * @throws \BadMethodCallException If called multiple times consecutively in the same expression
     */
    public function where(string $accessibleObjectName, ISpecification $accessibleObjectSpecification): ICompositeSpecification;

    /**
     * Appends an inline business rule (closure) to this specification with logical AND.
     *
     * The closure receives the whole candidate and returns a boolean. The resulting leaf
     * (`PredicateSpecification`) carries the optional failure code and message for the
     * Notification Pattern, so a rule handler can declare an ad hoc rule in one line:
     *
     * <code>
     * Spec::specify(Contract::class)
     *     ->must(fn($c) => $c->getOccurrencesCount() <= $rule->getValorInteiro(), $rule->getCodigo(), $rule->getMensagemViolacao());
     * </code>
     *
     * An exception thrown inside the closure propagates from `isSatisfiedBy()` and becomes an
     * error result under `evaluate()`. The closure is opaque to the SQL and TCriteria visitors
     * (they throw their "non translatable" exception); the ALinq visitor compiles it natively.
     *
     * @param \Closure(mixed): bool $predicate Inline rule over the whole candidate
     * @param string|null $code Failure code applied through `withCode()` when provided
     * @param string|null $message Failure message applied through `because()` when provided
     * @return ICompositeSpecification<T> Conjunction of this specification with the predicate leaf
     */
    public function must(\Closure $predicate, ?string $code = null, ?string $message = null): ICompositeSpecification;

    /**
     * Creates a logical conjunction (AND) with another specification or a property specification.
     *
     * Combines this specification with another using boolean AND logic.
     * The resulting composite is satisfied only when BOTH specifications are satisfied.
     *
     * Example:
     * <code>
     * $adultSpec = new AgeSpecification(18);
     * $verifiedSpec = new EmailVerifiedSpecification();
     * $combined = $adultSpec->and($verifiedSpec);
     * // Or property-scoped:
     * $spec->and('role', Spec::is('admin'));
     * </code>
     *
     * @param ISpecification<T>|string $otherSpecification The other specification or property name
     * @param ISpecification<mixed>|null $propertySpecification Property specification (when first argument is string)
     * @return ICompositeSpecification<T> New composite specification: this AND other
     * @throws \InvalidArgumentException If parameter is null or types are incompatible
     * @throws \BadMethodCallException If invoked out of order in fluent chain
     */
    public function and(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification;

    /**
     * Creates a logical disjunction (OR) with another specification or a property specification.
     *
     * Combines this specification with another using boolean OR logic.
     * The resulting composite is satisfied when EITHER specification is satisfied.
     *
     * Example:
     * <code>
     * $adminSpec = new RoleSpecification('admin');
     * $ownerSpec = new OwnerSpecification($userId);
     * $hasAccess = $adminSpec->or($ownerSpec);
     * // Or property-scoped:
     * $spec->or('gender', Spec::is('MALE'));
     * </code>
     *
     * @param ISpecification<T>|string $otherSpecification The other specification or property name
     * @param ISpecification<mixed>|null $propertySpecification Property specification (when first argument is string)
     * @return ICompositeSpecification<T> New composite specification: this OR other
     * @throws \InvalidArgumentException If parameter is null or invalid
     */
    public function or(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification;

    /**
     * Inverts this specification using logical NOT.
     *
     * Creates a new specification satisfied when this specification is NOT satisfied, and vice-versa.
     *
     * Example:
     * <code>
     * $adultSpec = new AgeSpecification(18);
     * $minorSpec = $adultSpec->not();
     * </code>
     *
     * @return ICompositeSpecification<T> Negated specification
     */
    public function not(): ICompositeSpecification;

    /**
     * Accepts a visitor to traverse the specification AST (Visitor Pattern).
     *
     * @template TResult
     * @param ISpecificationVisitor $visitor Visitor instance
     * @return mixed Result produced by the visitor
     */
    public function accept(ISpecificationVisitor $visitor): mixed;

    /**
     * Translates this specification into a parameterized relational SQL WHERE clause.
     *
     * @param \Antevemus\ASpecification\Contracts\Sql\ISqlDialect|\Antevemus\ASpecification\Sql\SqlDialect|string $dialect Target dialect ('pgsql', 'mysql', 'sqlsrv', 'oracle', 'sqlite', 'firebird', 'ansi')
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMap Property-to-column mapper
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMapper Alias of $fieldMap (the name used by the README); both given must be identical
     * @return \Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause Parameterized SQL clause
     * @throws \InvalidArgumentException When $fieldMap and $fieldMapper are both given and differ
     */
    public function toSql(
        \Antevemus\ASpecification\Contracts\Sql\ISqlDialect|\Antevemus\ASpecification\Sql\SqlDialect|string $dialect = 'ansi',
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null,
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMapper = null
    ): \Antevemus\ASpecification\Contracts\Sql\ISqlWhereClause;

    /**
     * Translates this specification into an Adianti Framework TCriteria instance.
     *
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMap Property-to-column mapper
     * @param array<string, mixed> $properties Criteria configuration ('order', 'limit', 'offset', 'direction', 'group')
     * @param \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array<string, string>|\Closure|null $fieldMapper Alias of $fieldMap (the name used by the README); both given must be identical
     * @return mixed \Adianti\Database\TCriteria instance
     * @throws \InvalidArgumentException When $fieldMap and $fieldMapper are both given and differ
     */
    public function toCriteria(
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMap = null,
        array $properties = [],
        \Antevemus\ASpecification\Contracts\Sql\IFieldMapper|array|\Closure|null $fieldMapper = null
    ): mixed;

    /**
     * Returns the target candidate type (FQCN) validated by this specification.
     *
     * @return class-string<T> Fully qualified class or interface name
     */
    public function getType(): string;

    /**
     * Evaluates whether the candidate satisfies this specification.
     *
     * Core evaluation method. Returns true if candidate satisfies the encapsulated business rule, false otherwise.
     * Null candidates never satisfy a specification.
     *
     * @param T|null $candidate Candidate object to evaluate
     * @return bool True if candidate satisfies the specification, false otherwise
     */
    public function isSatisfiedBy(?object $candidate): bool;

    /**
     * Checks specification subsumption - Generalization.
     *
     * Determines whether this specification is a generalization of another specification.
     * If true, any candidate satisfying the other specification also satisfies this specification.
     *
     * Example:
     * <code>
     * $animalSpec = new TypeSpecification(Animal::class);
     * $dogSpec = new TypeSpecification(Dog::class);
     * $animalSpec->isGeneralizationOf($dogSpec); // true
     * </code>
     *
     * @param ISpecification<T> $otherSpecification Candidate specification to compare
     * @return bool True if this specification is a generalization of the other
     * @throws \InvalidArgumentException If parameter is null
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool;

    /**
     * Checks specification subsumption - Specialization.
     *
     * Determines whether this specification is a special case of another specification.
     * If true, any candidate satisfying this specification also satisfies the other specification.
     *
     * Example:
     * <code>
     * $dogSpec = new TypeSpecification(Dog::class);
     * $animalSpec = new TypeSpecification(Animal::class);
     * $dogSpec->isSpecialCaseOf($animalSpec); // true
     * </code>
     *
     * @param ISpecification<T> $otherSpecification Candidate specification to compare
     * @return bool True if this specification is a special case of the other
     * @throws \InvalidArgumentException If parameter is null
     */
    public function isSpecialCaseOf(ISpecification $otherSpecification): bool;

    /**
     * Checks whether two specifications are disjoint (mutually exclusive).
     *
     * Two specifications are disjoint if no candidate can satisfy both simultaneously (empty intersection).
     *
     * Example:
     * <code>
     * $adultSpec = new AgeGreaterThanSpecification(18);
     * $childSpec = new AgeLessThanSpecification(12);
     * $adultSpec->isDisjointWith($childSpec); // true
     * </code>
     *
     * @param ISpecification<mixed> $otherSpecification Candidate specification to compare
     * @return bool True if specifications are disjoint
     * @throws \InvalidArgumentException If parameter is null
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool;

    /**
     * Checks whether this specification is an intersection of another specification.
     *
     * Verifies semantic equivalence with an intersection (logical AND) of specifications.
     *
     * @param ISpecification<T> $otherSpecification Candidate specification to compare
     * @return bool True if this specification is an intersection of the other
     * @throws \InvalidArgumentException If parameter is null
     */
    public function isIntersectionOf(ISpecification $otherSpecification): bool;

    /**
     * Checks whether this specification has a non-empty intersection with another specification.
     *
     * Opposite of isDisjointWith(). True if at least one candidate can satisfy both specifications.
     *
     * @param ISpecification<mixed> $otherSpecification Candidate specification to compare
     * @return bool True if specifications intersect
     * @throws \InvalidArgumentException If parameter is null
     */
    public function intersectsWith(ISpecification $otherSpecification): bool;

    /**
     * Evaluates candidate returning a rich result object (Notification Pattern).
     *
     * Unlike isSatisfiedBy() which returns a boolean, evaluate() provides detailed error traceability,
     * reasons, failure codes, and execution metadata.
     *
     * An exception raised while evaluating (missing property, throwing accessor, incompatible candidate
     * type) is NOT a rule failure: evaluate() returns a result with isError = true carrying the exception.
     * Negation never inverts an error result and composites propagate it; isSatisfiedBy() keeps throwing.
     *
     * @param mixed $candidate Candidate object or value to evaluate
     * @return SpecificationResult Evaluation result containing verdicts and failure notifications
     */
    public function evaluate(mixed $candidate): SpecificationResult;

    /**
     * Enriches this specification with a human-readable explanation of why it failed.
     *
     * @param string $reason Failure explanation or business requirement context
     * @return static Enriched specification instance
     */
    public function because(string $reason): static;

    /**
     * Enriches this specification with an error code (business or regulatory identifier).
     *
     * @param string $code Error or rule identifier (e.g. 'RULE_102', 'CREDIT_LIMIT_EXCEEDED')
     * @return static Enriched specification instance
     */
    public function withCode(string $code): static;

    /**
     * Creates a negated conjunction (logical AND NOT) with another specification.
     *
     * Fluent shortcut equivalent to $this->and($otherSpecification->not()).
     *
     * @param ISpecification<T>|string $otherSpecification Specification to negate or property name
     * @param ISpecification<mixed>|null $propertySpecification Property specification (when first argument is string)
     * @return ICompositeSpecification<T> New composite specification: this AND NOT other
     */
    public function andNot(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification;

    /**
     * Creates a negated disjunction (logical OR NOT) with another specification.
     *
     * Fluent shortcut equivalent to $this->or($otherSpecification->not()).
     *
     * @param ISpecification<T>|string $otherSpecification Specification to negate or property name
     * @param ISpecification<mixed>|null $propertySpecification Property specification (when first argument is string)
     * @return ICompositeSpecification<T> New composite specification: this OR NOT other
     */
    public function orNot(ISpecification|string $otherSpecification, ?ISpecification $propertySpecification = null): ICompositeSpecification;

    /**
     * Verifies whether another object denotes the same predicate as this specification.
     *
     * Equality is structural: same concrete class AND same parameters. Two
     * specifications of the same class with different parameters are NOT equal.
     * Partitioned repositories rely on this method to decide whether a new partition
     * replaces an existing one (RN-02 (a) of the partitioning architecture), so an
     * implementation that answers true for different predicates orphans entities.
     *
     * @param mixed $other Object to compare against (non-specifications are never equal)
     * @return bool True when both denote the same predicate
     */
    public function equals(mixed $other): bool;
}
