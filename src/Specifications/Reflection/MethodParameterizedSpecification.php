<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Specifications\Reflection;

use Antevemus\ASpecification\AbstractSpecification;
use Antevemus\ASpecification\Contracts\ILeafSpecification;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Results\SpecificationResult;
use Antevemus\ASpecification\Specifications\SpecificationAlgebra;
use Antevemus\ASpecification\Specifications\SubsumptionAndEqualityTrait;
use BackedEnum;
use DateTime;
use DateTimeInterface;

/**
 * MethodParameterizedSpecification - Declarative method call on the candidate.
 *
 * Calls a public method of the candidate with a fixed list of arguments and applies another
 * specification to the returned value: "call isEligibleFor(2026-12-01) and require true". It is
 * the method half of the Domian `FieldParameterizedSpecification`/`MethodParameterizedSpecification`
 * pair (Copyright 2006-2010 the original author or authors, Apache License 2.0; see
 * THIRD_PARTY_NOTICES.md); the field half is `where()` with the PropertyAccessor, so no field
 * specification exists (forward 018, decision D8).
 *
 * Unlike `must(closure)` the call is declarative: the method name and the arguments are data, so the
 * specification has structural equality, takes part in the algebra and can be described by a catalog.
 *
 * Arguments (RN-02): a positional list of values representable without code: scalars, null, arrays of
 * those, BackedEnum and DateTimeInterface. A closure, any other object or a resource is rejected with
 * InvalidArgumentException at construction. A mutable DateTime is copied on construction and on every
 * call, so neither the caller nor the called method can change the specification.
 *
 * Evaluation (RN-03), the same path where() uses for a property value:
 * - a null or non-object candidate does not satisfy;
 * - only PUBLIC methods are called (forward 018 §5): a missing or non-public method raises
 *   BadMethodCallException from isSatisfiedBy() and becomes an error result under evaluate()
 *   (never a rule failure, never inverted by NOT), as where() does with a missing property and
 *   must() with a throwing closure; any exception raised by the method behaves the same way;
 * - a null returned value does not satisfy (a null property does not satisfy where() either);
 * - any other returned value, scalars included, is evaluated by the result specification.
 * Side effects of the method are the caller's responsibility; the result is never cached.
 *
 * Identity and algebra (RN-04, RN-05):
 * - equals(): same method, arguments equal position by position (=== for scalars and enum cases,
 *   date-times by instant, arrays element by element) and equal result specifications;
 * - with another method call of the same method and arguments, isGeneralizationOf()/isDisjointWith()
 *   delegate to the result specifications; any other pair is decided by the shared axioms only
 *   (conservative: "not proven");
 * - isContradiction() is inherited from the result specification; isTautology() never is
 *   (a missing method or a null result does not satisfy; forward 019 RN-08).
 *
 * Translation (RN-07): opaque to SQL and TCriteria (their "non translatable" exception); the ALinq
 * visitor compiles it to the method call on the item.
 *
 * @template T
 * @extends AbstractSpecification<T>
 * @implements ILeafSpecification<T>
 * @version    1.6.0
 * @package    Antevemus\ASpecification
 * @subpackage Specifications\Reflection
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
class MethodParameterizedSpecification extends AbstractSpecification implements ILeafSpecification
{
    use SubsumptionAndEqualityTrait;

    /**
     * Declarative arguments, with mutable date-times copied.
     *
     * @var list<mixed>
     */
    private readonly array $arguments;

    /**
     * @param string $methodName Public method of the candidate to call
     * @param list<mixed> $arguments Positional, declarative arguments (scalars, null, arrays of those,
     *                               BackedEnum, DateTimeInterface)
     * @param ISpecification<mixed> $resultSpecification Specification applied to the returned value
     * @param string $type Declared candidate type (defaults to `mixed`; whereMethod() passes the type of
     *                     the specification it extends)
     * @throws \InvalidArgumentException If the method name is not a valid identifier, the arguments are
     *                                   not a positional list or an argument is not declarative
     */
    public function __construct(
        private readonly string $methodName,
        array $arguments,
        private readonly ISpecification $resultSpecification,
        private readonly string $type = 'mixed'
    ) {
        if (preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/', $methodName) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid method name "%s" for a method-call specification: a PHP identifier is required.',
                $methodName
            ));
        }
        if (!array_is_list($arguments)) {
            throw new \InvalidArgumentException(sprintf(
                'The arguments of the method-call specification "%s(...)" must be a positional list.',
                $methodName
            ));
        }
        foreach ($arguments as $index => $argument) {
            self::assertDeclarative($argument, $methodName, $index);
        }

        $this->arguments = self::copyArguments($arguments);
    }

    /**
     * Returns the name of the called method.
     */
    public function getMethodName(): string
    {
        return $this->methodName;
    }

    /**
     * Returns the declarative arguments of the call (mutable date-times are returned as copies).
     *
     * @return list<mixed>
     */
    public function getArguments(): array
    {
        return self::copyArguments($this->arguments);
    }

    /**
     * Returns the specification applied to the returned value.
     *
     * @return ISpecification<mixed>
     */
    public function getResultSpecification(): ISpecification
    {
        return $this->resultSpecification;
    }

    /**
     * Default rule name of this specification in failures and error results: "method(...)".
     */
    public function getRuleName(): string
    {
        return $this->methodName . '(...)';
    }

    /**
     * Calls the method on the candidate with the declared arguments and returns what it returns.
     *
     * Shared by isSatisfiedBy(), evaluate() and the ALinq visitor so that the three resolve and call
     * the method in exactly the same way.
     *
     * @param object $candidate
     * @return mixed The value returned by the method
     * @throws \BadMethodCallException When the method does not exist or is not public
     * @throws \Throwable Whatever the method itself raises
     */
    public function callOn(object $candidate): mixed
    {
        if (!method_exists($candidate, $this->methodName)) {
            throw new \BadMethodCallException(sprintf(
                'Method %s::%s() does not exist (method-call specification).',
                $candidate::class,
                $this->methodName
            ));
        }
        $method = new \ReflectionMethod($candidate, $this->methodName);
        if (!$method->isPublic()) {
            throw new \BadMethodCallException(sprintf(
                'Method %s::%s() is not public; a method-call specification only calls public methods.',
                $candidate::class,
                $this->methodName
            ));
        }

        return $candidate->{$this->methodName}(...self::copyArguments($this->arguments));
    }

    /**
     * {@inheritdoc}
     *
     * @throws \BadMethodCallException When the method does not exist or is not public
     */
    public function isSatisfiedBy(mixed $candidate): bool
    {
        if (!is_object($candidate)) {
            return false;
        }

        $result = $this->callOn($candidate);
        if ($result === null) {
            return false;
        }

        return $this->resultSpecification->isSatisfiedBy($result);
    }

    /**
     * {@inheritdoc}
     *
     * A failure is reported as ONE failure of this rule ("method(...)"), the result specification's
     * failures kept as diagnostic causes; an error of the call or of the result specification is an
     * error result, never absorbed by because()/withCode().
     */
    public function evaluate(mixed $candidate): SpecificationResult
    {
        $ruleName = $this->getRuleName();

        if (!is_object($candidate)) {
            return SpecificationResult::failure(
                message: $this->customReason ?? sprintf("Invalid candidate for method call '%s'.", $ruleName),
                code: $this->customCode,
                ruleName: $ruleName
            );
        }

        try {
            $result = $this->callOn($candidate);
        } catch (\Throwable $e) {
            return SpecificationResult::error(exception: $e, ruleName: $ruleName, code: $this->customCode);
        }

        if ($result === null) {
            return SpecificationResult::failure(
                message: $this->customReason ?? sprintf("Method '%s' returned null on candidate object.", $ruleName),
                code: $this->customCode,
                ruleName: $ruleName
            );
        }

        $resultEvaluation = $this->resultSpecification->evaluate($result);
        if ($resultEvaluation->isSatisfied) {
            return SpecificationResult::satisfied();
        }
        if ($resultEvaluation->isError) {
            return $resultEvaluation;
        }

        return SpecificationResult::failure(
            message: $this->customReason ?? sprintf("The value returned by '%s' did not satisfy the expected specification.", $ruleName),
            code: $this->customCode,
            ruleName: $ruleName,
            metadata: ['method' => $this->methodName, 'causes' => $resultEvaluation->failures]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * {@inheritdoc}
     *
     * Same method, same arguments (position by position) and equal result specifications. The
     * declared candidate type is not part of the identity: it does not change what is accepted.
     */
    public function equals(mixed $other): bool
    {
        if ($this === $other) {
            return true;
        }
        if (!$other instanceof self || $other::class !== static::class) {
            return false;
        }

        return $this->methodName === $other->methodName
            && $this->customReason === $other->customReason
            && $this->customCode === $other->customCode
            && self::argumentListsEqual($this->arguments, $other->arguments)
            && $this->resultSpecification->equals($other->resultSpecification);
    }

    /**
     * True when the other specification calls the same method with the same arguments.
     */
    public function callsSameMethodAs(self $other): bool
    {
        return $this->methodName === $other->methodName
            && self::argumentListsEqual($this->arguments, $other->arguments);
    }

    /**
     * {@inheritdoc}
     *
     * RN-05: same method and arguments → the result specifications decide; otherwise only the
     * shared axioms (reflexivity, A ⊇ ∅, disjunctions, conjunctions, sets).
     */
    public function isGeneralizationOf(ISpecification $otherSpecification): bool
    {
        if ($this->checkBaseGeneralization($otherSpecification)) {
            return true;
        }

        $other = SpecificationAlgebra::resolve($otherSpecification);
        if ($other instanceof self && $this->callsSameMethodAs($other)) {
            return $this->resultSpecification->isGeneralizationOf($other->resultSpecification);
        }

        return false;
    }

    /**
     * {@inheritdoc}
     *
     * RN-05: same method and arguments → the result specifications decide; otherwise only the
     * shared axioms.
     */
    public function isDisjointWith(ISpecification $otherSpecification): bool
    {
        if ($this->checkBaseDisjointness($otherSpecification)) {
            return true;
        }

        $other = SpecificationAlgebra::resolve($otherSpecification);
        if ($other instanceof self && $this->callsSameMethodAs($other)) {
            return $this->resultSpecification->isDisjointWith($other->resultSpecification);
        }

        return false;
    }

    /**
     * {@inheritdoc}
     *
     * Forward 019 RN-08: a method call whose result specification is a contradiction is one.
     */
    public function isContradiction(): bool
    {
        return $this->resultSpecification->isContradiction();
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        $parts = explode('\\', $this->resultSpecification::class);

        return sprintf('(CALL %s: %s)', $this->getRuleName(), end($parts));
    }

    // ==========================================
    // Declarative arguments
    // ==========================================

    /**
     * Rejects any argument that is not representable without code (RN-02).
     *
     * @throws \InvalidArgumentException
     */
    private static function assertDeclarative(mixed $value, string $methodName, int $index): void
    {
        if ($value === null || is_scalar($value) || $value instanceof BackedEnum || $value instanceof DateTimeInterface) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertDeclarative($item, $methodName, $index);
            }
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'Argument #%d of the method-call specification "%s(...)" is not declarative (%s): only scalars, '
            . 'null, arrays of those, BackedEnum and DateTimeInterface are accepted.',
            $index + 1,
            $methodName,
            get_debug_type($value)
        ));
    }

    /**
     * Copies the mutable date-times of an argument list (recursively), everything else as is.
     *
     * @param array<mixed> $arguments
     * @return array<mixed>
     */
    private static function copyArguments(array $arguments): array
    {
        foreach ($arguments as $key => $argument) {
            if ($argument instanceof DateTime) {
                $arguments[$key] = clone $argument;
            } elseif (is_array($argument)) {
                $arguments[$key] = self::copyArguments($argument);
            }
        }

        return $arguments;
    }

    /**
     * Position-by-position equality of two argument lists (RN-04): date-times by instant, arrays
     * element by element with the same keys in the same order, everything else by identity.
     *
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    private static function argumentListsEqual(array $a, array $b): bool
    {
        if (array_keys($a) !== array_keys($b)) {
            return false;
        }
        foreach ($a as $key => $value) {
            if (!self::argumentsEqual($value, $b[$key])) {
                return false;
            }
        }

        return true;
    }

    private static function argumentsEqual(mixed $a, mixed $b): bool
    {
        if ($a instanceof DateTimeInterface || $b instanceof DateTimeInterface) {
            return $a instanceof DateTimeInterface && $b instanceof DateTimeInterface && $a == $b;
        }
        if (is_array($a) || is_array($b)) {
            return is_array($a) && is_array($b) && self::argumentListsEqual($a, $b);
        }

        return $a === $b;
    }
}
