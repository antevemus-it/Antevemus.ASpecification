<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Attributes;

use Antevemus\ASpecification\Attributes\Exceptions\AttributeValidationException;
use Antevemus\ASpecification\Attributes\Exceptions\UnknownRuleOperatorException;
use Antevemus\ASpecification\Attributes\Exceptions\UnknownSpecificationClassException;
use Antevemus\ASpecification\Contracts\ISpecification;
use Antevemus\ASpecification\Results\SpecificationFailure;
use Antevemus\ASpecification\Results\SpecificationResult;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * AttributeValidator - High-performance PHP 8.4 Reflection engine for declarative specifications
 *
 * Scans classes, properties, and parameterless methods for #[AssertSpec] and #[ValidateRule]
 * attributes, evaluating target values against Evans & Fowler specifications and inline constraints.
 *
 * Features:
 * - Full PHP 8.4 Reflection inspection across public, protected, and private members
 * - Automatic instantiation and caching of parameterless ISpecification instances; a class that
 *   does not exist or does not implement ISpecification raises UnknownSpecificationClassException
 *   (configuration error) instead of switching the invariant off in silence
 * - Evaluation of class-level aggregate specifications and field-level property specifications
 *   through evaluate(): an exception thrown by a specification is an evaluation error of the
 *   Notification Pattern (isError, exception) aggregated into the result, never a raw exception
 * - An annotated getter that throws when invoked is the same kind of evaluation error (the value
 *   could not be produced): error failure with the exception message, the attribute code and the
 *   method name; the remaining attributes are still evaluated
 * - Inline operator evaluation (relational, range, regex, email, nullability) over the closed
 *   catalog ValidateRule::OPERATORS; an unknown operator raises UnknownRuleOperatorException
 *   (configuration error) instead of being reported as a violation of the candidate
 * - Result aggregation into rich, notification-pattern SpecificationResult instances
 *
 * @version    1.4.1
 * @package    Antevemus\ASpecification
 * @subpackage Attributes
 * @author     Heliton Junior (CTO) - <contato@antevemus.com.br>
 * @copyright  Copyright (c) 2025-2026 Antevemus Soluções Inovadoras em TI Ltda.
 * @license    MIT
 */
final class AttributeValidator
{
    /**
     * @var array<string, ISpecification> Cache of parameterless specification instances
     */
    private static array $instanceCache = [];

    /**
     * Validate an object using declarative attributes and return a comprehensive result.
     *
     * @param object $target The target object (DTO, Entity, Form Request, Value Object)
     * @return SpecificationResult The evaluation verdict containing all failure diagnostics; isError
     *         and exception are set when a #[AssertSpec] specification threw while being evaluated or
     *         when an annotated getter threw while being invoked (#[AssertSpec] or #[ValidateRule])
     * @throws UnknownRuleOperatorException When a #[ValidateRule] names an operator outside
     *         ValidateRule::OPERATORS (raised while instantiating the attribute; never a failure)
     * @throws UnknownSpecificationClassException When a #[AssertSpec] names a class that does not
     *         exist or does not implement ISpecification (configuration error; never a failure)
     */
    public static function validate(object $target): SpecificationResult
    {
        $refClass = new ReflectionClass($target);
        $failures = [];
        /** @var list<\Throwable> $errors Exceptions raised while evaluating #[AssertSpec] specifications */
        $errors = [];

        // 1. Class-Level Specifications (Aggregate Validation)
        foreach ($refClass->getAttributes(AssertSpec::class) as $attrRef) {
            /** @var AssertSpec $attr */
            $attr = $attrRef->newInstance();
            $spec = self::resolveSpecification($attr->specificationClass, $attr->arguments, sprintf('class %s', $refClass->getName()));

            $result = $spec->evaluate($target);
            if (!$result->isSatisfied) {
                $failures[] = self::assertSpecFailure(
                    $attr,
                    $result,
                    sprintf('Class %s failed specification %s', $refClass->getShortName(), $attr->specificationClass),
                    null,
                    ['target' => get_class($target), 'severity' => $attr->severity]
                );
                self::collectError($result, $errors);
            }
        }

        // 2. Property-Level Specifications & Inline Rules
        foreach ($refClass->getProperties() as $property) {
            $propName = $property->getName();
            $value = $property->isInitialized($target) ? $property->getValue($target) : null;

            // #[AssertSpec] on properties
            foreach ($property->getAttributes(AssertSpec::class) as $attrRef) {
                /** @var AssertSpec $attr */
                $attr = $attrRef->newInstance();
                $spec = self::resolveSpecification($attr->specificationClass, $attr->arguments, sprintf("property '%s' of %s", $propName, $refClass->getName()));

                $result = $spec->evaluate($value);
                if (!$result->isSatisfied) {
                    $failures[] = self::assertSpecFailure(
                        $attr,
                        $result,
                        sprintf("Field '%s' does not satisfy specification %s", $propName, $attr->specificationClass),
                        $propName,
                        ['actual' => $value, 'severity' => $attr->severity]
                    );
                    self::collectError($result, $errors);
                }
            }

            // #[ValidateRule] on properties
            foreach ($property->getAttributes(ValidateRule::class) as $attrRef) {
                /** @var ValidateRule $rule */
                $rule = $attrRef->newInstance();

                if (!self::evaluateRule($value, $rule->operator, $rule->expected)) {
                    $failures[] = new SpecificationFailure(
                        message: $rule->message ?? sprintf("Field '%s' failed validation rule '%s'", $propName, $rule->operator),
                        code: $rule->code,
                        ruleName: sprintf('ValidateRule(%s)', $rule->operator),
                        property: $propName,
                        metadata: ['actual' => $value, 'expected' => $rule->expected, 'severity' => $rule->severity]
                    );
                }
            }
        }

        // 3. Method-Level Specifications & Rules (Getters / Computed Values)
        foreach ($refClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getNumberOfRequiredParameters() > 0 || $method->isStatic()) {
                continue;
            }

            $methodName = $method->getName();

            // #[AssertSpec] on getters
            foreach ($method->getAttributes(AssertSpec::class) as $attrRef) {
                /** @var AssertSpec $attr */
                $attr = $attrRef->newInstance();
                $spec = self::resolveSpecification($attr->specificationClass, $attr->arguments, sprintf("method '%s()' of %s", $methodName, $refClass->getName()));

                try {
                    $value = $method->invoke($target);
                } catch (\Throwable $e) {
                    $failures[] = self::getterInvocationFailure($e, $attr->code, $attr->specificationClass, $methodName, $attr->severity);
                    $errors[] = $e;
                    continue;
                }

                $result = $spec->evaluate($value);
                if (!$result->isSatisfied) {
                    $failures[] = self::assertSpecFailure(
                        $attr,
                        $result,
                        sprintf("Method '%s()' does not satisfy specification %s", $methodName, $attr->specificationClass),
                        $methodName,
                        ['actual' => $value, 'severity' => $attr->severity]
                    );
                    self::collectError($result, $errors);
                }
            }

            // #[ValidateRule] on getters
            foreach ($method->getAttributes(ValidateRule::class) as $attrRef) {
                /** @var ValidateRule $rule */
                $rule = $attrRef->newInstance();
                $ruleName = sprintf('ValidateRule(%s)', $rule->operator);

                try {
                    $value = $method->invoke($target);
                } catch (\Throwable $e) {
                    $failures[] = self::getterInvocationFailure($e, $rule->code, $ruleName, $methodName, $rule->severity);
                    $errors[] = $e;
                    continue;
                }

                if (!self::evaluateRule($value, $rule->operator, $rule->expected)) {
                    $failures[] = new SpecificationFailure(
                        message: $rule->message ?? sprintf("Method '%s()' failed validation rule '%s'", $methodName, $rule->operator),
                        code: $rule->code,
                        ruleName: sprintf('ValidateRule(%s)', $rule->operator),
                        property: $methodName,
                        metadata: ['actual' => $value, 'expected' => $rule->expected, 'severity' => $rule->severity]
                    );
                }
            }
        }

        if (empty($failures)) {
            return SpecificationResult::satisfied();
        }

        // An evaluation error is never absorbed: the result carries isError and the first exception,
        // exactly as SpecificationResult::combine() does for composite specifications.
        return new SpecificationResult(false, $failures, $errors !== [], $errors[0] ?? null);
    }

    /**
     * Validate an object and throw AttributeValidationException on any violation.
     *
     * When the result is an evaluation error, the exception that aborted the evaluation is attached
     * as the previous exception and the result keeps isError.
     *
     * @param object $target
     * @throws AttributeValidationException On any violation of the candidate, or when a specification
     *         could not be evaluated (result with isError; cause available through getPrevious())
     * @throws UnknownRuleOperatorException On a #[ValidateRule] with an operator outside the catalog
     * @throws UnknownSpecificationClassException On a #[AssertSpec] naming a class that is not a specification
     */
    public static function assert(object $target): void
    {
        $result = self::validate($target);
        if (!$result->isSatisfied) {
            throw new AttributeValidationException($result);
        }
    }

    /**
     * Build the failure recorded for an unsatisfied #[AssertSpec] evaluation.
     *
     * A plain violation carries the attribute's business message (or the default one). An evaluation
     * error carries the exception message instead, because the business message would claim a
     * verdict that was never reached; the attribute code, rule name and target are kept, and the
     * exception class is recorded under metadata['evaluation_error'], as SpecificationResult::error() does.
     *
     * @param AssertSpec $attr The attribute being evaluated
     * @param SpecificationResult $result The unsatisfied evaluation result
     * @param string $defaultMessage Message used when the attribute has none
     * @param string|null $property Annotated property or method name (null at class level)
     * @param array<string, mixed> $metadata Diagnostic metadata of the target
     * @return SpecificationFailure
     */
    private static function assertSpecFailure(
        AssertSpec $attr,
        SpecificationResult $result,
        string $defaultMessage,
        ?string $property,
        array $metadata
    ): SpecificationFailure {
        if ($result->isError) {
            $exception = $result->exception;
            $message = $exception !== null && $exception->getMessage() !== ''
                ? $exception->getMessage()
                : ($exception !== null ? get_class($exception) : 'Specification evaluation error');

            return new SpecificationFailure(
                message: $message,
                code: $attr->code,
                ruleName: $attr->specificationClass,
                property: $property,
                metadata: $metadata + ['evaluation_error' => $exception !== null ? get_class($exception) : null]
            );
        }

        return new SpecificationFailure(
            message: $attr->message ?? $defaultMessage,
            code: $attr->code,
            ruleName: $attr->specificationClass,
            property: $property,
            metadata: $metadata
        );
    }

    /**
     * Record the exception of an error result so the aggregated result keeps the error state.
     *
     * @param SpecificationResult $result Evaluation result of one #[AssertSpec]
     * @param list<\Throwable> $errors Accumulator (by reference)
     */
    private static function collectError(SpecificationResult $result, array &$errors): void
    {
        if ($result->isError && $result->exception !== null) {
            $errors[] = $result->exception;
        }
    }

    /**
     * Build the error failure recorded when an annotated getter throws while being invoked.
     *
     * The value could not be produced, so no verdict was reached: the failure carries the exception
     * message (or its class when the message is empty), the attribute code, the rule name
     * (specification class or ValidateRule(<operator>)), the method name and
     * metadata['evaluation_error'], as an evaluation error does (BUG-20261007-MXUG).
     *
     * @param \Throwable $exception Exception thrown by the getter
     * @param string|null $code Attribute code
     * @param string $ruleName Rule name reported on the failure
     * @param string $methodName Annotated getter
     * @param string $severity Attribute severity
     * @return SpecificationFailure
     */
    private static function getterInvocationFailure(
        \Throwable $exception,
        ?string $code,
        string $ruleName,
        string $methodName,
        string $severity
    ): SpecificationFailure {
        return new SpecificationFailure(
            message: $exception->getMessage() !== '' ? $exception->getMessage() : get_class($exception),
            code: $code,
            ruleName: $ruleName,
            property: $methodName,
            metadata: ['severity' => $severity, 'evaluation_error' => get_class($exception)]
        );
    }

    /**
     * Clear the internal specification instance cache.
     */
    public static function clearCache(): void
    {
        self::$instanceCache = [];
    }

    /**
     * Resolve and optionally cache an ISpecification instance.
     *
     * A class that does not exist or does not implement ISpecification is a configuration error of
     * the attribute: it raises UnknownSpecificationClassException and is never skipped (skipping it
     * would switch the declared invariant off in silence and report the candidate as valid).
     *
     * @param class-string $className Specification class named on the attribute
     * @param array<mixed> $arguments Constructor arguments (a parameterized specification is never cached)
     * @param string $target Annotated element, for the exception message (e.g. "property 'age' of App\Dto")
     * @return ISpecification
     * @throws UnknownSpecificationClassException When the class does not exist or is not a specification
     */
    private static function resolveSpecification(string $className, array $arguments, string $target): ISpecification
    {
        if (!class_exists($className)) {
            throw new UnknownSpecificationClassException($className, $target, 'class does not exist');
        }

        if (!is_subclass_of($className, ISpecification::class)) {
            throw new UnknownSpecificationClassException($className, $target, 'class does not implement ' . ISpecification::class);
        }

        if (empty($arguments)) {
            if (!isset(self::$instanceCache[$className])) {
                /** @var ISpecification $instance */
                $instance = new $className();
                self::$instanceCache[$className] = $instance;
            }
            return self::$instanceCache[$className];
        }

        /** @var ISpecification $instance */
        $instance = new $className(...$arguments);
        return $instance;
    }

    /**
     * Evaluate an inline validation rule operator against a value.
     *
     * @param mixed $value Actual candidate value
     * @param string $operator Validation operator
     * @param mixed $expected Expected value, threshold, or pattern
     * @return bool
     * @throws UnknownRuleOperatorException When the operator is not in ValidateRule::OPERATORS
     *         (defense in depth: ValidateRule already refuses it at construction)
     */
    private static function evaluateRule(mixed $value, string $operator, mixed $expected): bool
    {
        $normalizedOp = ValidateRule::normalizeOperator($operator);

        return match ($normalizedOp) {
            '=', '==' => $value == $expected,
            '===' => $value === $expected,
            '!=', '<>' => $value != $expected,
            '!==' => $value !== $expected,
            '>' => is_numeric($value) && is_numeric($expected) && $value > $expected,
            '>=' => is_numeric($value) && is_numeric($expected) && $value >= $expected,
            '<' => is_numeric($value) && is_numeric($expected) && $value < $expected,
            '<=' => is_numeric($value) && is_numeric($expected) && $value <= $expected,
            'between' => is_array($expected) && count($expected) >= 2 && $value >= $expected[0] && $value <= $expected[1],
            'in' => is_array($expected) && in_array($value, $expected, true),
            'notin', 'not_in' => is_array($expected) && !in_array($value, $expected, true),
            'regex' => is_string($expected) && is_scalar($value) && (bool) preg_match($expected, (string) $value),
            'notnull', 'not_null' => $value !== null,
            'null' => $value === null,
            'notempty', 'not_empty' => !empty($value),
            'empty' => empty($value),
            // not_blank: a string must have visible content; an array must have elements; any other
            // value counts as present unless it is null or false (0 and 0.0 are present, unlike 'not_empty').
            'not_blank', 'notblank' => match (true) {
                is_string($value) => trim($value) !== '',
                is_array($value) => $value !== [],
                default => $value !== null && $value !== false,
            },
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            default => throw new UnknownRuleOperatorException($operator, ValidateRule::OPERATORS),
        };
    }
}
