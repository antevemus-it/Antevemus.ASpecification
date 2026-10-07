<?php

declare(strict_types=1);

namespace Antevemus\ASpecification\Attributes;

use Antevemus\ASpecification\Attributes\Exceptions\AttributeValidationException;
use Antevemus\ASpecification\Attributes\Exceptions\UnknownRuleOperatorException;
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
 * - Automatic instantiation and caching of parameterless ISpecification instances
 * - Evaluation of class-level aggregate specifications and field-level property specifications
 * - Inline operator evaluation (relational, range, regex, email, nullability) over the closed
 *   catalog ValidateRule::OPERATORS; an unknown operator raises UnknownRuleOperatorException
 *   (configuration error) instead of being reported as a violation of the candidate
 * - Result aggregation into rich, notification-pattern SpecificationResult instances
 *
 * @version    1.1.0
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
     * @return SpecificationResult The evaluation verdict containing all failure diagnostics
     * @throws UnknownRuleOperatorException When a #[ValidateRule] names an operator outside
     *         ValidateRule::OPERATORS (raised while instantiating the attribute; never a failure)
     */
    public static function validate(object $target): SpecificationResult
    {
        $refClass = new ReflectionClass($target);
        $failures = [];

        // 1. Class-Level Specifications (Aggregate Validation)
        foreach ($refClass->getAttributes(AssertSpec::class) as $attrRef) {
            /** @var AssertSpec $attr */
            $attr = $attrRef->newInstance();
            $spec = self::resolveSpecification($attr->specificationClass, $attr->arguments);

            if ($spec !== null && !$spec->isSatisfiedBy($target)) {
                $failures[] = new SpecificationFailure(
                    message: $attr->message ?? sprintf('Class %s failed specification %s', $refClass->getShortName(), $attr->specificationClass),
                    code: $attr->code,
                    ruleName: $attr->specificationClass,
                    property: null,
                    metadata: ['target' => get_class($target), 'severity' => $attr->severity]
                );
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
                $spec = self::resolveSpecification($attr->specificationClass, $attr->arguments);

                if ($spec !== null && !$spec->isSatisfiedBy($value)) {
                    $failures[] = new SpecificationFailure(
                        message: $attr->message ?? sprintf("Field '%s' does not satisfy specification %s", $propName, $attr->specificationClass),
                        code: $attr->code,
                        ruleName: $attr->specificationClass,
                        property: $propName,
                        metadata: ['actual' => $value, 'severity' => $attr->severity]
                    );
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
                $spec = self::resolveSpecification($attr->specificationClass, $attr->arguments);
                $value = $method->invoke($target);

                if ($spec !== null && !$spec->isSatisfiedBy($value)) {
                    $failures[] = new SpecificationFailure(
                        message: $attr->message ?? sprintf("Method '%s()' does not satisfy specification %s", $methodName, $attr->specificationClass),
                        code: $attr->code,
                        ruleName: $attr->specificationClass,
                        property: $methodName,
                        metadata: ['actual' => $value, 'severity' => $attr->severity]
                    );
                }
            }

            // #[ValidateRule] on getters
            foreach ($method->getAttributes(ValidateRule::class) as $attrRef) {
                /** @var ValidateRule $rule */
                $rule = $attrRef->newInstance();
                $value = $method->invoke($target);

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

        return new SpecificationResult(false, $failures);
    }

    /**
     * Validate an object and throw AttributeValidationException on any violation.
     *
     * @param object $target
     * @throws AttributeValidationException On any violation of the candidate
     * @throws UnknownRuleOperatorException On a #[ValidateRule] with an operator outside the catalog
     */
    public static function assert(object $target): void
    {
        $result = self::validate($target);
        if (!$result->isSatisfied) {
            throw new AttributeValidationException($result);
        }
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
     * @param class-string $className
     * @param array<mixed> $arguments
     * @return ISpecification|null
     */
    private static function resolveSpecification(string $className, array $arguments = []): ?ISpecification
    {
        if (!class_exists($className)) {
            return null;
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
